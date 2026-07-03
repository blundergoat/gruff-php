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
 * Detects XML loading of request-controlled data without network restrictions.
 */
final class UnsafeXmlLoadingRule implements RuleInterface
{
    /**
     * Stable rule identifier for unsafe XML loading.
     */
    public const ID = 'security.unsafe-xml-loading';

    /**
     * Class names whose load/loadXML/open/xml methods are network-capable XML loaders.
     *
     * Generic method names such as `open` or `load` are only flagged when the receiver
     * is provably one of these classes; matching accepts both short and imported
     * fully-qualified spellings via SecurityNodeHelper::hasMatchingClassName().
     */
    private const XML_RECEIVER_CLASS_NAMES = ['DOMDocument', 'SimpleXMLElement', 'XMLReader'];

    /**
     * Describe the unsafe XML loading rule.
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
        );
    }

    /**
     * Find XML loaders that receive request-controlled data without LIBXML_NONET.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for network-capable XML loaders fed request data.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if (!in_array($name, ['simplexml_load_file', 'simplexml_load_string'], true)) {
                continue;
            }

            $xmlArg = SecurityNodeHelper::argumentValue($call->args, 0);
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
     * Build XML-loading findings for DOMDocument/XMLReader-style method and static calls.
     *
     * @param AnalysisUnit                    $analysisUnit - Parsed unit supplying the display path for any finding.
     * @param Expr\MethodCall|Expr\StaticCall $call         - Loader call to inspect (`load`, `loadXML`, `open`, `xml`).
     *
     * @return list<Finding> - One finding when an XML-capable receiver loads request-controlled data without LIBXML_NONET, else empty.
     */
    private function xmlMethodFindings(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): array
    {
        $method = SecurityNodeHelper::methodName($call);
        if (!in_array($method, ['load', 'loadxml', 'open', 'xml'], true)) {
            // Not an XML-loading entry point, so it cannot trigger external-entity or network fetches; skip it.
            return [];
        }

        // Loader-shaped names are everywhere (archives, ORMs, builders); without receiver evidence
        // of an XML parser class this is not an XML sink, so the user sees no false warning.
        if (!$this->isXmlCapableReceiver($analysisUnit, $call)) {
            return [];
        }

        $xmlArg = SecurityNodeHelper::argumentValue($call->args, 0);
        if ($xmlArg === null || !SecurityNodeHelper::containsUserInput($xmlArg)) {
            // The XML payload is trusted (or absent), so an unrestricted loader carries no injection risk here.
            return [];
        }

        // DOMDocument load/loadXML put options at index 1; XMLReader open/xml take encoding first, so options sit at 2.
        $optionsIndex = in_array($method, ['open', 'xml'], true) ? 2 : 1;
        if ($this->hasLibxmlNonetArgument($call->args, $optionsIndex)) {
            // The caller passed LIBXML_NONET, which blocks the network fetch this rule guards against; not a finding.
            return [];
        }

        // Request-controlled XML reaches a network-capable loader with no LIBXML_NONET: the exact unsafe shape to flag.
        return [$this->finding($analysisUnit, $call, $method)];
    }

    /**
     * Decide whether the call's receiver is provably an XML-capable parser class.
     *
     * This gate is why `gruff-php analyse --include-rule security.unsafe-xml-loading` warns
     * on real XML parsers but stays quiet on archives, ORMs, and builders that happen to
     * share the same method names.
     *
     * @param AnalysisUnit                    $analysisUnit - Parsed unit supplying top-level statements for receiver tracing.
     * @param Expr\MethodCall|Expr\StaticCall $call         - Loader-named call whose receiver is being classified.
     *
     * @return bool - true for static calls on an allowlisted XML class, inline `new DOMDocument()` receivers,
     *              variables or `$this` properties with XML parser evidence, and false for every unknown receiver
     *              so generic `open`/`load`/`xml` methods stay unflagged
     */
    private function isXmlCapableReceiver(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): bool
    {
        // Static calls name their class directly, so the allowlist check is immediate.
        if ($call instanceof Expr\StaticCall) {
            return SecurityNodeHelper::hasMatchingClassName($call->class, self::XML_RECEIVER_CLASS_NAMES);
        }

        $receiver = $call->var;
        // An inline construction names the class right at the call site.
        if ($receiver instanceof Expr\New_) {
            return SecurityNodeHelper::hasMatchingClassName($receiver->class, self::XML_RECEIVER_CLASS_NAMES);
        }

        // A plain variable needs its earlier assignments traced to learn what it holds.
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
     * Trace whether a receiver variable can hold an XML parser at the loader call.
     *
     * Same-scope writes are replayed in source order. A write the runtime could skip
     * on the sink's path (it sits in a branch the sink does not share) can add XML
     * evidence but never erase it, so one conditional rebind cannot hide a real
     * parser from the user; an unskippable write fully rebinds the receiver.
     *
     * @param AnalysisUnit    $analysisUnit - Parsed unit supplying top-level statements when the call has no enclosing function.
     * @param string          $variableName - Receiver variable name at the loader call.
     * @param Expr\MethodCall $call         - Loader call whose byte offset bounds the assignment search.
     *
     * @return bool - true when the replayed writes leave the receiver possibly bound to an allowlisted XML
     *              parser at the call; false when it was never assigned in scope or ends provably non-XML
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

        // Replay the writes in order; the possibly-XML state left at the call is what counts.
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
     * Trace whether a `$this->property` receiver can hold an XML parser at the loader call.
     *
     * @param AnalysisUnit       $analysisUnit - Parsed unit supplying statements for same-scope property assignment tracing.
     * @param Expr\PropertyFetch $receiver     - Receiver property fetch from the loader call.
     * @param Expr\MethodCall    $call         - Loader call whose receiver is being classified.
     *
     * @return bool - true when the property is typed as an XML parser, promoted with an XML parser type, assigned an XML
     *              parser in the constructor, or assigned one earlier in the call's own scope
     */
    private function isPropertyXmlParser(AnalysisUnit $analysisUnit, Expr\PropertyFetch $receiver, Expr\MethodCall $call): bool
    {
        $propertyName = $this->thisPropertyName($receiver);
        if ($propertyName === null) {
            return false;
        }

        $classLike = $this->enclosingClassLike($receiver);
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
     * Resolve a statically named `$this->property` fetch.
     *
     * @param Expr $expr - Expression that may be a property fetch.
     *
     * @return string|null - Property name when the expression is `$this->property`, null for dynamic or non-this fetches.
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
     * Find the class-like declaration containing a node.
     *
     * @param Node $node - Node whose containing class-like declaration is needed.
     *
     * @return Node\Stmt\ClassLike|null - Nearest class, trait, interface, or enum ancestor, or null at file scope.
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
     * Check same-class property declarations and promoted constructor properties for XML parser types.
     *
     * @param Node\Stmt\ClassLike $classLike    - Class-like declaration that owns the `$this` property access.
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
     * Check same-class property declarations for XML parser types.
     *
     * @param Node\Stmt\ClassLike $classLike    - Class-like declaration that owns the `$this` property access.
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
     * Check promoted constructor properties for XML parser types.
     *
     * @param Node\Stmt\ClassLike $classLike    - Class-like declaration that owns the `$this` property access.
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
     * Check whether a type node can name one of the XML parser classes.
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
     * Check constructor writes that initialise an untyped `$this` property to an XML parser.
     *
     * @param Node\Stmt\ClassLike $classLike    - Class-like declaration whose constructor is inspected.
     * @param string              $propertyName - Property name from the receiver fetch.
     *
     * @return bool - true when constructor writes leave the property possibly bound to an allowlisted XML parser.
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
     * Find a class-like declaration's constructor.
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
     * Trace same-scope writes to a `$this` property before the loader call.
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
     * Check whether an assignment is a direct statement in a method body.
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
     * Check whether any positional options argument at or after the given index passes LIBXML_NONET.
     *
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args       - Call args; string-keyed named args are skipped.
     * @param int                                                  $startIndex - First positional index the options flags can appear at; varies by
     *                                                                         loader signature.
     *
     * @return bool - True when an argument from the given index contains LIBXML_NONET.
     */
    private function hasLibxmlNonetArgument(array $args, int $startIndex): bool
    {
        foreach ($args as $index => $arg) {
            if (!is_int($index) || $index < $startIndex || !$arg instanceof Node\Arg) {
                continue;
            }

            if ($this->containsLibxmlNonet($arg->value)) {
                // A flags argument carries LIBXML_NONET, so the network-blocking guardrail is present; report safe.
                return true;
            }
        }

        // No positional flags argument referenced LIBXML_NONET, so the call is treated as network-enabled.
        return false;
    }

    /**
     * Test whether an argument expression mentions the LIBXML_NONET constant anywhere in its subtree.
     *
     * @param Node $node - Argument value node to search (a bare constant, a bitmask expression, etc.).
     *
     * @return bool - True when the node contains the LIBXML_NONET constant.
     */
    private function containsLibxmlNonet(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        // Search the whole subtree so the flag is honoured even inside a `LIBXML_NONET | LIBXML_NOENT` bitmask.
        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
                // Match the explicit network-blocking flag accepted by PHP XML loaders.
                return SecurityNodeHelper::constantName($candidate) === 'LIBXML_NONET';
            }) instanceof Node;
    }

    /**
     * Build the unsafe XML loading finding for one flagged loader call.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying the display path recorded on the finding.
     * @param Node         $node         - Loader call node; its start line locates the finding in source.
     * @param string       $sink         - Loader name (e.g. `loadXML`) put in the message and metadata so triage knows the call.
     *
     * @return Finding - Security finding.
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
