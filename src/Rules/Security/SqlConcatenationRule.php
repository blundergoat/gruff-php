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
 * Flags a SQL string assembled by concatenating or interpolating dynamic data into a query - the classic
 * SQL-injection shape - so the user moves the value to a bound parameter before it reaches the database.
 *
 * Runs per file over supported query methods and procedural database functions whose SQL argument splices in a
 * non-allowlisted dynamic part and whose literal fragments contain a SQL keyword. `prepare()` templates and
 * allowlisted identifier interpolation (`$wpdb->prefix`) are recognised. Warning, medium confidence.
 */
final class SqlConcatenationRule implements RuleInterface
{
    /**
     * Stable rule identifier for SQL concatenation findings.
     */
    public const ID = 'security.sql-concatenation';

    /**
     * Method names that run a raw SQL string as their first argument.
     *
     * @var list<string>
     */
    private const QUERY_METHODS = ['exec', 'query', 'raw', 'select'];

    /**
     * Procedural SQL sinks mapped to the positional index carrying the query string.
     *
     * pg_query() also accepts the query as its sole argument; sqlArgumentForSink() handles that overload.
     *
     * @var array<string, int>
     */
    private const PROCEDURAL_SQL_ARGUMENT_INDEXES = [
        'mysqli_query' => 1,
        'pg_query'     => 1,
        'mysql_query'  => 0,
        'sqlsrv_query' => 1,
        'oci_parse'    => 1,
    ];

    /**
     * Pattern requiring at least one word-bounded SQL keyword in the literal fragments before flagging.
     */
    private const SQL_KEYWORD_PATTERN = '/\b(?:select|insert|update|delete|alter|drop|create|show|from|where)\b/i';

    /**
     * Describes the SQL-concatenation rule for the registry and reports.
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
     * Reports each query call whose SQL argument splices in an unsafe dynamic part.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for heuristic SQL concatenation.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $safeReceivers = $ruleContext->settingsFor($this->definition())->stringListOption('safeInterpolationReceivers');
        $findings      = [];

        $queryCallCandidates = NodeIndex::nodesOfAny(
            $analysisUnit,
            [Expr\MethodCall::class, Expr\StaticCall::class, Expr\FuncCall::class],
        );
        // Restrict findings to APIs whose SQL-argument contract is known.
        foreach ($queryCallCandidates as $queryCall) {
            /** @var Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $queryCall NodeIndex restricts these classes. */
            $sqlArgument = $this->sqlArgumentForSink($queryCall);
            // Flag a supported query sink whose SQL argument splices in unsafe dynamic data.
            if ($sqlArgument !== null
                && $this->isInjectableSqlConstruction(
                    $this->sqlConstructionSubject($sqlArgument, $queryCall, $analysisUnit),
                    $safeReceivers,
                    $analysisUnit,
                )
            ) {
                $findings[] = $this->finding($analysisUnit, $queryCall);
            }
        }

        return $findings;
    }

    /**
     * Selects the SQL argument from a supported query sink.
     *
     * @param Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $queryCall - Candidate whose API determines the SQL position.
     *
     * @return Expr|null - SQL argument for a supported sink, otherwise null.
     */
    private function sqlArgumentForSink(Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $queryCall): ?Expr
    {
        // Method/static query APIs consistently take SQL as their first argument.
        if ($queryCall instanceof Expr\MethodCall || $queryCall instanceof Expr\StaticCall) {
            if (!$queryCall->name instanceof Identifier
                || !in_array(strtolower($queryCall->name->toString()), self::QUERY_METHODS, true)
            ) {
                return null;
            }

            return SecurityNodeHelper::argumentValue($queryCall->args, 0);
        }

        $functionName = SecurityNodeHelper::globalFunctionName($queryCall);
        // Unknown functions cannot safely inherit another API's argument contract.
        if ($functionName === null || !isset(self::PROCEDURAL_SQL_ARGUMENT_INDEXES[$functionName])) {
            return null;
        }

        $sqlArgumentIndex = self::PROCEDURAL_SQL_ARGUMENT_INDEXES[$functionName];
        // pg_query($query) is the one supported overload whose query occupies position zero.
        if ($functionName === 'pg_query' && SecurityNodeHelper::argumentValue($queryCall->args, 1) === null) {
            $sqlArgumentIndex = 0;
        }

        return SecurityNodeHelper::argumentValue($queryCall->args, $sqlArgumentIndex);
    }

    /**
     * Selects the expression to inspect for a query call's first argument.
     *
     * When the argument's root expression is a prepare() call the parameterisation already covers its
     * bound values, so inspection moves to the template argument - which still flags when it
     * interpolates anything beyond allowlisted parts. prepare() is never skipped wholesale.
     *
     * For a curated procedural sink, a plain local is followed through one same-scope assignment only when it
     * is the variable's sole write before the sink. Existing method/static sinks keep their direct-argument
     * behaviour so wrapper-specific quoting APIs are not reclassified by this procedural coverage change.
     *
     * @param Expr                                          $sqlArgument  - SQL value passed to the query API.
     * @param Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $querySink    - API call that consumes the SQL value.
     * @param AnalysisUnit                                  $analysisUnit - Unit owning the call and candidate local assignment.
     *
     * @return Expr - prepare() template, shallow local value, or original argument.
     */
    private function sqlConstructionSubject(
        Expr $sqlArgument,
        Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $querySink,
        AnalysisUnit $analysisUnit,
    ): Expr
    {
        $preparedSqlTemplate = $this->preparedSqlTemplate($sqlArgument);
        // Parameter binding is safe only when the template itself does not interpolate unsafe data.
        if ($preparedSqlTemplate !== $sqlArgument) {
            return $preparedSqlTemplate;
        }

        // Wrapper-specific quoting semantics are unknown, so local tracking stays limited to curated functions.
        if (!$querySink instanceof Expr\FuncCall) {
            return $sqlArgument;
        }

        $localQueryValue = $this->soleLocalQueryValue($sqlArgument, $querySink, $analysisUnit);
        // One unambiguous local hop exposes the query construction without approximating general data flow.
        if ($localQueryValue instanceof Expr) {
            return $this->preparedSqlTemplate($localQueryValue);
        }

        return $sqlArgument;
    }

    /**
     * Selects a prepare() call's SQL template without treating parameterisation as a blanket exemption.
     *
     * @param Expr $subject - Direct query argument or shallow assigned value.
     *
     * @return Expr - prepare() template when present, otherwise the original expression.
     */
    private function preparedSqlTemplate(Expr $subject): Expr
    {
        // A prepare() call already binds its values, so inspect its template instead.
        if (($subject instanceof Expr\MethodCall || $subject instanceof Expr\StaticCall)
            && SecurityNodeHelper::methodName($subject) === 'prepare'
        ) {
            $template = SecurityNodeHelper::argumentValue($subject->args, 0);
            // Use the template when prepare() supplies one.
            if ($template instanceof Expr) {
                return $template;
            }
        }

        return $subject;
    }

    /**
     * Resolves a query local through one unambiguous assignment in the sink's scope.
     *
     * @param Expr          $sqlArgument  - Query value, which must be a plainly named variable.
     * @param Expr\FuncCall $querySink    - Procedural call whose source position bounds eligible writes.
     * @param AnalysisUnit  $analysisUnit - Unit supplying file-scope statements when no function owns the sink.
     *
     * @return Expr|null - Sole assigned value before the sink, or null for ambiguity/reassignment/nested scope.
     */
    private function soleLocalQueryValue(
        Expr $sqlArgument,
        Expr\FuncCall $querySink,
        AnalysisUnit $analysisUnit,
    ): ?Expr
    {
        // Compound expressions and dynamic variable names already exceed this one-hop precision guard.
        if (!$sqlArgument instanceof Expr\Variable || !is_string($sqlArgument->name)) {
            return null;
        }

        $sinkFilePosition = $querySink->getStartFilePos();
        // Source ordering is required to reject writes that occur after the sink.
        if ($sinkFilePosition < 0) {
            return null;
        }

        $sinkScope       = SecurityNodeHelper::enclosingFunctionLike($querySink);
        $scopeStatements = $sinkScope instanceof FunctionLike ? $sinkScope->getStmts() : $analysisUnit->statements;
        // A scope without statements cannot establish a unique preceding write.
        if ($scopeStatements === null) {
            return null;
        }

        $queryWrites = $this->localQueryWritesBeforeSink(
            array_values($scopeStatements),
            $sqlArgument->name,
            $sinkScope,
            $sinkFilePosition,
        );

        // More than one write is reassignment; non-plain writes are deliberately beyond this shallow tracker.
        if (count($queryWrites) !== 1 || !$queryWrites[0] instanceof Expr\Assign) {
            return null;
        }

        $queryAssignment = $queryWrites[0];
        $sinkAncestorIds = SecurityNodeHelper::ancestorIdsWithin(
            $querySink,
            $sinkScope instanceof Node ? $sinkScope : null,
        );
        // Conditional or otherwise skippable writes cannot prove which value reaches the sink.
        if (SecurityNodeHelper::isSkippableBeforeSink(
            $queryAssignment,
            $querySink,
            $sinkScope instanceof Node ? $sinkScope : null,
            $sinkAncestorIds,
        )) {
            return null;
        }

        return $queryAssignment->expr;
    }

    /**
     * Collects writes to one local before a sink, excluding writes owned by nested function-like scopes.
     *
     * @param list<Node\Stmt>   $scopeStatements   - Statements belonging to the sink's scope.
     * @param string            $queryVariableName - Plain local name without the leading `$`.
     * @param FunctionLike|null $sinkScope         - Function-like owning the sink, or null for file scope.
     * @param int               $sinkFilePosition  - Byte offset that every eligible write must precede.
     *
     * @return list<Expr\Assign|Expr\AssignOp|Expr\AssignRef> - Matching writes in source order.
     */
    private function localQueryWritesBeforeSink(
        array $scopeStatements,
        string $queryVariableName,
        ?FunctionLike $sinkScope,
        int $sinkFilePosition,
    ): array {
        $candidateWrites = (new NodeFinder())->find(
            $scopeStatements,
            static fn(Node $candidate): bool => ($candidate instanceof Expr\Assign
                                                  || $candidate instanceof Expr\AssignOp
                                                  || $candidate instanceof Expr\AssignRef)
                                                 && $candidate->getStartFilePos() >= 0
                                                 && $candidate->getStartFilePos() < $sinkFilePosition,
        );

        $queryWrites = [];
        // Nested closures may reuse the local name without writing the value consumed by this sink.
        foreach ($candidateWrites as $write) {
            if (!($write instanceof Expr\Assign || $write instanceof Expr\AssignOp || $write instanceof Expr\AssignRef)) {
                continue;
            }

            if (SecurityNodeHelper::enclosingFunctionLike($write) === $sinkScope
                && $write->var instanceof Expr\Variable
                && $write->var->name === $queryVariableName
            ) {
                $queryWrites[] = $write;
            }
        }

        return $queryWrites;
    }

    /**
     * Reports whether an expression assembles SQL with unsafe dynamic parts.
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
     * Reports whether any string construction splices in a non-allowlisted dynamic part.
     *
     * @param Expr         $subject - Expression under inspection.
     * @param list<string> $safeReceivers - Variable names whose property fetches are safe identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True when any string construction in the subtree carries an unsafe dynamic part.
     */
    private function hasUnsafeDynamicPart(Expr $subject, array $safeReceivers, AnalysisUnit $analysisUnit): bool
    {
        // Inspect every concatenation and interpolated string in the subtree.
        foreach ((new NodeFinder())->find($subject, static fn(Node $candidate): bool => $candidate instanceof Expr\BinaryOp\Concat || $candidate instanceof Scalar\InterpolatedString) as $construction) {
            // Weigh each dynamic part directly spliced into this construction.
            foreach ($this->directDynamicParts($construction) as $part) {
                // One non-allowlisted dynamic part makes the construction unsafe.
                if (!$this->isSafeInterpolationPart($part, $safeReceivers, $analysisUnit)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Lists the direct dynamic parts of one string construction node.
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
        // A concatenation has a left and right operand.
        if ($construction instanceof Expr\BinaryOp\Concat) {
            $candidates = [$construction->left, $construction->right];
        }

        // An interpolated string has its embedded parts.
        if ($construction instanceof Scalar\InterpolatedString) {
            $candidates = $construction->parts;
        }

        $dynamicParts = [];
        // Keep only the dynamic (non-literal, non-nested) parts.
        foreach ($candidates as $candidate) {
            // Literal fragments and nested constructions are handled by the caller.
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
     * Reports whether a dynamic part is allowlisted identifier interpolation.
     *
     * @param Expr         $part - Dynamic part spliced into the string construction.
     * @param list<string> $safeReceivers - Variable names whose property fetches are safe identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True for a property fetch on an unshadowed allowlisted variable ($wpdb->prefix and friends).
     */
    private function isSafeInterpolationPart(Expr $part, array $safeReceivers, AnalysisUnit $analysisUnit): bool
    {
        // Only a property fetch on an allowlisted receiver variable can be safe identifier interpolation.
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
     * Reports whether a local binding makes an allowlisted receiver name untrustworthy.
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
        // A receiver bound as a parameter is not the trusted global.
        if ($scope instanceof FunctionLike && $this->isParameterName($receiverName, $scope)) {
            return true;
        }

        $sinkPosition = $part->getStartFilePos();
        // Without a byte offset the assignment order cannot be proven, so assume shadowed.
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

        // Weigh each earlier assignment in scope.
        foreach ($assignments as $assignment) {
            // Only an assignment node can rebind the receiver.
            if (!($assignment instanceof Expr\Assign || $assignment instanceof Expr\AssignOp || $assignment instanceof Expr\AssignRef)) {
                continue;
            }

            // An assignment in a nested scope does not rebind this variable.
            if (SecurityNodeHelper::enclosingFunctionLike($assignment) !== $scope) {
                continue;
            }

            // A local assignment to the receiver name shadows the trusted global.
            if ($assignment->var instanceof Expr\Variable && $assignment->var->name === $receiverName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a function-like declares a parameter with the given name.
     *
     * @param string       $name - Variable name without the leading `$`.
     * @param FunctionLike $scope - Function-like to inspect.
     *
     * @return bool - True when the name is bound as a parameter.
     */
    private function isParameterName(string $name, FunctionLike $scope): bool
    {
        // Check each declared parameter.
        foreach ($scope->getParams() as $parameter) {
            // A parameter of that name is the binding we are looking for.
            if ($parameter->var instanceof Expr\Variable && $parameter->var->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collects every literal string fragment under an expression.
     *
     * @param Expr $subject - Expression under inspection.
     *
     * @return list<string> - literal and interpolated-string fragment values, in finder order; empty when none.
     */
    private function literalFragments(Expr $subject): array
    {
        $fragments  = [];
        $nodeFinder = new NodeFinder();

        // Gather each literal and interpolated fragment under the expression.
        foreach ($nodeFinder->find($subject, static fn(Node $candidate): bool => $candidate instanceof Scalar\String_ || $candidate instanceof InterpolatedStringPart) as $literal) {
            // Read the fragment's literal text.
            if ($literal instanceof Scalar\String_ || $literal instanceof InterpolatedStringPart) {
                $fragments[] = $literal->value;
            }
        }

        return $fragments;
    }

    /**
     * Builds the SQL-concatenation finding for a call node.
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
