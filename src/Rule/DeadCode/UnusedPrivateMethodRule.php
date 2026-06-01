<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

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
     * @return RuleDefinition Rule metadata and defaults.
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for unused private methods.
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

        // Hand back every unused-private-method finding gathered across the class-likes in this unit.
        return $findings;
    }

    /**
     * Collect private methods declared on a class-like node.
     *
     * @param Class_|Trait_|Enum_ $classLike
     * @return array<string, Stmt\ClassMethod>
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

        // Hand back the non-magic private declarations keyed by name, the candidates that must be referenced.
        return $privateMethods;
    }

    /**
     * Collect private method calls made inside a class-like node.
     *
     * @param NodeFinder          $nodeFinder Reused tree walker that enumerates every node under the class-like body.
     * @param Class_|Trait_|Enum_ $classLike
     * @return array<string, true>
     */
    private function calledPrivateMethodNames(NodeFinder $nodeFinder, Class_|Trait_|Enum_ $classLike): array
    {
        $calledNames = [];
        $allNodes    = $nodeFinder->find($classLike->stmts, static fn (): bool => true);

        foreach ($allNodes as $node) {
            $name = $this->calledMethodName($node) ?? $this->callableArrayName($node);

            if ($name !== null) {
                $calledNames[$name] = true;
            }
        }

        // Hand back the set of method names referenced anywhere in scope; absence here means unused.
        return $calledNames;
    }

    /**
     * Extract a private method name from `$this` or self/static calls.
     *
     * @param Node $node Arbitrary node from the class-like body; only `$this`/self/static method calls yield a name.
     *
     * @return string|null Called method name, or null for unsupported calls.
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

        // No literal in-scope call shape matched, so this node names no private method.
        return null;
    }

    /**
     * Extract a method name from callable-array syntax.
     *
     * @param Node $node Arbitrary node from the class-like body; only a `[$this, 'method']` array yields a name.
     *
     * @return string|null Callable method name, or null when the node is not supported.
     */
    private function callableArrayName(Node $node): ?string
    {
        if (!$node instanceof Expr\Array_) {
            // Only array literals can spell the `[$this, 'method']` callable shape; nothing else qualifies.
            return null;
        }

        foreach ($node->items as $arrayItem) {
            if ($this->isCallableReference($arrayItem->value)) {
                // First `[$this, 'method']` element wins; report the method it references as a use.
                return $this->extractCallableName($arrayItem->value);
            }
        }

        // The array held no `$this` callable pair, so it references no private method.
        return null;
    }

    /**
     * Build findings for unused methods in the dead-code rule.
     *
     * @param AnalysisUnit                    $analysisUnit   Supplies the display path stamped on each finding; the
     *                                                         line span comes from each method node, not this unit.
     * @param RuleDefinition                  $definition     Supplies the rule id, severity, pillar, and tier copied into every finding.
     * @param Class_|Trait_|Enum_             $classLike
     * @param array<string, Stmt\ClassMethod> $privateMethods
     * @param array<string, true>             $calledNames
     * @return list<Finding>
     */
    private function findingsForUnusedMethods(
        AnalysisUnit $analysisUnit,
        RuleDefinition $definition,
        Class_|Trait_|Enum_ $classLike,
        array $privateMethods,
        array $calledNames,
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

        // Hand back one finding per private method whose name was never seen among the called names.
        return $findings;
    }

    /**
     * Check whether an expression is a `$this` callable-array reference.
     *
     * @param Expr $expr Candidate array-item value to test for the `[$this, 'method']` callable pair.
     *
     * @return bool True when the expression is a supported callable reference.
     */
    private function isCallableReference(Expr $expr): bool
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            // Only a two-element array can be the `[$this, 'method']` pair; anything else is not a reference.
            return false;
        }

        $first  = $expr->items[0]->value;
        $second = $expr->items[1]->value;

        // True only when the pair is literally `$this` followed by a string method name.
        return ($first instanceof Expr\Variable && $first->name === 'this')
            && $second instanceof Node\Scalar\String_;
    }

    /**
     * Extract the method name from a supported callable-array expression.
     *
     * @param Expr $expr Callable-array value already vetted by isCallableReference for the `[$this, 'method']` shape.
     *
     * @return string|null Callable method name, or null when not available.
     */
    private function extractCallableName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            // Guard against callers that skipped the shape check; only a two-element array carries a name.
            return null;
        }

        $second = $expr->items[1]->value;

        // The method name lives in the second slot as a string literal; absent that, there is no name.
        return $second instanceof Node\Scalar\String_ ? $second->value : null;
    }

    /**
     * Resolve a display name for a class-like node.
     *
     * @param Class_|Trait_|Enum_ $node
     *
     * @return string Class-like display name.
     */
    private function resolveClassName(Node $node): string
    {
        if ($node instanceof Class_) {
            // Anonymous classes have no name node, so fall back to a stable placeholder for the symbol string.
            return $node->name?->toString() ?? 'class@anonymous';
        }

        // Traits and enums are always named; the line-tagged fallback only guards against malformed input.
        return $node->name?->toString() ?? sprintf('unknown@%d', $node->getStartLine());
    }
}
