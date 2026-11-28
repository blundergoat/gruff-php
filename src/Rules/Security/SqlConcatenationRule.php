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
 * Flags SQL assembled from unsafe dynamic data by concatenation or interpolation so callers can move values to bound parameters.
 * Supported method and procedural sinks require a SQL keyword in the literal fragments; findings are medium-confidence warnings.
 *
 * Inspection unwraps prepare() templates and performs shallow assignment inspection for procedural query variables.
 * Allowlisted property fetches, such as $wpdb->prefix, are exempt when the local binding checks do not reject the receiver.
 * Direct (int), ->quoteString() and Util::backquote() operands are exempt; the quote escapers must sit outside an open quote.
 */
final class SqlConcatenationRule implements RuleInterface
{
    /**
     * Stable rule identifier for SQL concatenation findings.
     */
    public const ID = 'security.sql-concatenation';

    /**
     * Method names treated as SQL sinks with the query in the first positional argument.
     *
     * @var list<string> - Case-insensitive names matched on method and static calls.
     */
    private const QUERY_METHODS = ['exec', 'query', 'raw', 'select'];

    /**
     * Maps supported procedural sinks to their zero-based query argument positions.
     * Named arguments use SecurityNodeHelper::SINK_PARAMETERS; sqlArgumentForSink() also handles one-argument pg_query().
     *
     * @var array<string, int> - Query positions passed to SecurityNodeHelper::sinkArgumentValue().
     */
    private const PROCEDURAL_SQL_ARGUMENTS = [
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
            // Off unless a project sets `enabled: true`: precision-floor M02 found it right on 4 of 11 judged findings, too few
            // to establish its precision floor, so it is off by default rather than deleted (ADR-034).
            isEnabledByDefault: false,
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
                                         'shape'      => 'A value escaped where it is spliced: an (int) cast, a ->quoteString() call or a Util::backquote() call.',
                                         'mitigation' => 'These count as escaped, without checking a concatenation inside their arguments, unless ->quoteString() or Util::backquote() sits inside an already open quote or the construction passes through another call such as sprintf(); any other escaping helper still flags, so bind the value instead.',
                                     ],
                                     [
                                         'shape'      => 'Non-SQL query() receivers such as DOMXPath::query(\'//item[@id=\' . $tag . \']\').',
                                         'mitigation' => 'A word-bounded SQL keyword must appear in the literal fragments before the rule fires.',
                                     ],
                                 ],
        );
    }

    /**
     * Reports recognised query-call shapes whose SQL arguments match the unsafe-dynamic-part heuristic.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Supplies receiver names whose property fetches pass the local-shadow check.
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
        // Restrict candidates to recognised query names and their configured SQL argument positions.
        foreach ($queryCallCandidates as $queryCall) {
            /** @var Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $queryCall - NodeIndex restricts these classes. */
            $sqlArgument = $this->sqlArgumentForSink($queryCall);

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
     * Selects the SQL argument from a recognised query-call shape.
     *
     * @param Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $queryCall - Candidate whose recognised name determines the SQL position.
     *
     * @return Expr|null - SQL argument, or null for unsupported calls, missing arguments or callable placeholders.
     */
    private function sqlArgumentForSink(Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $queryCall): ?Expr
    {

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
        if ($functionName === null || !isset(self::PROCEDURAL_SQL_ARGUMENTS[$functionName])) {
            return null;
        }

        $sqlArgument = SecurityNodeHelper::sinkArgumentValue($queryCall, self::PROCEDURAL_SQL_ARGUMENTS[$functionName]);
        // pg_query($query) is the one supported overload whose query occupies position zero. Match it
        // positionally only: the named form already resolved above, and asking by name here would let
        // pg_query(connection: $handle) hand back the connection as though it were the query text.
        if ($functionName === 'pg_query' && $sqlArgument === null) {
            return SecurityNodeHelper::argumentValue($queryCall->args, 0);
        }

        return $sqlArgument;
    }

    /**
     * Selects a query's SQL expression, inspecting a prepare() template instead of its bound values.
     * For procedural sinks, tries one preceding assignment in the same scope; other tracked assignments and skippable writes block it.
     * Method and static sinks retain direct-argument inspection because wrapper-specific quoting semantics are unknown.
     *
     * @param Expr $sqlArgument - SQL value passed to the query API.
     * @param Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $querySink - API call that consumes the SQL value.
     * @param AnalysisUnit $analysisUnit - Unit owning the call and candidate local assignment.
     *
     * @return Expr - prepare() template, tracked assignment's right-hand expression, or original argument.
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
     * Selects the first expression argument from a method or static call named prepare().
     *
     * @param Expr $subject - Direct query argument or shallow assigned value.
     *
     * @return Expr - First expression argument of a call named prepare(), otherwise the original expression.
     */
    private function preparedSqlTemplate(Expr $subject): Expr
    {

        if (($subject instanceof Expr\MethodCall || $subject instanceof Expr\StaticCall)
            && SecurityNodeHelper::methodName($subject) === 'prepare'
        ) {
            $template = SecurityNodeHelper::argumentValue($subject->args, 0);

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
     * @param string            $queryVariableName - Plain local name without a leading dollar sign.
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
                && $this->isVariableWrittenByAssignmentTarget($write->var, $queryVariableName)
            ) {
                $queryWrites[] = $write;
            }
        }

        return $queryWrites;
    }

    /**
     * Reports whether an assignment target overwrites any part of one local query value.
     *
     * @param Expr   $assignmentTarget - Direct, offset, or destructuring target from a preceding write.
     * @param string $queryVariableName - Local query name without the leading `$`.
     *
     * @return bool - True when the target writes the named local, one of its offsets, or a destructured occurrence.
     */
    private function isVariableWrittenByAssignmentTarget(Expr $assignmentTarget, string $queryVariableName): bool
    {
        // A direct local write replaces the value the procedural sink later consumes.
        if ($assignmentTarget instanceof Expr\Variable) {
            return $assignmentTarget->name === $queryVariableName;
        }

        // Mutating an offset changes the tracked value, even though the outer variable is not the assignment node.
        if ($assignmentTarget instanceof Expr\ArrayDimFetch) {
            return $this->isVariableWrittenByAssignmentTarget($assignmentTarget->var, $queryVariableName);
        }

        // Destructuring can rebind the query local at any nesting depth and therefore ends shallow tracking.
        if ($assignmentTarget instanceof Expr\List_) {
            foreach ($assignmentTarget->items as $item) {
                if ($item !== null && $this->isVariableWrittenByAssignmentTarget($item->value, $queryVariableName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reports whether an expression matches the rule's heuristic for SQL containing an unrecognised dynamic part.
     *
     * @param Expr         $subject - SQL argument, prepare() template, or shallow local value under inspection.
     * @param list<string> $safeReceivers - Variable names whose property fetches are safe identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True when a string construction has an unrecognised dynamic part and its literal fragments contain a SQL keyword.
     */
    private function isInjectableSqlConstruction(Expr $subject, array $safeReceivers, AnalysisUnit $analysisUnit): bool
    {
        if (!$this->hasUnsafeDynamicPart($subject, $safeReceivers, $analysisUnit)) {

            return false;
        }

        // Require a word-bounded SQL keyword in literal fragments before this rule treats the expression as SQL.
        return preg_match(self::SQL_KEYWORD_PATTERN, implode(' ', $this->literalFragments($subject))) === 1;
    }

    /**
     * Finds dynamic operands not covered by recognised exemptions in concatenations and interpolated strings.
     * Escaped operands qualify only without an intervening call; wrappers such as sprintf() admit only literals and allowlisted receivers.
     *
     * @param Expr         $subject - Expression under inspection.
     * @param list<string> $safeReceivers - Variable names whose property fetches are safe identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True when any string construction in the subtree contains a dynamic part not covered by a recognised exemption.
     */
    private function hasUnsafeDynamicPart(Expr $subject, array $safeReceivers, AnalysisUnit $analysisUnit): bool
    {
        $isEscaped = fn (Node $node): bool => $this->isEscapedOperand($node);
        // Inspect every outermost concatenation and interpolated string outside an escaped operand.
        foreach (SecurityNodeHelper::outermostStringConstructions($subject, $isEscaped) as $construction) {
            $isDirect    = !SecurityNodeHelper::hasCallBetween($construction, $subject);
            $literalText = '';
            foreach (SecurityNodeHelper::flattenedOperands($construction) as $part) {
                if ($part instanceof Scalar\String_ || $part instanceof InterpolatedStringPart) {
                    $literalText .= $part->value;
                    continue;
                }

                if (!($isDirect && $this->isEscapedAt($part, $literalText))
                    && !($part instanceof Expr && $this->isSafeInterpolationPart($part, $safeReceivers, $analysisUnit))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reports whether a spliced operand qualifies for the rule's escaped-operand exemption at its position.
     *
     * @param Node   $part        - Operand of the construction.
     * @param string $literalText - Literal text spliced before it in the same construction.
     *
     * @return bool - True for an (int) cast, or a recognised quote escaper outside any open single, double or backtick quote.
     */
    private function isEscapedAt(Node $part, string $literalText): bool
    {
        return $part instanceof Expr\Cast\Int_
            || ($this->isEscapedOperand($part) && !SecurityNodeHelper::hasOpenQuote($literalText, '\'"`', true));
    }

    /**
     * Reports whether a dynamic part passes the allowlisted-identifier heuristic.
     *
     * @param Expr         $part - Dynamic part spliced into the string construction.
     * @param list<string> $safeReceivers - Variable names configured for identifier interpolation.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression, used to reject local receiver shadows.
     *
     * @return bool - True for a property fetch on a configured variable that the local-shadow check does not reject.
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
     * Recognises integer casts and SQL escaper calls by syntax and case-insensitive name.
     * quoteString() matches any receiver; Util::backquote() matches any namespace, without verifying the callee's implementation.
     * First-class callable references do not qualify.
     *
     * @param Node $operand - Operand of a SQL concatenation or interpolation.
     *
     * @return bool - True for an (int) cast, a ->quoteString() call, or a Util::backquote() call.
     */
    private function isEscapedOperand(Node $operand): bool
    {
        if ($operand instanceof Expr\Cast\Int_) {
            return true;
        }


        if (($operand instanceof Expr\MethodCall || $operand instanceof Expr\NullsafeMethodCall)
            && !$operand->isFirstClassCallable()
            && $operand->name instanceof Identifier && $operand->name->toLowerString() === 'quotestring') {
            return true;
        }


        return $operand instanceof Expr\StaticCall && !$operand->isFirstClassCallable()
            && $operand->class instanceof Node\Name && strtolower($operand->class->getLast()) === 'util'
            && $operand->name instanceof Identifier && $operand->name->toLowerString() === 'backquote';
    }

    /**
     * Reports whether the rule recognises a local binding of an allowlisted receiver name.
     *
     * @param string       $receiverName - Variable name without the leading dollar sign.
     * @param Expr         $part - Property fetch whose source position is the sink boundary.
     * @param AnalysisUnit $analysisUnit - Unit owning the expression.
     *
     * @return bool - True when the receiver is a parameter or the rule finds a same-scope assignment before the sink.
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

        foreach ($assignments as $assignment) {
            if (!($assignment instanceof Expr\Assign || $assignment instanceof Expr\AssignOp || $assignment instanceof Expr\AssignRef)) {
                continue;
            }

            // Nested function-like assignments are outside this scope's local-binding heuristic.
            if (SecurityNodeHelper::enclosingFunctionLike($assignment) !== $scope) {
                continue;
            }

            // A same-scope assignment makes the rule treat the trusted global as shadowed.
            if ($assignment->var instanceof Expr\Variable && $assignment->var->name === $receiverName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a function-like declares a parameter with the given name.
     *
     * @param string       $name - Variable name without the leading dollar sign.
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


        foreach ($nodeFinder->find($subject, static fn(Node $candidate): bool => $candidate instanceof Scalar\String_ || $candidate instanceof InterpolatedStringPart) as $literal) {

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
     * @return Finding - Medium-confidence warning located at the query call's source line.
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
