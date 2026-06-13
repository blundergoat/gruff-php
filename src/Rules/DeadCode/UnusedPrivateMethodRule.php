<?php

declare(strict_types=1);

namespace GruffPhp\Rules\DeadCode;

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
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Detects private methods that are never called within their class-like scope.
 */
final readonly class UnusedPrivateMethodRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused private method findings.
     */
    public const ID = 'dead-code.unused-private-method';

    /**
     * Magic methods excluded because PHP calls them through language hooks.
     */
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__clone', '__toString', '__debugInfo',
        '__get', '__set', '__isset', '__unset', '__call', '__callStatic',
        '__invoke', '__sleep', '__wakeup', '__serialize', '__unserialize',
        '__set_state',
    ];

    /**
     * Describe the unused private method rule.
     *
     * @return RuleDefinition - id, name, pillar, tier, and the default Warning severity callers apply to each finding
     */
    public function definition(): RuleDefinition
    {
        // High-confidence warning: a private method no in-scope call shape reaches is dead and safe to delete,
        // yet stale dead code rarely breaks a build, so it warns rather than errors.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unused private method',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find private methods that are not referenced inside their class-like scope.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per private method unreferenced in its class-like; empty when none are dead
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];

        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike NodeIndex query is constrained to class-like classes. */
            $privateMethods = $this->privateMethods($classLike);

            if ($privateMethods === []) {
                continue;
            }

            $calledNames = $this->calledPrivateMethodNames($nodeFinder, $classLike);
            $findings    = array_merge(
                $findings,
                $this->findingsForUnusedMethods(
                    analysisUnit:   $analysisUnit,
                    definition:     $definition,
                    classLike:      $classLike,
                    privateMethods: $privateMethods,
                    calledNames:    $calledNames,
                ),
            );
        }

        return $findings;
    }

    /**
     * Collect private methods declared on a class-like node.
     *
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration whose private methods are collected.
     *
     * @return array<string, Stmt\ClassMethod> - candidate private declarations keyed by method name; empty when the class-like declares none
     */
    private function privateMethods(Class_|Trait_|Enum_ $classLike): array
    {
        $privateMethods = [];

        foreach ($classLike->stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassMethod || !$stmt->isPrivate()) {
                continue;
            }

            $name = $stmt->name->toString();
            if (!in_array($name, self::MAGIC_METHODS, true)) {
                $privateMethods[$name] = $stmt;
            }
        }

        return $privateMethods;
    }

    /**
     * Collect private method calls made inside a class-like node.
     *
     * @param NodeFinder          $nodeFinder - Reused tree walker that enumerates every node under the class-like body.
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration whose body is searched for private method calls.
     *
     * @return array<string, true> - set of method names referenced anywhere in scope; a name's absence means it is unused
     */
    private function calledPrivateMethodNames(NodeFinder $nodeFinder, Class_|Trait_|Enum_ $classLike): array
    {
        $calledNames = [];
        $allNodes    = $nodeFinder->find($classLike->stmts, static fn(): bool => true);

        foreach ($allNodes as $node) {
            $name = $this->calledMethodName($node) ?? $this->callableArrayName($node, $classLike);

            if ($name !== null) {
                $calledNames[$name] = true;
            }
        }

        return $calledNames;
    }

    /**
     * Extract a private method name from `$this` or self/static calls.
     *
     * @param Node $node - Arbitrary node from the class-like body; only `$this`/self/static method calls yield a name.
     *
     * @return string|null - method name from a `$this->`/self/static call, or null when no such call shape matches
     */
    private function calledMethodName(Node $node): ?string
    {
        if ($node instanceof Expr\MethodCall
            && $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier
        ) {
            // A literal `$this->name()` call counts as a use of that private method.
            return $node->name->toString();
        }

        if ($node instanceof Expr\StaticCall
            && ($node->class instanceof Node\Name)
            && in_array($node->class->toString(), ['self', 'static'], true)
            && $node->name instanceof Node\Identifier
        ) {
            // A `self::name()` or `static::name()` call counts as a use of that private method.
            return $node->name->toString();
        }

        return null;
    }

    /**
     * Extract a method name from callable-array syntax.
     *
     * @param Node                $node - Arbitrary node from the class-like body; only callable arrays yield a name.
     * @param Class_|Trait_|Enum_ $classLike - Declaring scope used to match `ClassName::class` callable targets.
     *
     * @return string|null - method name from a supported callable array, or null when the node is not that shape
     */
    private function callableArrayName(Node $node, Class_|Trait_|Enum_ $classLike): ?string
    {
        if (!$node instanceof Expr\Array_) {
            // Only array literals can spell callable-array syntax; nothing else qualifies.
            return null;
        }

        if ($this->isCallableReference($node, $classLike)) {
            return $this->extractCallableName($node);
        }

        return null;
    }

    /**
     * Build findings for unused methods in the dead-code rule.
     *
     * @param AnalysisUnit                    $analysisUnit - Supplies the display path stamped on each finding; the
     *                                                         line span comes from each method node, not this unit.
     * @param RuleDefinition                  $definition - Supplies the rule id, severity, pillar, and tier copied into every finding.
     * @param Class_|Trait_|Enum_             $classLike - Owner whose name prefixes each reported private method symbol.
     * @param array<string, Stmt\ClassMethod> $privateMethods - Candidate private methods keyed by name.
     * @param array<string, true>             $calledNames - Private method names observed in calls within the class-like scope.
     *
     * @return list<Finding> - one finding per private method whose name never appears in $calledNames; empty when all are used
     */
    private function findingsForUnusedMethods(
        AnalysisUnit        $analysisUnit,
        RuleDefinition      $definition,
        Class_|Trait_|Enum_ $classLike,
        array               $privateMethods,
        array               $calledNames,
    ): array {
        $findings  = [];
        $className = $this->resolveClassName($classLike);

        foreach ($privateMethods as $name => $classMethod) {
            if (isset($calledNames[$name])) {
                continue;
            }

            $symbol     = sprintf('%s::%s()', $className, $name);
            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('Private method %s is never called.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $classMethod->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                endLine:     $classMethod->getEndLine() > 0 ? $classMethod->getEndLine() : null,
                symbol:      $symbol,
                remediation: 'Remove the unused private method.',
            );
        }

        return $findings;
    }

    /**
     * Check whether an expression is a same-class callable-array reference.
     *
     * @param Expr                $expr - Candidate expression to test for the callable pair.
     * @param Class_|Trait_|Enum_ $classLike - Declaring scope used to match explicit class-name callables.
     *
     * @return bool - true for same-class `[$this, 'method']`, `[self::class, 'method']`, and `[__CLASS__, 'method']` pairs
     */
    private function isCallableReference(Expr $expr, Class_|Trait_|Enum_ $classLike): bool
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            // Only a two-element array can be the `[$this, 'method']` pair; anything else is not a reference.
            return false;
        }

        $first  = $expr->items[0]->value;
        $second = $expr->items[1]->value;

        if (!$second instanceof Node\Scalar\String_) {
            return false;
        }

        if ($first instanceof Expr\Variable && $first->name === 'this') {
            return true;
        }

        if ($first instanceof Node\Scalar\MagicConst\Class_) {
            // `[__CLASS__, 'method']` names the defining class, the same as `[self::class, 'method']`.
            return true;
        }

        if (!$first instanceof Expr\ClassConstFetch
            || !$first->name instanceof Node\Identifier
            || strtolower($first->name->toString()) !== 'class'
        ) {
            return false;
        }

        if (!$first->class instanceof Node\Name) {
            return false;
        }

        $shortName = strtolower($first->class->getLast());
        if ($shortName === 'self' || $shortName === 'static') {
            return true;
        }

        // Compare fully-qualified names so a same-short-name class in another namespace cannot mask real dead code.
        return strtolower($this->resolvedReferenceName($first->class)) === strtolower($this->declaringClassName($classLike));
    }

    /**
     * Resolve a class reference to its fully-qualified name.
     *
     * @param Node\Name $name - Class name node from a callable-array target.
     *
     * @return string - Resolved fully-qualified name, or the written name when no resolution is attached.
     */
    private function resolvedReferenceName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Node\Name ? $resolved->toString() : $name->toString();
    }

    /**
     * Resolve the fully-qualified name of a class-like declaration.
     *
     * @param Class_|Trait_|Enum_ $classLike - Declaring scope whose qualified name anchors callable comparisons.
     *
     * @return string - Fully-qualified declaration name, or the short name when no namespace is resolved.
     */
    private function declaringClassName(Class_|Trait_|Enum_ $classLike): string
    {
        $namespacedName = $classLike->namespacedName ?? null;

        return $namespacedName instanceof Node\Name ? $namespacedName->toString() : $this->resolveClassName($classLike);
    }

    /**
     * Extract the method name from a supported callable-array expression.
     *
     * @param Expr $expr - Callable-array value already vetted by isCallableReference for the `[$this, 'method']` shape.
     *
     * @return string|null - the string in the array's second slot, or null when that slot is not a string literal
     */
    private function extractCallableName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            // Guard against callers that skipped the shape check; only a two-element array carries a name.
            return null;
        }

        $second = $expr->items[1]->value;

        return $second instanceof Node\Scalar\String_ ? $second->value : null;
    }

    /**
     * Resolve a display name for a class-like node.
     *
     * @param Class_|Trait_|Enum_ $node - Class-like declaration whose display symbol is needed for finding text.
     *
     * @return string - declared class/trait/enum name, or an `@anonymous`/`unknown@line` placeholder when unnamed
     */
    private function resolveClassName(Node $node): string
    {
        if ($node instanceof Class_) {
            return $node->name?->toString() ?? 'class@anonymous';
        }

        return $node->name?->toString() ?? sprintf('unknown@%d', $node->getStartLine());
    }
}
