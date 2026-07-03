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
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;

/**
 * Detects SQL-like strings assembled through concatenation.
 */
final class SqlConcatenationRule implements RuleInterface
{
    /**
     * Stable rule identifier for SQL concatenation findings.
     */
    public const ID = 'security.sql-concatenation';

    /**
     * @var list<string>
     */
    private const QUERY_METHODS = ['exec', 'query', 'raw', 'select'];

    /**
     * Pattern requiring at least one word-bounded SQL keyword in the literal fragments before flagging.
     */
    private const SQL_KEYWORD_PATTERN = '/\b(?:select|insert|update|delete|alter|drop|create|show|from|where)\b/i';

    /**
     * Describe the SQL concatenation rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                  self::ID,
            name:                'SQL string concatenation',
            pillar:              Pillar::Security,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Warning,
            confidence:          Confidence::Medium,
            defaultOptions:      [
                                     'safeInterpolationReceivers' => ['wpdb'],
                                 ],
            optionDescriptions:  [
                                     'safeInterpolationReceivers' => 'Variable names whose property fetches ($wpdb->prefix, $wpdb->options, ...) are identifier interpolation, not injectable data; such parts alone never flag a query.',
                                 ],
            falsePositiveShapes: [
                                     [
                                         'shape'      => 'Already-parameterised call whose first argument is $wpdb->prepare() with only %placeholders and $wpdb->prefix-style identifiers in the template.',
                                         'mitigation' => 'The prepare() template is inspected instead of the wrapper; interpolating a local into the template still flags.',
                                     ],
                                     [
                                         'shape'      => 'DDL/maintenance SQL interpolating only $wpdb->prefix-style identifiers ("ALTER TABLE {$wpdb->prefix}t ...").',
                                         'mitigation' => 'Allowlisted via options.safeInterpolationReceivers; set the option to [] to flag every interpolation.',
                                     ],
                                     [
                                         'shape'      => 'Non-SQL query() receivers such as DOMXPath::query(\'//item[@id=\' . $tag . \']\').',
                                         'mitigation' => 'A word-bounded SQL keyword must appear in the literal fragments before the rule fires.',
                                     ],
                                 ],
        );
    }

    /**
     * Find query method calls whose first argument splices unsafe dynamic parts into SQL.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for heuristic SQL concatenation.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $safeReceivers = $ruleContext->settingsFor($this->definition())->stringListOption('safeInterpolationReceivers');
        $findings      = [];

        $calls = NodeIndex::nodesOfAny($analysisUnit, [Expr\MethodCall::class, Expr\StaticCall::class]);
        // User view: add each item that can appear in findings list.
        foreach ($calls as $call) {
            /** @var Expr\MethodCall|Expr\StaticCall $call NodeIndex query restricts these classes. */
            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($firstArg !== null
                && $call->name instanceof Identifier
                && in_array(strtolower($call->name->toString()), self::QUERY_METHODS, true)
                && $this->isInjectableSqlConstruction($this->inspectionSubject($firstArg), $safeReceivers, $analysisUnit)
            ) {
                $findings[] = $this->finding($analysisUnit, $call);
            }
        }

        return $findings;
    }

    /**
     * Select the expression to inspect for a query call's first argument.
     *
     * When the argument's root expression is a prepare() call the parameterisation already covers its
     * bound values, so inspection moves to the template argument - which still flags when it
     * interpolates anything beyond allowlisted parts. prepare() is never skipped wholesale.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr $firstArg - First argument of the query call.
     *
     * @return Expr - the prepare() template when the root is a prepare() call with one, otherwise the argument itself.
     */
    private function inspectionSubject(Expr $firstArg): Expr
    {
        // User view: choose the findings list branch for this case.
        if (($firstArg instanceof Expr\MethodCall || $firstArg instanceof Expr\StaticCall)
            && SecurityNodeHelper::methodName($firstArg) === 'prepare'
        ) {
            $template = SecurityNodeHelper::argumentValue($firstArg->args, 0);
            // User view: choose the findings list branch for this case.
            if ($template instanceof Expr) {
                return $template;
            }
        }

        return $firstArg;
    }

    /**
     * Decide whether an expression assembles SQL with unsafe dynamic parts.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr         $subject - Expression under inspection (first argument or prepare() template).
     * @param list<string> $safeReceivers - Variable names whose property fetches are safe identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True when a concatenation/interpolation splices a non-allowlisted dynamic part into
     *                fragments that contain at least one SQL keyword (the SQL keyword pattern gate).
     */
    private function isInjectableSqlConstruction(Expr $subject, array $safeReceivers, AnalysisUnit $analysisUnit): bool
    {
        // User view: choose the findings list branch for this case.
        if (!$this->hasUnsafeDynamicPart($subject, $safeReceivers, $analysisUnit)) {
            // Every dynamic part is allowlisted identifier interpolation (or there is no string construction at all).
            return false;
        }

        // SQL-shape gate: without a word-bounded SQL keyword in the literal fragments this is not a SQL sink.
        return preg_match(self::SQL_KEYWORD_PATTERN, implode(' ', $this->literalFragments($subject))) === 1;
    }

    /**
     * Detect a concatenation or interpolation whose direct dynamic parts include a non-allowlisted expression.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr         $subject - Expression under inspection.
     * @param list<string> $safeReceivers - Variable names whose property fetches are safe identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True when any string construction in the subtree carries an unsafe dynamic part.
     */
    private function hasUnsafeDynamicPart(Expr $subject, array $safeReceivers, AnalysisUnit $analysisUnit): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ((new NodeFinder())->find($subject, static fn(Node $candidate): bool => $candidate instanceof Expr\BinaryOp\Concat || $candidate instanceof Scalar\InterpolatedString) as $construction) {
            // User view: add each item that can appear in findings list.
            foreach ($this->directDynamicParts($construction) as $part) {
                // User view: choose the findings list branch for this case.
                if (!$this->isSafeInterpolationPart($part, $safeReceivers, $analysisUnit)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * List the direct dynamic parts of one string construction node.
     *
     * Literal fragments and nested constructions are skipped: nested Concat/InterpolatedString nodes are
     * visited as constructions of their own by the caller, so each dynamic leaf is judged exactly once.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $construction - Concat or interpolated-string node.
     *
     * @return list<Expr> - dynamic (non-literal, non-construction) parts spliced into the string.
     */
    private function directDynamicParts(Node $construction): array
    {
        $candidates = [];
        // User view: choose the findings list branch for this case.
        if ($construction instanceof Expr\BinaryOp\Concat) {
            $candidates = [$construction->left, $construction->right];
        }

        // User view: choose the findings list branch for this case.
        if ($construction instanceof Scalar\InterpolatedString) {
            $candidates = $construction->parts;
        }

        $dynamicParts = [];
        // User view: add each item that can appear in findings list.
        foreach ($candidates as $candidate) {
            // User view: choose the findings list branch for this case.
            if ($candidate instanceof InterpolatedStringPart
                || $candidate instanceof Scalar\String_
                || $candidate instanceof Expr\BinaryOp\Concat
                || $candidate instanceof Scalar\InterpolatedString
            ) {
                continue;
            }

            $dynamicParts[] = $candidate;
        }

        return $dynamicParts;
    }

    /**
     * Decide whether a dynamic part is allowlisted identifier interpolation.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr         $part - Dynamic part spliced into the string construction.
     * @param list<string> $safeReceivers - Variable names whose property fetches are safe identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True for a property fetch on an unshadowed allowlisted variable ($wpdb->prefix and friends).
     */
    private function isSafeInterpolationPart(Expr $part, array $safeReceivers, AnalysisUnit $analysisUnit): bool
    {
        // User view: choose the findings list branch for this case.
        if (!$part instanceof Expr\PropertyFetch
            || !$part->var instanceof Expr\Variable
            || !is_string($part->var->name)
            || !in_array($part->var->name, $safeReceivers, true)
        ) {
            return false;
        }

        return !$this->isReceiverLocallyShadowed($part->var->name, $part, $analysisUnit);
    }

    /**
     * Detect local bindings that make an allowlisted receiver name untrustworthy.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string       $receiverName - Variable name without the leading `$`.
     * @param Expr         $part - Property fetch whose source position is the sink boundary.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression.
     *
     * @return bool - True when the receiver is a parameter or was locally assigned before the interpolation.
     */
    private function isReceiverLocallyShadowed(string $receiverName, Expr $part, AnalysisUnit $analysisUnit): bool
    {
        $scope = SecurityNodeHelper::enclosingFunctionLike($part);
        // User view: choose the findings list branch for this case.
        if ($scope instanceof FunctionLike && $this->isParameterName($receiverName, $scope)) {
            return true;
        }

        $sinkPosition = $part->getStartFilePos();
        // User view: choose the findings list branch for this case.
        if ($sinkPosition < 0) {
            return true;
        }

        // User view: missing data becomes a safe findings list default.
        $statements = $scope instanceof FunctionLike ? ($scope->getStmts() ?? []) : $analysisUnit->statements;
        $assignments = (new NodeFinder())->find(
            array_values($statements),
            static fn(Node $candidate): bool => ($candidate instanceof Expr\Assign || $candidate instanceof Expr\AssignOp || $candidate instanceof Expr\AssignRef)
                                                && $candidate->getStartFilePos() >= 0
                                                && $candidate->getStartFilePos() < $sinkPosition,
        );

        // User view: add each item that can appear in findings list.
        foreach ($assignments as $assignment) {
            // User view: choose the findings list branch for this case.
            if (!($assignment instanceof Expr\Assign || $assignment instanceof Expr\AssignOp || $assignment instanceof Expr\AssignRef)) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (SecurityNodeHelper::enclosingFunctionLike($assignment) !== $scope) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($assignment->var instanceof Expr\Variable && $assignment->var->name === $receiverName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a function-like declares a parameter with the given name.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string       $name - Variable name without the leading `$`.
     * @param FunctionLike $scope - Function-like to inspect.
     *
     * @return bool - True when the name is bound as a parameter.
     */
    private function isParameterName(string $name, FunctionLike $scope): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ($scope->getParams() as $parameter) {
            // User view: choose the findings list branch for this case.
            if ($parameter->var instanceof Expr\Variable && $parameter->var->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collect every literal string fragment under an expression.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr $subject - Expression under inspection.
     *
     * @return list<string> - literal and interpolated-string fragment values, in finder order; empty when none.
     */
    private function literalFragments(Expr $subject): array
    {
        $fragments  = [];
        $nodeFinder = new NodeFinder();

        // User view: add each item that can appear in findings list.
        foreach ($nodeFinder->find($subject, static fn(Node $candidate): bool => $candidate instanceof Scalar\String_ || $candidate instanceof InterpolatedStringPart) as $literal) {
            // User view: choose the findings list branch for this case.
            if ($literal instanceof Scalar\String_ || $literal instanceof InterpolatedStringPart) {
                $fragments[] = $literal->value;
            }
        }

        return $fragments;
    }

    /**
     * Build the SQL concatenation finding for a call node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path recorded on the finding.
     * @param Node         $node - Query call flagged as concatenating SQL; its start line locates the finding.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node): Finding
    {
        // Heuristic match only, so emit a medium-confidence warning rather than a hard error.
        return new Finding(
            ruleId:      self::ID,
            message:     'Heuristic SQL query string concatenation detected.',
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Use prepared statements, bound parameters, or query-builder parameter APIs instead of concatenating SQL. prepare() templates and allowlisted identifier interpolation ($wpdb->prefix) are recognised; everything else spliced into the SQL still flags.',
        );
    }
}
