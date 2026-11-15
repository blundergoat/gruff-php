<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Reports recognized literal-on `ini_set()` calls for `display_errors` and `display_startup_errors`.
 * Emits one warning per matching call unless a recognized SAPI or bare-constant branch guard suppresses it.
 * Constants not declared in the file are treated as configuration switches without resolving their runtime values.
 */
final class DebugModeEnabledRule implements RuleInterface
{
    /** Stable rule identifier for debug-mode findings. */
    public const ID = 'security.debug-mode-enabled';

    /**
     * `ini_set` directives that put PHP errors into the page a visitor receives.
     *
     * @var list<string> - Error-display directive names recognized by this rule.
     */
    private const DISPLAY_DIRECTIVES = ['display_errors', 'display_startup_errors'];

    /**
     * SAPI names accepted as command-line indicators by the guard matcher.
     *
     * @var list<string> - Names allowed in the literal SAPI membership list.
     */
    private const COMMAND_LINE_SAPIS = ['cli', 'phpdbg', 'embed'];

    /**
     * Describes the rule for `list-rules` and for each finding it reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning-level because forced error display leaks file paths and internals to anyone who triggers an error.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Debug error display enabled',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            // Off unless a project sets `enabled: true`: precision-floor M02 left 3 findings across the corpus after its repair, fewer than the ten a
            // precision floor needs, so it stays opt-in until more evidence exists (ADR-034).
            isEnabledByDefault: false,
            description:     'Forces PHP error display on via ini_set(display_errors, ...), leaking diagnostics in production.',
            falsePositiveShapes: [
                [
                    'shape'      => 'Dev/test bootstrap intentionally enables error display.',
                    'mitigation' => 'Gate the toggle behind an environment check, or disable the rule for bootstrap paths.',
                ],
            ],
        );
    }

    /**
     * Reports recognized literal-on ini_set() toggles outside the supported branch guards.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per matching toggle; empty when no call passes the literal and guard checks.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {

            if (SecurityNodeHelper::globalFunctionName($call) !== 'ini_set') {
                continue;
            }

            $directive = SecurityNodeHelper::sinkArgumentValue($call, 0);
            // Settings such as memory_limit, or a directive name the code computes, are not this rule's concern.
            if (!$directive instanceof Scalar\String_ || !in_array(strtolower($directive->value), self::DISPLAY_DIRECTIVES, true)) {
                continue;
            }

            $displaySetting = SecurityNodeHelper::sinkArgumentValue($call, 1);

            if ($displaySetting === null || !$this->turnsDisplayOn($displaySetting)) {
                continue;
            }


            if ($this->isHiddenFromWebVisitors($call, $analysisUnit)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf("ini_set('%s', ...) forces PHP error display on; this leaks diagnostics to users in production.", strtolower($directive->value)),
                filePath:    $analysisUnit->file->displayPath,
                line:        $call->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Leave display_errors off in production and log errors instead; gate any error-display toggle behind an explicit non-production environment check.',
                metadata:    [
                    'directive' => strtolower($directive->value),
                ],
            );
        }

        return $findings;
    }

    /**
     * Checks enclosing branch bodies for recognized SAPI or configuration guards.
     * Stops at the nearest function-like scope; an outer guard can also cover a call in a nested condition.
     *
     * @param Expr\FuncCall $call - The ini_set() call that turns display on.
     * @param AnalysisUnit $analysisUnit - File searched for its own definition of a guarding constant.
     *
     * @return bool - True when an enclosing branch matches the SAPI or external-constant guard heuristic.
     */
    private function isHiddenFromWebVisitors(Expr\FuncCall $call, AnalysisUnit $analysisUnit): bool
    {
        $owningFunction = SecurityNodeHelper::enclosingFunctionLike($call);
        $current        = $call;
        $parent         = $call->getAttribute('parent');

        // Stop at the owning function: a guard in the caller does not prove when a closure or method runs.
        while ($parent instanceof Node && $parent !== $owningFunction) {
            $inBranchBody = ($parent instanceof Stmt\If_ || $parent instanceof Stmt\ElseIf_ || $parent instanceof Stmt\Else_)
                && in_array($current, $parent->stmts, true);

            if ($inBranchBody && ($this->runsOnlyFromCommandLine($parent) || $this->runsOnlyWhenConfigEnabled($parent, $analysisUnit))) {
                return true;
            }

            $current = $parent;
            $parent  = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Reports whether a later branch of an if-chain runs only after an earlier test ruled out every web SAPI.
     *
     * @param Stmt\If_|Stmt\ElseIf_|Stmt\Else_ $branch - Branch whose body holds the toggle.
     *
     * @return bool - True when an earlier negated membership test limits PHP_SAPI to command-line values.
     */
    private function runsOnlyFromCommandLine(Stmt\If_|Stmt\ElseIf_|Stmt\Else_ $branch): bool
    {
        $ifChain = $branch->getAttribute('parent');
        // Direct SAPI checks on the first branch are not recognized here.
        if ($branch instanceof Stmt\If_ || !$ifChain instanceof Stmt\If_) {
            return false;
        }

        $earlierConditions = [$ifChain->cond];
        // Only conditions before this branch were tested when it runs; later elseif tests never decided anything.
        foreach ($ifChain->elseifs as $elseIf) {

            if ($elseIf === $branch) {
                break;
            }

            $earlierConditions[] = $elseIf->cond;
        }

        // Each earlier condition was false, so a failed "not a command-line SAPI" test means the script runs from the command line.
        foreach ($earlierConditions as $condition) {

            if ($condition instanceof Expr\BooleanNot && $this->testsForCommandLineSapi($condition->expr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recognizes in_array() calls comparing PHP_SAPI with a non-empty literal list of command-line SAPIs.
     *
     * @param Expr $condition - Operand of a negated if or elseif condition.
     *
     * @return bool - True for in_array(PHP_SAPI, ...) with a non-empty list of unkeyed, non-unpacked accepted SAPI strings; false otherwise.
     */
    private function testsForCommandLineSapi(Expr $condition): bool
    {

        if (!$condition instanceof Expr\FuncCall || SecurityNodeHelper::globalFunctionName($condition) !== 'in_array') {
            return false;
        }

        $sapiOperand = SecurityNodeHelper::sinkArgumentValue($condition, 0);
        $sapiList    = SecurityNodeHelper::sinkArgumentValue($condition, 1);
        // Without PHP_SAPI and a literal non-empty list, this heuristic cannot establish command-line-only execution.
        if ($sapiOperand === null || SecurityNodeHelper::constantName($sapiOperand) !== 'PHP_SAPI' || !$sapiList instanceof Expr\Array_ || $sapiList->items === []) {
            return false;
        }

        // Every listed SAPI must be a command-line one; a single web SAPI such as fpm-fcgi lets a web request through.
        foreach ($sapiList->items as $sapiEntry) {
            $isCommandLineSapi = $sapiEntry->key === null && !$sapiEntry->unpack && $sapiEntry->value instanceof Scalar\String_
                && in_array(strtolower($sapiEntry->value->value), self::COMMAND_LINE_SAPIS, true);

            if (!$isCommandLineSapi) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recognizes an if or elseif guard that uses a bare constant not declared in this file.
     *
     * @param Stmt\If_|Stmt\ElseIf_|Stmt\Else_ $branch - Branch whose body holds the toggle.
     * @param AnalysisUnit $analysisUnit - File searched for its own definition of the same constant.
     *
     * @return bool - True for a non-keyword constant condition with no matching declaration in the file.
     */
    private function runsOnlyWhenConfigEnabled(Stmt\If_|Stmt\ElseIf_|Stmt\Else_ $branch, AnalysisUnit $analysisUnit): bool
    {

        if ($branch instanceof Stmt\Else_) {
            return false;
        }

        $constantName = SecurityNodeHelper::constantName($branch->cond);
        // Negated and compound conditions are not plain switches, and a literal true condition is not an external switch.
        if ($constantName === null || in_array($constantName, ['TRUE', 'FALSE', 'NULL'], true)) {
            return false;
        }

        // A local declaration prevents this heuristic from treating the constant as an external switch.
        return !$this->fileSetsConstant($analysisUnit, $constantName);
    }

    /**
     * Searches the file for a matching literal define() call or const declaration, regardless of execution order.
     *
     * @param AnalysisUnit $analysisUnit - File to search.
     * @param string $constantName - Upper-cased constant name from the branch condition.
     *
     * @return bool - True when a matching declaration is found; false does not establish where the value comes from.
     */
    private function fileSetsConstant(AnalysisUnit $analysisUnit, string $constantName): bool
    {
        // Declaration order and reachability do not affect this syntactic check.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $definedName = SecurityNodeHelper::sinkArgumentValue($call, 0);

            if (SecurityNodeHelper::globalFunctionName($call) === 'define' && $definedName instanceof Scalar\String_ && strtoupper($definedName->value) === $constantName) {
                return true;
            }
        }


        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Const_::class) as $constStatement) {

            foreach ($constStatement->consts as $declaredConstant) {

                if (strtoupper($declaredConstant->name->toString()) === $constantName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reports whether a literal setting matches the rule's enabled-value list.
     *
     * @param Expr $displaySetting - Second argument to ini_set().
     *
     * @return bool - True for a recognized enabled literal; false for any other literal or computed value.
     */
    private function turnsDisplayOn(Expr $displaySetting): bool
    {

        if ($displaySetting instanceof Scalar\String_) {
            return in_array(strtolower($displaySetting->value), ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true);
        }


        if ($displaySetting instanceof Scalar\Int_) {
            return $displaySetting->value !== 0;
        }


        if ($displaySetting instanceof Expr\ConstFetch) {
            return strtolower($displaySetting->name->toString()) === 'true';
        }

        // A computed value such as `$debug` might be off, so the rule does not guess.
        return false;
    }
}
