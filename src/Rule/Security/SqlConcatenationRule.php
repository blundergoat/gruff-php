<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
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
        foreach ($calls as $call) {
            /** @var Expr\MethodCall|Expr\StaticCall $call NodeIndex query restricts these classes. */
            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
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
     * @param Expr $firstArg - First argument of the query call.
     *
     * @return Expr - the prepare() template when the root is a prepare() call with one, otherwise the argument itself.
     */
    private function inspectionSubject(Expr $firstArg): Expr
    {
        if (($firstArg instanceof Expr\MethodCall || $firstArg instanceof Expr\StaticCall)
            && SecurityNodeHelper::methodName($firstArg) === 'prepare'
        ) {
            $template = SecurityNodeHelper::argumentValue($firstArg->args, 0);
            if ($template instanceof Expr) {
                return $template;
            }
        }

        return $firstArg;
    }

    /**
     * Decide whether an expression assembles SQL with unsafe dynamic parts.
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
     * @param Expr         $subject - Expression under inspection.
     * @param list<string> $safeReceivers - Variable names whose property fetches are safe identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True when any string construction in the subtree carries an unsafe dynamic part.
     */
    private function hasUnsafeDynamicPart(Expr $subject, array $safeReceivers, AnalysisUnit $analysisUnit): bool
    {
        foreach ((new NodeFinder())->find($subject, static fn(Node $candidate): bool => $candidate instanceof Expr\BinaryOp\Concat || $candidate instanceof Scalar\InterpolatedString) as $construction) {
            foreach ($this->directDynamicParts($construction) as $part) {
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
     * @param Node $construction - Concat or interpolated-string node.
     *
     * @return list<Expr> - dynamic (non-literal, non-construction) parts spliced into the string.
     */
    private function directDynamicParts(Node $construction): array
    {
        $candidates = [];
        if ($construction instanceof Expr\BinaryOp\Concat) {
            $candidates = [$construction->left, $construction->right];
        }

        if ($construction instanceof Scalar\InterpolatedString) {
            $candidates = $construction->parts;
        }

        $dynamicParts = [];
        foreach ($candidates as $candidate) {
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
     * @param Expr         $part - Dynamic part spliced into the string construction.
     * @param list<string> $safeReceivers - Variable names whose property fetches are safe identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True for a property fetch on an unshadowed allowlisted variable ($wpdb->prefix and friends).
     */
    private function isSafeInterpolationPart(Expr $part, array $safeReceivers, AnalysisUnit $analysisUnit): bool
    {
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
     * @param string       $receiverName - Variable name without the leading `$`.
     * @param Expr         $part - Property fetch whose source position is the sink boundary.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression.
     *
     * @return bool - True when the receiver is a parameter or was locally assigned before the interpolation.
     */
    private function isReceiverLocallyShadowed(string $receiverName, Expr $part, AnalysisUnit $analysisUnit): bool
    {
        $scope = SecurityNodeHelper::enclosingFunctionLike($part);
        if ($scope instanceof FunctionLike && $this->isParameterName($receiverName, $scope)) {
            return true;
        }

        $sinkPosition = $part->getStartFilePos();
        if ($sinkPosition < 0) {
            return true;
        }

        $statements = $scope instanceof FunctionLike ? ($scope->getStmts() ?? []) : $analysisUnit->statements;
        $assignments = (new NodeFinder())->find(
            array_values($statements),
            static fn(Node $candidate): bool => ($candidate instanceof Expr\Assign || $candidate instanceof Expr\AssignOp || $candidate instanceof Expr\AssignRef)
                                                && $candidate->getStartFilePos() >= 0
                                                && $candidate->getStartFilePos() < $sinkPosition,
        );

        foreach ($assignments as $assignment) {
            if (!($assignment instanceof Expr\Assign || $assignment instanceof Expr\AssignOp || $assignment instanceof Expr\AssignRef)) {
                continue;
            }

            if (SecurityNodeHelper::enclosingFunctionLike($assignment) !== $scope) {
                continue;
            }

            if ($assignment->var instanceof Expr\Variable && $assignment->var->name === $receiverName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a function-like declares a parameter with the given name.
     *
     * @param string       $name - Variable name without the leading `$`.
     * @param FunctionLike $scope - Function-like to inspect.
     *
     * @return bool - True when the name is bound as a parameter.
     */
    private function isParameterName(string $name, FunctionLike $scope): bool
    {
        foreach ($scope->getParams() as $parameter) {
            if ($parameter->var instanceof Expr\Variable && $parameter->var->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collect every literal string fragment under an expression.
     *
     * @param Expr $subject - Expression under inspection.
     *
     * @return list<string> - literal and interpolated-string fragment values, in finder order; empty when none.
     */
    private function literalFragments(Expr $subject): array
    {
        $fragments  = [];
        $nodeFinder = new NodeFinder();

        foreach ($nodeFinder->find($subject, static fn(Node $candidate): bool => $candidate instanceof Scalar\String_ || $candidate instanceof InterpolatedStringPart) as $literal) {
            if ($literal instanceof Scalar\String_ || $literal instanceof InterpolatedStringPart) {
                $fragments[] = $literal->value;
            }
        }

        return $fragments;
    }

    /**
     * Build the SQL concatenation finding for a call node.
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
