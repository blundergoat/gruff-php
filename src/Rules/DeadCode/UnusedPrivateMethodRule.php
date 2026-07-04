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
 * Flags a private method that nothing inside its own class-like ever calls, so the user can delete
 * genuinely dead helpers.
 *
 * Runs per file: for each class, trait, or enum it collects the private methods (skipping magic methods
 * PHP calls through language hooks) and every in-scope call shape - `$this->m()`, `self::m()`,
 * `[$this, 'm']` callable arrays - then reports any private method never called. If the class holds a
 * computed dispatch it cannot resolve (`$this->{$name}()`), any private method might be the target, so
 * the whole class is skipped to keep findings safe.
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
     * Describes the unused-private-method rule for the registry and reports.
     *
     * @return RuleDefinition - id, name, pillar, tier, and the default Warning severity callers apply to each finding.
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
     * Reports each private method that no in-scope call reaches, class by class.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per private method unreferenced in its class-like; empty when none are dead.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];

        // Check each class, trait, or enum in the file.
        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike NodeIndex query is constrained to class-like classes. */
            $privateMethods = $this->privateMethods($classLike);

            // Nothing to check when the class declares no private methods.
            if ($privateMethods === []) {
                continue;
            }

            // Computed same-class dispatch may reach any private method here, so telling the user to
            // delete one would be unsafe advice; accepted trade-off: skip this whole class-like.
            if ($this->hasUnresolvableDynamicDispatch($nodeFinder, $classLike)) {
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
     * Collects a class-like's private methods, skipping magic methods PHP invokes through language hooks.
     *
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration whose private methods are collected.
     *
     * @return array<string, Stmt\ClassMethod> - candidate private declarations keyed by method name; empty when the class-like declares none.
     */
    private function privateMethods(Class_|Trait_|Enum_ $classLike): array
    {
        $privateMethods = [];

        // Scan the class body for private method declarations.
        foreach ($classLike->stmts as $stmt) {
            // Only private methods are candidates.
            if (!$stmt instanceof Stmt\ClassMethod || !$stmt->isPrivate()) {
                continue;
            }

            $name = $stmt->name->toString();
            // Skip magic methods; PHP calls them for you, so they are never truly dead.
            if (!in_array($name, self::MAGIC_METHODS, true)) {
                $privateMethods[$name] = $stmt;
            }
        }

        return $privateMethods;
    }

    /**
     * Collects the names of every private method called in scope, by call or callable-array reference.
     *
     * @param NodeFinder          $nodeFinder - Reused tree walker that enumerates every node under the class-like body.
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration whose body is searched for private method calls.
     *
     * @return array<string, true> - set of method names referenced anywhere in scope; a name's absence means it is unused.
     */
    private function calledPrivateMethodNames(NodeFinder $nodeFinder, Class_|Trait_|Enum_ $classLike): array
    {
        $calledNames = [];
        $allNodes    = $nodeFinder->find($classLike->stmts, static fn(): bool => true);

        // Walk every node, resolving any call or callable array to a method name.
        foreach ($allNodes as $node) {
            $name = $this->calledMethodName($node) ?? $this->callableArrayName($node, $classLike);

            // Record any method name a call resolved to.
            if ($name !== null) {
                $calledNames[$name] = true;
            }
        }

        return $calledNames;
    }

    /**
     * Reads the called method name from a `$this->`/self/static call, or null for any other node.
     *
     * @param Node $node - Arbitrary node from the class-like body; only `$this`/self/static method calls yield a name.
     *
     * @return string|null - method name from a `$this->`/self/static call, or null when no such call shape matches.
     */
    private function calledMethodName(Node $node): ?string
    {
        // A `$this->...()` call may name a private method.
        if ($node instanceof Expr\MethodCall
            && $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
        ) {
            if ($node->name instanceof Node\Identifier) {
                // A literal `$this->name()` call counts as a use of that private method.
                return $node->name->toString();
            }

            if ($node->name instanceof Node\Scalar\String_) {
                // `$this->{'name'}()` resolves exactly like the literal call, so count it precisely.
                return $node->name->value;
            }
        }

        // A `self::...()` or `static::...()` call may name a private method.
        if ($node instanceof Expr\StaticCall
            && ($node->class instanceof Node\Name)
            && in_array($node->class->toString(), ['self', 'static'], true)
        ) {
            if ($node->name instanceof Node\Identifier) {
                // A `self::name()` or `static::name()` call counts as a use of that private method.
                return $node->name->toString();
            }

            if ($node->name instanceof Node\Scalar\String_) {
                // `self::{'name'}()` resolves exactly like the literal call, so count it precisely.
                return $node->name->value;
            }
        }

        return null;
    }

    /**
     * Reports whether the class holds a computed same-class dispatch whose target cannot be resolved.
     *
     * @param NodeFinder          $nodeFinder - Reused tree walker over the class-like body.
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration whose body is searched for dynamic dispatch.
     *
     * @return bool - true when a `$this->{$expr}()`, `self::{$expr}()`, `static::{$expr}()`, or same-class callable
     *              array with a non-literal method slot exists; dynamic calls on other receivers never count.
     */
    private function hasUnresolvableDynamicDispatch(NodeFinder $nodeFinder, Class_|Trait_|Enum_ $classLike): bool
    {
        // Scan the whole class-like body: one computed dispatch anywhere makes every candidate unsafe to report.
        foreach ($nodeFinder->find($classLike->stmts, static fn(): bool => true) as $node) {
            // A computed instance call on the object itself: the target name is unknowable statically.
            if ($node instanceof Expr\MethodCall
                && $node->var instanceof Expr\Variable
                && $node->var->name === 'this'
                && !$node->name instanceof Node\Identifier
                && !$node->name instanceof Node\Scalar\String_
            ) {
                return true;
            }

            // The static-call twin of the same shape, reached through self or static.
            if ($node instanceof Expr\StaticCall
                && $node->class instanceof Node\Name
                && in_array($node->class->toString(), ['self', 'static'], true)
                && !$node->name instanceof Node\Identifier
                && !$node->name instanceof Node\Scalar\String_
            ) {
                return true;
            }

            // A same-class callable array whose method slot is computed can invoke any private method here.
            if ($node instanceof Expr\Array_
                && count($node->items) === 2
                && !$node->items[1]->value instanceof Node\Scalar\String_
                && $this->isSameClassCallableTarget($node->items[0]->value, $classLike)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads the method name from a same-class callable array, or null when the node is not that shape.
     *
     * @param Node                $node - Arbitrary node from the class-like body; only callable arrays yield a name.
     * @param Class_|Trait_|Enum_ $classLike - Declaring scope used to match `ClassName::class` callable targets.
     *
     * @return string|null - method name from a supported callable array, or null when the node is not that shape.
     */
    private function callableArrayName(Node $node, Class_|Trait_|Enum_ $classLike): ?string
    {
        if (!$node instanceof Expr\Array_) {
            // Only array literals can spell callable-array syntax; nothing else qualifies.
            return null;
        }

        // A same-class callable array's second slot names the method.
        if ($this->isCallableReference($node, $classLike)) {
            return $this->extractCallableName($node);
        }

        return null;
    }

    /**
     * Builds one finding per private method whose name never appears among the observed calls.
     *
     * @param AnalysisUnit                    $analysisUnit - Supplies the display path stamped on each finding; the
     *                                                         line span comes from each method node, not this unit.
     * @param RuleDefinition                  $definition - Supplies the rule id, severity, pillar, and tier copied into every finding.
     * @param Class_|Trait_|Enum_             $classLike - Owner whose name prefixes each reported private method symbol.
     * @param array<string, Stmt\ClassMethod> $privateMethods - Candidate private methods keyed by name.
     * @param array<string, true>             $calledNames - Private method names observed in calls within the class-like scope.
     *
     * @return list<Finding> - one finding per private method whose name never appears in $calledNames; empty when all are used.
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

        // Report each declared method that no call referenced.
        foreach ($privateMethods as $name => $classMethod) {
            // A method that was called is live, so skip it.
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
     * Reports whether an expression is a same-class `[target, 'method']` callable pair.
     *
     * @param Expr                $expr - Candidate expression to test for the callable pair.
     * @param Class_|Trait_|Enum_ $classLike - Declaring scope used to match explicit class-name callables.
     *
     * @return bool - true for same-class `[$this, 'method']`, `[self::class, 'method']`, and `[__CLASS__, 'method']` pairs.
     */
    private function isCallableReference(Expr $expr, Class_|Trait_|Enum_ $classLike): bool
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            // Only a two-element array can be the `[$this, 'method']` pair; anything else is not a reference.
            return false;
        }

        // The method slot must be a string literal for the name to be knowable.
        if (!$expr->items[1]->value instanceof Node\Scalar\String_) {
            return false;
        }

        return $this->isSameClassCallableTarget($expr->items[0]->value, $classLike);
    }

    /**
     * Reports whether a callable array's first element resolves to the declaring class itself.
     *
     * @param Expr                $first - First element of a two-item array literal.
     * @param Class_|Trait_|Enum_ $classLike - Declaring scope used to match explicit class-name callables.
     *
     * @return bool - true for `$this`, `__CLASS__`, `self::class`, `static::class`, and the declaring class's own
     *              `Name::class` reference; false for every other receiver expression.
     */
    private function isSameClassCallableTarget(Expr $first, Class_|Trait_|Enum_ $classLike): bool
    {
        // The object itself is always the defining class.
        if ($first instanceof Expr\Variable && $first->name === 'this') {
            return true;
        }

        // `[__CLASS__, 'method']` names the defining class, the same as `[self::class, 'method']`.
        if ($first instanceof Node\Scalar\MagicConst\Class_) {
            return true;
        }

        // Beyond those two shapes, only a `SomeName::class` constant can still target this class.
        if (!$first instanceof Expr\ClassConstFetch
            || !$first->name instanceof Node\Identifier
            || strtolower($first->name->toString()) !== 'class'
        ) {
            return false;
        }

        // Dynamic class expressions carry no name to compare against.
        if (!$first->class instanceof Node\Name) {
            return false;
        }

        $shortName = strtolower($first->class->getLast());
        // self::class and static::class resolve to the defining class by definition.
        if ($shortName === 'self' || $shortName === 'static') {
            return true;
        }

        // Compare fully-qualified names so a same-short-name class in another namespace cannot mask real dead code.
        return strtolower($this->resolvedReferenceName($first->class)) === strtolower($this->declaringClassName($classLike));
    }

    /**
     * Resolves a class-name node to its fully-qualified form, falling back to the written name.
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
     * Resolves the declaring class-like's fully-qualified name, or its short name when none is attached.
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
     * Reads the method name from a vetted `[target, 'method']` callable array, or null when absent.
     *
     * @param Expr $expr - Callable-array value already vetted by isCallableReference for the `[$this, 'method']` shape.
     *
     * @return string|null - the string in the array's second slot, or null when that slot is not a string literal.
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
     * Returns a display name for the class-like, using a stable placeholder when it is unnamed.
     *
     * @param Class_|Trait_|Enum_ $node - Class-like declaration whose display symbol is needed for finding text.
     *
     * @return string - declared class/trait/enum name, or an `@anonymous`/`unknown@line` placeholder when unnamed.
     */
    private function resolveClassName(Node $node): string
    {
        if ($node instanceof Class_) {
            // Anonymous classes have no name node, so fall back to a stable placeholder.
            return $node->name?->toString() ?? 'class@anonymous';
        }

        // Traits and enums are always named; the line-tagged fallback only guards against malformed input.
        return $node->name?->toString() ?? sprintf('unknown@%d', $node->getStartLine());
    }
}
