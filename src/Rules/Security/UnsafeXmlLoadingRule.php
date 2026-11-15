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
use PhpParser\NodeFinder;

/**
 * Reports request-tainted XML loader calls with no recognised LIBXML_NONET argument.
 * Checks SimpleXML functions and load/loadXML/open/xml methods on receivers with XML class-name evidence.
 *
 * Receiver evidence comes from static names, inline construction, variable assignments and `$this` properties.
 * Name matching is heuristic and can include user-defined classes sharing an XML parser's short name.
 *
 * Findings are warnings at medium confidence; the scan does not establish whether external entities are enabled.
 */
final class UnsafeXmlLoadingRule implements RuleInterface
{
    /**
     * Stable rule identifier for unsafe XML loading.
     */
    public const ID = 'security.unsafe-xml-loading';

    /**
     * Class-name allowlist for XML receiver heuristics.
     * Short names also match the final segment of qualified names, including user-defined classes.
     * This supplies name evidence rather than resolved type information.
     */
    private const XML_RECEIVER_CLASS_NAMES = ['DOMDocument', 'SimpleXMLElement', 'XMLReader'];

    /**
     * Describes the unsafe-XML-loading rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: taint tracking is heuristic and LIBXML_NONET may be set out of view, so warn not error.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unsafe XML loading',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'A parse guarded somewhere the call cannot see, such as libxml_set_external_entity_loader(null) installed during bootstrap.',
                    'mitigation' => 'Only an explicit LIBXML_NONET argument on the call itself counts as guarded, so pass that flag where the parse happens.',
                ],
            ],
        );
    }

    /**
     * Reports supported XML loader calls whose data looks request-controlled and whose eligible arguments do not mention LIBXML_NONET.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for supported XML-loader shapes that pass the request-data and options heuristics.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);

            if (!in_array($name, ['simplexml_load_file', 'simplexml_load_string'], true)) {
                continue;
            }

            $xmlArg = SecurityNodeHelper::sinkArgumentValue($call, 0);

            if ($xmlArg !== null && SecurityNodeHelper::containsUserInput($xmlArg) && !$this->hasLibxmlNonetArgument($call->args, 2)) {
                $findings[] = $this->finding($analysisUnit, $call, $name);
            }
        }


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\MethodCall::class) as $call) {
            array_push($findings, ...$this->xmlMethodFindings($analysisUnit, $call));
        }


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $call) {
            array_push($findings, ...$this->xmlMethodFindings($analysisUnit, $call));
        }

        return $findings;
    }

    /**
     * Builds the XML-loading findings for DOMDocument/XMLReader-style method and static calls.
     *
     * @param AnalysisUnit                    $analysisUnit - Parsed unit supplying the display path for any finding.
     * @param Expr\MethodCall|Expr\StaticCall $call         - Loader call to inspect (load, loadXML, open, xml).
     *
     * @return list<Finding> - One finding when receiver and input heuristics match and no eligible argument mentions LIBXML_NONET; otherwise empty.
     */
    private function xmlMethodFindings(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): array
    {
        $method = SecurityNodeHelper::methodName($call);
        if (!in_array($method, ['load', 'loadxml', 'open', 'xml'], true)) {

            return [];
        }

        // Common loader names also occur on archives and ORMs; require XML receiver evidence before reporting.

        if (!$this->isXmlCapableReceiver($analysisUnit, $call)) {
            return [];
        }

        $xmlArg = SecurityNodeHelper::argumentValue($call->args, 0);
        if ($xmlArg === null || !SecurityNodeHelper::containsUserInput($xmlArg)) {

            return [];
        }

        // DOMDocument load/loadXML put options at index 1; XMLReader open/xml take encoding first, so options sit at 2.
        $optionsIndex = in_array($method, ['open', 'xml'], true) ? 2 : 1;
        if ($this->hasLibxmlNonetArgument($call->args, $optionsIndex)) {
            // An eligible argument mentions LIBXML_NONET, so the rule treats this call as guarded and does not report it.
            return [];
        }


        return [$this->finding($analysisUnit, $call, $method)];
    }

    /**
     * Looks for XML parser class-name evidence on a loader call's receiver.
     * Recognises static class names, inline construction, variable assignments and $this properties.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying top-level statements for receiver tracing.
     * @param Expr\MethodCall|Expr\StaticCall $call - Loader-named call whose receiver is being classified.
     *
     * @return bool - True when a supported receiver shape yields XML class-name evidence; false for unknown receivers.
     */
    private function isXmlCapableReceiver(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): bool
    {

        if ($call instanceof Expr\StaticCall) {
            return SecurityNodeHelper::hasMatchingClassName($call->class, self::XML_RECEIVER_CLASS_NAMES);
        }

        $receiver = $call->var;

        if ($receiver instanceof Expr\New_) {
            return SecurityNodeHelper::hasMatchingClassName($receiver->class, self::XML_RECEIVER_CLASS_NAMES);
        }


        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            return $this->isVariableXmlParser($analysisUnit, $receiver->name, $call);
        }


        if ($receiver instanceof Expr\PropertyFetch) {
            return $this->isPropertyXmlParser($analysisUnit, $receiver, $call);
        }

        // Chained calls and other receiver shapes carry no class evidence; stay silent.
        return false;
    }

    /**
     * Replays earlier same-scope assignments to detect construction of a recognised XML parser class.
     * Skippable writes can add parser evidence; other writes replace it.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying top-level statements when the call has no enclosing function.
     * @param string $variableName - Receiver variable name at the loader call.
     * @param Expr\MethodCall $call - Loader call whose byte offset bounds the assignment search.
     *
     * @return bool - True when replay retains XML construction evidence; false when absent, cleared or positions are unavailable.
     */
    private function isVariableXmlParser(AnalysisUnit $analysisUnit, string $variableName, Expr\MethodCall $call): bool
    {
        $callPosition = $call->getStartFilePos();
        // Without byte offsets the assignment order cannot be proven; stay silent rather than guess.
        if ($callPosition < 0) {
            return false;
        }

        $scope      = SecurityNodeHelper::enclosingFunctionLike($call);
        $statements = $scope instanceof Node\FunctionLike ? ($scope->getStmts() ?? []) : $analysisUnit->statements;
        $nodeFinder = new NodeFinder();
        $writes     = $nodeFinder->find(
            array_values($statements),
            static fn(Node $candidate): bool => $candidate instanceof Expr\Assign
                                                && $candidate->var instanceof Expr\Variable
                                                && $candidate->var->name === $variableName
                                                && $candidate->getStartFilePos() >= 0
                                                && $candidate->getStartFilePos() < $callPosition,
        );
        usort($writes, static fn(Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos());

        $sinkAncestorIds = SecurityNodeHelper::ancestorIdsWithin($call, $scope);
        $isPossiblyXml   = false;


        foreach ($writes as $write) {
            // Writes from nested closures do not bind this scope's variable.
            if (!$write instanceof Expr\Assign || SecurityNodeHelper::enclosingFunctionLike($write) !== $scope) {
                continue;
            }

            $constructsXmlParser = $write->expr instanceof Expr\New_
                                   && SecurityNodeHelper::hasMatchingClassName($write->expr->class, self::XML_RECEIVER_CLASS_NAMES);

            // A skippable write can add XML evidence but never erase it; an unskippable one rebinds fully.
            $isPossiblyXml = SecurityNodeHelper::isSkippableBeforeSink($write, $call, $scope, $sinkAncestorIds)
                ? ($isPossiblyXml || $constructsXmlParser)
                : $constructsXmlParser;
        }

        return $isPossiblyXml;
    }

    /**
     * Looks for XML parser class-name evidence on a statically named $this property.
     * Checks declared and promoted types, constructor writes and earlier same-scope assignments.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying statements for same-scope property assignment tracing.
     * @param Expr\PropertyFetch $receiver - Receiver property fetch from the loader call.
     * @param Expr\MethodCall $call - Loader call whose receiver is being classified.
     *
     * @return bool - True when types or replayed writes retain XML parser name evidence; false for unsupported properties or no evidence.
     */
    private function isPropertyXmlParser(AnalysisUnit $analysisUnit, Expr\PropertyFetch $receiver, Expr\MethodCall $call): bool
    {
        $propertyName = $this->thisPropertyName($receiver);
        // A dynamic or non-this property fetch gives no name to trace.
        if ($propertyName === null) {
            return false;
        }

        $classLike = $this->enclosingClassLike($receiver);
        // The class either types the property as a parser or builds one in its constructor.
        if (
            $classLike instanceof Node\Stmt\ClassLike
            && (
                $this->hasClassPropertyXmlParserType($classLike, $propertyName)
                || $this->hasConstructorXmlParserAssignment($classLike, $propertyName)
            )
        ) {
            return true;
        }

        return $this->isPropertyAssignedXmlParserInScope($analysisUnit, $propertyName, $call);
    }

    /**
     * Resolves a statically named $this->property fetch to its property name.
     *
     * @param Expr $expr - Expression that may be a property fetch.
     *
     * @return string|null - Property name when the expression is $this->property, null for dynamic or non-this fetches.
     */
    private function thisPropertyName(Expr $expr): ?string
    {

        if (
            !$expr instanceof Expr\PropertyFetch
            || !$expr->var instanceof Expr\Variable
            || $expr->var->name !== 'this'
            || !$expr->name instanceof Node\Identifier
        ) {
            return null;
        }

        return $expr->name->toString();
    }

    /**
     * Finds the nearest class-like node starting with the supplied node.
     *
     * @param Node $node - Starting node for the parent-chain search.
     *
     * @return Node\Stmt\ClassLike|null - Supplied or enclosing class, trait, interface or enum; null when the chain contains none.
     */
    private function enclosingClassLike(Node $node): ?Node\Stmt\ClassLike
    {

        $current = $node;
        while ($current instanceof Node) {

            if ($current instanceof Node\Stmt\ClassLike) {
                return $current;
            }

            $parent  = $current->getAttribute('parent');
            $current = $parent instanceof Node ? $parent : null;
        }

        return null;
    }

    /**
     * Reports whether a same-class or promoted property is typed as an XML parser.
     *
     * @param Node\Stmt\ClassLike $classLike    - Class-like declaration that owns the $this property access.
     * @param string              $propertyName - Property name from the receiver fetch.
     *
     * @return bool - true when a declared or promoted property type names an allowlisted XML parser class.
     */
    private function hasClassPropertyXmlParserType(Node\Stmt\ClassLike $classLike, string $propertyName): bool
    {
        return $this->hasDeclaredPropertyXmlParserType($classLike, $propertyName)
               || $this->hasPromotedPropertyXmlParserType($classLike, $propertyName);
    }

    /**
     * Reports whether a declared same-class property is typed as an XML parser.
     *
     * @param Node\Stmt\ClassLike $classLike    - Class-like declaration that owns the $this property access.
     * @param string              $propertyName - Property name from the receiver fetch.
     *
     * @return bool - true when a declared property type names an allowlisted XML parser class.
     */
    private function hasDeclaredPropertyXmlParserType(Node\Stmt\ClassLike $classLike, string $propertyName): bool
    {
        foreach ($classLike->stmts as $statement) {

            if (!$statement instanceof Node\Stmt\Property || $statement->isStatic() || !$this->isXmlParserType($statement->type)) {
                continue;
            }

            foreach ($statement->props as $property) {

                if ($property->name->toString() === $propertyName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reports whether a promoted constructor property is typed as an XML parser.
     *
     * @param Node\Stmt\ClassLike $classLike    - Class-like declaration that owns the $this property access.
     * @param string              $propertyName - Property name from the receiver fetch.
     *
     * @return bool - true when a promoted constructor property type names an allowlisted XML parser class.
     */
    private function hasPromotedPropertyXmlParserType(Node\Stmt\ClassLike $classLike, string $propertyName): bool
    {
        $constructor = $this->constructor($classLike);

        if ($constructor === null) {
            return false;
        }


        foreach ($constructor->params as $parameter) {

            if (
                $parameter->flags === 0
                || !$parameter->var instanceof Expr\Variable
                || $parameter->var->name !== $propertyName
            ) {
                continue;
            }


            if ($this->isXmlParserType($parameter->type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a type node can name one of the XML parser classes.
     *
     * @param Node|null $type - Property or parameter type node.
     *
     * @return bool - true when the type, nullable type, union member, or intersection member names an XML parser class.
     */
    private function isXmlParserType(?Node $type): bool
    {

        if ($type === null) {
            return false;
        }


        if ($type instanceof Node\Name) {
            return SecurityNodeHelper::hasMatchingClassName($type, self::XML_RECEIVER_CLASS_NAMES);
        }


        if ($type instanceof Node\NullableType) {
            return $this->isXmlParserType($type->type);
        }


        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {

            foreach ($type->types as $innerType) {

                if ($this->isXmlParserType($innerType)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Replays constructor assignments to find XML parser construction for a $this property.
     * Direct statements replace parser evidence; nested statements can only add it.
     *
     * @param Node\Stmt\ClassLike $classLike - Class-like declaration whose constructor is inspected.
     * @param string $propertyName - Property name from the receiver fetch.
     *
     * @return bool - True when constructor writes leave the property possibly bound to an allowlisted XML parser.
     */
    private function hasConstructorXmlParserAssignment(Node\Stmt\ClassLike $classLike, string $propertyName): bool
    {
        $constructor = $this->constructor($classLike);

        if ($constructor === null) {
            return false;
        }

        $assignments = (new NodeFinder())->findInstanceOf($constructor->stmts ?? [], Expr\Assign::class);
        usort($assignments, static fn(Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos());

        $isPossiblyXml = false;

        foreach ($assignments as $assignment) {

            if (
                SecurityNodeHelper::enclosingFunctionLike($assignment) !== $constructor
                || $this->thisPropertyName($assignment->var) !== $propertyName
            ) {
                continue;
            }

            $constructsXmlParser = $assignment->expr instanceof Expr\New_
                                   && SecurityNodeHelper::hasMatchingClassName($assignment->expr->class, self::XML_RECEIVER_CLASS_NAMES);

            $isPossiblyXml = $this->isDirectStatementInScope($assignment, $constructor)
                ? $constructsXmlParser
                : ($isPossiblyXml || $constructsXmlParser);
        }

        return $isPossiblyXml;
    }

    /**
     * Returns a class-like declaration's constructor, or null when it has none.
     *
     * @param Node\Stmt\ClassLike $classLike - Class-like declaration to inspect.
     *
     * @return Node\Stmt\ClassMethod|null - Constructor method, or null when the class-like has none.
     */
    private function constructor(Node\Stmt\ClassLike $classLike): ?Node\Stmt\ClassMethod
    {
        foreach ($classLike->stmts as $statement) {

            if ($statement instanceof Node\Stmt\ClassMethod && strtolower($statement->name->toString()) === '__construct') {
                return $statement;
            }
        }

        return null;
    }

    /**
     * Reports whether same-scope writes bind a `$this` property to an XML parser before the loader call.
     *
     * @param AnalysisUnit    $analysisUnit - Parsed unit supplying top-level statements when the call has no enclosing method.
     * @param string          $propertyName - Receiver property name at the loader call.
     * @param Expr\MethodCall $call         - Loader call whose byte offset bounds the assignment search.
     *
     * @return bool - true when earlier same-scope writes leave the property possibly bound to an XML parser.
     */
    private function isPropertyAssignedXmlParserInScope(AnalysisUnit $analysisUnit, string $propertyName, Expr\MethodCall $call): bool
    {
        $callPosition = $call->getStartFilePos();
        // Without byte offsets the assignment order cannot be proven; stay silent.
        if ($callPosition < 0) {
            return false;
        }

        $scope      = SecurityNodeHelper::enclosingFunctionLike($call);
        $statements = $scope instanceof Node\FunctionLike ? ($scope->getStmts() ?? []) : $analysisUnit->statements;
        $writes     = (new NodeFinder())->find(
            array_values($statements),
            fn(Node $candidate): bool => $candidate instanceof Expr\Assign
                                         && $candidate->getStartFilePos() >= 0
                                         && $candidate->getStartFilePos() < $callPosition
                                         && $this->thisPropertyName($candidate->var) === $propertyName,
        );
        usort($writes, static fn(Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos());

        $sinkAncestorIds = SecurityNodeHelper::ancestorIdsWithin($call, $scope);
        $isPossiblyXml   = false;


        foreach ($writes as $write) {
            // Writes from nested closures do not bind this scope's property.
            if (!$write instanceof Expr\Assign || SecurityNodeHelper::enclosingFunctionLike($write) !== $scope) {
                continue;
            }

            $constructsXmlParser = $write->expr instanceof Expr\New_
                                   && SecurityNodeHelper::hasMatchingClassName($write->expr->class, self::XML_RECEIVER_CLASS_NAMES);

            $isPossiblyXml = SecurityNodeHelper::isSkippableBeforeSink($write, $call, $scope, $sinkAncestorIds)
                ? ($isPossiblyXml || $constructsXmlParser)
                : $constructsXmlParser;
        }

        return $isPossiblyXml;
    }

    /**
     * Reports whether an assignment is a direct statement in a method body.
     *
     * @param Node              $node  - Assignment node being classified.
     * @param Node\FunctionLike $scope - Function-like scope expected to own the statement directly.
     *
     * @return bool - true for top-level expression statements in the scope body, false for branch/nested statements.
     */
    private function isDirectStatementInScope(Node $node, Node\FunctionLike $scope): bool
    {
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Stmt\Expression) {
            $parent = $parent->getAttribute('parent');
        }

        return $parent === $scope;
    }

    /**
     * Reports whether a named argument or a positional argument at or after the given slot mentions LIBXML_NONET.
     *
     * @param array<int|string, Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args       - Positional and named call arguments.
     * @param int                                                                      $startIndex - First options slot for this loader.
     *
     * @return bool - True when an eligible argument contains a LIBXML_NONET reference.
     */
    private function hasLibxmlNonetArgument(array $args, int $startIndex): bool
    {

        foreach ($args as $index => $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }

            // A named argument names the parameter it fills, so its position says nothing about which slot it is.
            //
            // Only an unnamed argument can be ruled out by sitting before the flags slot. Skipping named arguments here
            // would report simplexml_load_string(data: $xml, options: LIBXML_NONET) as unguarded, a false positive.
            if (!$arg->name instanceof Node\Identifier && is_int($index) && $index < $startIndex) {
                continue;
            }

            if ($this->containsLibxmlNonet($arg->value)) {
                // An eligible argument mentions LIBXML_NONET, so the rule treats the call as guarded.
                return true;
            }
        }

        // No eligible argument mentioned LIBXML_NONET, so the rule retains the call as a heuristic candidate.
        return false;
    }

    /**
     * Reports whether an argument expression mentions the LIBXML_NONET constant anywhere in its subtree.
     *
     * @param Node $node - Argument value node to search (a bare constant, a bitmask expression, etc.).
     *
     * @return bool - True when the node contains the LIBXML_NONET constant.
     */
    private function containsLibxmlNonet(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        // Search the whole subtree so the heuristic recognises LIBXML_NONET inside a bitmask expression.
        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {

                return SecurityNodeHelper::constantName($candidate) === 'LIBXML_NONET';
            }) instanceof Node;
    }

    /**
     * Builds the unsafe-XML-loading finding for one flagged loader call.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying the display path recorded on the finding.
     * @param Node $node - Loader call node; its start line locates the finding in source.
     * @param string $sink - Loader name (e.g. loadXML) put in the message and metadata so triage knows the call.
     *
     * @return Finding - Warning for the named XML loader, with its source location and sink metadata.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {
        // Warning, not Error: a flagged loader may still be safe if entity loading is disabled elsewhere.
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('XML loading with request-controlled data and no LIBXML_NONET detected: %s.', $sink),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Use LIBXML_NONET and disable external entity/network loading before parsing request-controlled XML.',
            metadata:    [
                             'sink' => $sink,
                         ],
        );
    }
}
