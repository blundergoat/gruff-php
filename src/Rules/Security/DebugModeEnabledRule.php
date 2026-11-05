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
 * Reports `ini_set('display_errors', <on>)` and its startup-errors twin, which show PHP warnings and stack traces to site visitors.
 *
 * A user who left `ini_set('display_errors', 1);` in a controller gets one warning on that line.
 * Only literal on-values count; environment switches such as `APP_DEBUG` live in `.env` and are out of scope.
 *
 * A toggle a web visitor can never reach stays quiet:
 * - a branch reached only on the command line, after `!in_array(PHP_SAPI, ['cli', ...])`;
 * - the body of `if (SOME_CONSTANT)` when the constant is deployment configuration this file does not set itself.
 */
final class DebugModeEnabledRule implements RuleInterface
{
    /** Stable rule identifier for debug-mode findings. */
    public const ID = 'security.debug-mode-enabled';

    /**
     * `ini_set` directives that put PHP errors into the page a visitor receives.
     *
     * @var list<string>
     */
    private const DISPLAY_DIRECTIVES = ['display_errors', 'display_startup_errors'];

    /**
     * SAPIs that print to a terminal or an embedding program, never to a web visitor.
     *
     * @var list<string>
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
     * Reports each `ini_set()` call that forces error display on where a web visitor could see it.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per forced toggle; empty when the file never turns display on, or only for the CLI or an opt-in.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Every function call is a candidate, because the toggle is an ordinary ini_set() call anywhere in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            // Other functions, and a namespaced ini_set() the project defined itself, cannot change PHP's error display.
            if (SecurityNodeHelper::globalFunctionName($call) !== 'ini_set') {
                continue;
            }

            $directive = SecurityNodeHelper::sinkArgumentValue($call, 0);
            // Settings such as `memory_limit`, or a directive name the code computes, are not this rule's concern.
            if (!$directive instanceof Scalar\String_ || !in_array(strtolower($directive->value), self::DISPLAY_DIRECTIVES, true)) {
                continue;
            }

            $displaySetting = SecurityNodeHelper::sinkArgumentValue($call, 1);
            // Turning display off, such as `ini_set('display_errors', '0')`, is the safe production setting.
            if ($displaySetting === null || !$this->turnsDisplayOn($displaySetting)) {
                continue;
            }

            // A CLI-only branch or a deployment's opt-in constant keeps the errors away from web visitors.
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
     * Reports whether an enclosing branch already keeps the toggle away from web visitors.
     *
     * Climbs out to the owning function, method or closure; a call inside a condition rather than a branch body is never hidden.
     *
     * @param Expr\FuncCall $call - The ini_set() call that turns display on.
     * @param AnalysisUnit  $analysisUnit - File searched for its own definition of a guarding constant.
     *
     * @return bool - True when a branch body around the call runs only on the command line or only under an opt-in constant.
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
            // Only a branch body guards the call; a loop or a plain block around it decides nothing about who sees the errors.
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
     * @return bool - True when an earlier condition in the chain is `!in_array(PHP_SAPI, [command-line SAPIs])`.
     */
    private function runsOnlyFromCommandLine(Stmt\If_|Stmt\ElseIf_|Stmt\Else_ $branch): bool
    {
        $ifChain = $branch->getAttribute('parent');
        // The first branch has no earlier test, so a web request can still reach it.
        if ($branch instanceof Stmt\If_ || !$ifChain instanceof Stmt\If_) {
            return false;
        }

        $earlierConditions = [$ifChain->cond];
        // Only conditions before this branch were tested when it runs; later elseif tests never decided anything.
        foreach ($ifChain->elseifs as $elseIf) {
            // Reaching this branch's own elseif ends the list of tests that already failed.
            if ($elseIf === $branch) {
                break;
            }

            $earlierConditions[] = $elseIf->cond;
        }

        // Each earlier condition was false, so a failed "not a command-line SAPI" test means the script runs from the command line.
        foreach ($earlierConditions as $condition) {
            // Symfony-style bootstraps write `if (!\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true))`.
            if ($condition instanceof Expr\BooleanNot && $this->testsForCommandLineSapi($condition->expr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether an expression checks PHP_SAPI against a list made only of command-line SAPIs.
     *
     * @param Expr $condition - Operand of a negated if or elseif condition.
     *
     * @return bool - True for `in_array(PHP_SAPI, ['cli', ...])` when every entry is a command-line SAPI literal.
     */
    private function testsForCommandLineSapi(Expr $condition): bool
    {
        // Only an in_array() membership test is the SAPI guard seen in real bootstraps.
        if (!$condition instanceof Expr\FuncCall || SecurityNodeHelper::globalFunctionName($condition) !== 'in_array') {
            return false;
        }

        $sapiOperand = SecurityNodeHelper::sinkArgumentValue($condition, 0);
        $sapiList    = SecurityNodeHelper::sinkArgumentValue($condition, 1);
        // Without PHP_SAPI compared to a literal, non-empty list, the test says nothing about where the script runs.
        if ($sapiOperand === null || SecurityNodeHelper::constantName($sapiOperand) !== 'PHP_SAPI' || !$sapiList instanceof Expr\Array_ || $sapiList->items === []) {
            return false;
        }

        // Every listed SAPI must be a command-line one; a single web SAPI such as `fpm-fcgi` lets a web request through.
        foreach ($sapiList->items as $sapiEntry) {
            $isCommandLineSapi = $sapiEntry->key === null && !$sapiEntry->unpack && $sapiEntry->value instanceof Scalar\String_
                && in_array(strtolower($sapiEntry->value->value), self::COMMAND_LINE_SAPIS, true);
            // A keyed, spread or computed entry, or a web SAPI, means the branch is not command-line only.
            if (!$isCommandLineSapi) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reports whether a branch runs only when the deployment switches a configuration constant on, such as WordPress's `WP_DEBUG_DISPLAY`.
     *
     * @param Stmt\If_|Stmt\ElseIf_|Stmt\Else_ $branch - Branch whose body holds the toggle.
     * @param AnalysisUnit                      $analysisUnit - File searched for its own definition of the same constant.
     *
     * @return bool - True when the branch condition is a bare constant that this file does not set itself.
     */
    private function runsOnlyWhenConfigEnabled(Stmt\If_|Stmt\ElseIf_|Stmt\Else_ $branch, AnalysisUnit $analysisUnit): bool
    {
        // An else body runs when the constant is off, which is the opposite of an opt-in.
        if ($branch instanceof Stmt\Else_) {
            return false;
        }

        $constantName = SecurityNodeHelper::constantName($branch->cond);
        // A negated or compound condition is not a plain switch, and `if (true)` is no configuration at all.
        if ($constantName === null || in_array($constantName, ['TRUE', 'FALSE', 'NULL'], true)) {
            return false;
        }

        // A constant this file sets with define() or const is the file's own decision, so display is forced on regardless.
        return !$this->fileSetsConstant($analysisUnit, $constantName);
    }

    /**
     * Reports whether the file itself sets a constant, by define() or a const statement.
     *
     * @param AnalysisUnit $analysisUnit - File to search.
     * @param string       $constantName - Upper-cased constant name from the branch condition.
     *
     * @return bool - True when the file sets the constant; false means the value comes from the deployment's configuration.
     */
    private function fileSetsConstant(AnalysisUnit $analysisUnit, string $constantName): bool
    {
        // define('LOCAL_DEBUG', true) anywhere in the file sets the switch before the branch can read it.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $definedName = SecurityNodeHelper::sinkArgumentValue($call, 0);
            // Only a literal name can be matched with the constant the branch reads.
            if (SecurityNodeHelper::globalFunctionName($call) === 'define' && $definedName instanceof Scalar\String_ && strtoupper($definedName->value) === $constantName) {
                return true;
            }
        }

        // `const LOCAL_DEBUG = true;` at the top of the file sets the switch too.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Const_::class) as $constStatement) {
            // One const statement can declare several constants.
            foreach ($constStatement->consts as $declaredConstant) {
                // A matching name means the file chose the value itself.
                if (strtoupper($declaredConstant->name->toString()) === $constantName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reports whether a literal setting turns error display on.
     *
     * @param Expr $displaySetting - Second argument to ini_set().
     *
     * @return bool - True for an on-literal; false for off, or for a computed value the rule cannot read.
     */
    private function turnsDisplayOn(Expr $displaySetting): bool
    {
        // String switches are the common shape, such as `ini_set('display_errors', 'On')`.
        if ($displaySetting instanceof Scalar\String_) {
            return in_array(strtolower($displaySetting->value), ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true);
        }

        // Any non-zero integer, such as `ini_set('display_errors', 1)`, turns display on.
        if ($displaySetting instanceof Scalar\Int_) {
            return $displaySetting->value !== 0;
        }

        // Of the bare constants, only `true` turns display on.
        if ($displaySetting instanceof Expr\ConstFetch) {
            return strtolower($displaySetting->name->toString()) === 'true';
        }

        // A computed value such as `$debug` might be off, so the rule does not guess.
        return false;
    }
}
