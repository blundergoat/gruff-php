<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Waste;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;

/**
 * Detects trivial wrapper methods that only delegate a one-line call.
 */
final readonly class OneLineMethodRule implements RuleInterface
{
    /**
     * Stable identifier for the one-line method rule.
     */
    public const ID = 'waste.one-line-method';

    /**
     * Magic and lifecycle methods ignored even when they are one-line wrappers.
     */
    private const SKIPPED_METHODS = [
        '__construct',
        '__destruct',
        '__clone',
        '__toString',
        '__debugInfo',
        '__get',
        '__set',
        '__isset',
        '__unset',
        '__call',
        '__callStatic',
        '__invoke',
        '__sleep',
        '__wakeup',
        '__serialize',
        '__unserialize',
        '__set_state',
        'setUp',
        'tearDown',
        'setUpBeforeClass',
        'tearDownAfterClass',
    ];

    /**
     * Describe the one-line method rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata, defaults, and options.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'One-line method',
            pillar:          Pillar::Maintainability,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                'minParameters' => 1,
                'minInFileCallers' => 2,
                'namedAlternativeFactoryExempt' => true,
                'allowedSymbols' => [],
            ],
            description: 'Flags trivial methods that only wrap a one-line call expression.',
            optionDescriptions: [
                'minParameters' => 'Minimum parameter count before flagging non-private methods (private zero-arg pass-through helpers can still fire).',
                'minInFileCallers' => 'Skip when the wrapper is called from this many sites in the same file (default 2).',
                'namedAlternativeFactoryExempt' => 'Skip public static factory pairs like Money::fromCents()/fromDollars() that exist for naming clarity.',
                'allowedSymbols' => 'Qualified symbols that intentionally stay thin (API contracts, security helpers); see remediation.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Public API contract methods whose body legitimately delegates to one internal call (e.g. DashboardStateFactory::initialProjectRoot()).',
                    'mitigation' => 'Add the qualified symbol to options.allowedSymbols.',
                ],
                [
                    'shape' => 'Named-alternative factory pairs (Money::fromCents(), Money::fromDollars()) where each factory wraps `new self(...)`.',
                    'mitigation' => 'namedAlternativeFactoryExempt defaults to true; verify both factories return new instances of the same class.',
                ],
            ],
        );
    }

    /**
     * Find trivial methods that only wrap a single call expression.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for one-line wrapper methods.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition         = $this->definition();
        $settings           = $ruleContext->settingsFor($definition);
        $minParameterOption = $settings->option('minParameters');
        $minParameters      = is_int($minParameterOption) ? max(0, $minParameterOption) : 1;
        $minCallersOption   = $settings->option('minInFileCallers');
        $minInFileCallers   = is_int($minCallersOption) ? max(0, $minCallersOption) : 0;
        $factoryExempt      = $settings->option('namedAlternativeFactoryExempt') === true;
        $allowedSymbols     = array_fill_keys($settings->stringListOption('allowedSymbols'), true);
        $nodeFinder         = new NodeFinder();
        $selfCallCounts     = $this->selfCallCountsByClass($analysisUnit, $nodeFinder);
        $factoryMethodIds   = $factoryExempt ? $this->namedAlternativeFactoryMethodIds($analysisUnit) : [];
        $contractMethodIds  = $this->contractMethodIds($analysisUnit);
        $findings           = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, ClassMethod::class) as $classMethod) {
            $enclosingClassId = $this->enclosingClassId($classMethod);
            // User view: missing data becomes the expected findings list state.
            $selfCallerCount  = $enclosingClassId !== null
                // User view: missing data becomes a safe findings list default.
                ? ($selfCallCounts[$enclosingClassId][strtolower($classMethod->name->toString())] ?? 0)
                : 0;

            // User view: choose the findings list branch for this case.
            if ($this->shouldSkip(
                classMethod:      $classMethod,
                minParameters:    $minParameters,
                minInFileCallers: $minInFileCallers,
                selfCallerCount:  $selfCallerCount,
                factoryMethodIds: $factoryMethodIds,
                contractMethodIds: $contractMethodIds,
            )) {
                continue;
            }

            // User view: missing data becomes a safe findings list default.
            $statement = $classMethod->stmts[0] ?? null;
            // User view: choose the findings list branch for this case.
            if (!$statement instanceof Return_ && !$statement instanceof Expression) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($statement->getStartLine() !== $statement->getEndLine()) {
                continue;
            }

            $expression = $statement instanceof Return_ ? $statement->expr : $statement->expr;
            // User view: choose the findings list branch for this case.
            if (!$expression instanceof Expr || !$this->containsCall($expression, $nodeFinder)) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($classMethod);
            // User view: choose the findings list branch for this case.
            if (isset($allowedSymbols[$symbol])) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s only wraps a one-line call expression.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $classMethod->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                endLine:     $classMethod->getEndLine() > 0 ? $classMethod->getEndLine() : null,
                symbol:      $symbol,
                remediation: 'Inline the expression at the call site or expand the method so it owns a meaningful contract. If this method is an intentional API contract, add its qualified symbol to `rules.waste.one-line-method.options.allowedSymbols` in `.gruff-php.yaml`.',
                metadata:    [
                    'method' => $classMethod->name->toString(),
                    'parameterCount' => count($classMethod->params),
                    'statementKind' => $statement instanceof Return_ ? 'return' : 'expression',
                ],
            );
        }

        return $findings;
    }

    /**
     * Decide whether a method shape is exempt from one-line wrapper checks.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod        $classMethod - Candidate whose own param count, single-statement body, and factory membership decide exemption (caller count is supplied separately).
     * @param int                $minParameters - Methods declaring fewer parameters are skipped as zero/low-arg accessors.
     * @param int                $minInFileCallers - Skip threshold: reached this many in-file self-callers exempts the wrapper (0 disables).
     * @param int                $selfCallerCount - Count of in-class `$this->name()` / `self::name()` / `static::name()` / `parent::name()` calls.
     * @param array<int, true>   $factoryMethodIds - Alternative named-factory method object ids.
     * @param array<int, true>   $contractMethodIds - Methods that satisfy a local interface/abstract/trait contract.
     *
     * @return bool - True when the method should not be reported.
     */
    private function shouldSkip(
        ClassMethod $classMethod,
        int $minParameters,
        int $minInFileCallers,
        int $selfCallerCount,
        array $factoryMethodIds,
        array $contractMethodIds,
    ): bool
    {
        $name = $classMethod->name->toString();

        // User view: choose the findings list branch for this case.
        if ($classMethod->isAbstract() || in_array($name, self::SKIPPED_METHODS, true)) {
            // Abstract methods have no body to inline; magic/lifecycle signatures are framework-owned, not wrappers.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if (str_starts_with($name, 'test') || str_starts_with($name, 'provide') || str_ends_with($name, 'Provider')) {
            // Test cases and data providers are scaffolding the rule never treats as delegating wrappers.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if (!$classMethod->isPrivate() && count($classMethod->params) < $minParameters) {
            // Public/protected zero/low-arg methods are commonly accessors or API vocabulary; private helpers are
            // still checked because a private `return SomeFactory::value();` wrapper is often just indirection.
            return true;
        }

        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($classMethod->stmts === null || count($classMethod->stmts) !== 1) {
            // Only an exactly-one-statement body can be the "one-line" call this rule targets.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if (isset($factoryMethodIds[spl_object_id($classMethod)])) {
            // Named-alternative factory pairs are intentional thin constructors, exempt by design.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if (isset($contractMethodIds[spl_object_id($classMethod)]) || $this->hasOverrideAttribute($classMethod)) {
            // Interface, abstract-parent, trait, and explicit Override contracts own the method surface.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if ($this->isIntentBearingPublicApi($classMethod) || $this->hasSemanticBoundaryName($name)) {
            // Public API/data-object methods and domain predicates are named contracts, not low-value indirection.
            return true;
        }

        // Only exempt when configured to honour in-file callers and the method clears that caller count.
        return $minInFileCallers > 0 && $selfCallerCount >= $minInFileCallers;
    }

    /**
     * Walk up from a ClassMethod to its enclosing class-like declaration so caller
     * counts can be scoped by owning class. Returns null when the method is not
     * enclosed by a class/trait/enum (e.g. interface methods, which the rule
     * already skips via `isAbstract()`).
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod $classMethod - Method whose owning class-like declaration we resolve.
     *
     * @return int|null - Object id of the enclosing class-like, or null when unresolved.
     */
    private function enclosingClassId(ClassMethod $classMethod): ?int
    {
        $node = $classMethod->getAttribute('parent');
        while ($node instanceof Node) {
            // User view: choose the findings list branch for this case.
            if ($node instanceof Class_ || $node instanceof Trait_ || $node instanceof Enum_) {
                // First class/trait/enum ancestor owns the method; its id scopes the caller counts.
                return spl_object_id($node);
            }
            $node = $node->getAttribute('parent');
        }

        // No class-like ancestor: the method is interface-level, so it has no caller scope here.
        return null;
    }

    /**
     * Detect whether an expression contains any call or object creation.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr       $expression - Single-statement body expression being classified as real work or not.
     * @param NodeFinder $nodeFinder - Shared finder reused across methods to avoid per-call allocation.
     *
     * @return bool - True when the expression contains callable work.
     */
    private function containsCall(Expr $expression, NodeFinder $nodeFinder): bool
    {
        // A wrapper only counts when its one expression actually delegates work, so look for any call node.
        $call = $nodeFinder->findFirst([$expression], static function (Node $node): bool {
            // Method/static/function calls and `new` are the delegations that make a one-liner a wrapper.
            return $node instanceof Expr\MethodCall
                || $node instanceof Expr\StaticCall
                || $node instanceof Expr\FuncCall
                || $node instanceof Expr\New_;
        });

        // User view: missing data becomes the expected findings list state.
        return $call !== null;
    }

    /**
     * Count in-class self-targeted method calls (`$this->name()`, `self::name()`,
     * `static::name()`, `parent::name()`) keyed by enclosing class object id and
     * lowercase method name. Scoping by class prevents two unrelated classes in
     * the same file from cross-contaminating each other's caller counts — a
     * file with `A::save()` called twice and a separate `B::save()` wrapper
     * with no callers would otherwise see `B::save()` silently exempted under
     * the `minInFileCallers: 2` default.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose class-like declarations are tallied.
     * @param NodeFinder   $nodeFinder - Shared finder reused across methods to avoid per-call allocation.
     *
     * @return array<int, array<string, int>> - Counts keyed by class object id, then lowercase method name.
     */
    private function selfCallCountsByClass(AnalysisUnit $analysisUnit, NodeFinder $nodeFinder): array
    {
        /** @var array<int, array<string, int>> $counts Accumulator shape is built incrementally per class. */
        $counts = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]) as $class) {
            /** @var Class_|Trait_|Enum_ $class NodeIndex query is constrained to these class-like kinds. */
            $classId          = spl_object_id($class);
            $counts[$classId] = [];

            // User view: add each item that can appear in findings list.
            foreach ($class->getMethods() as $method) {
                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
                if ($method->stmts === null) {
                    continue;
                }

                $calls = $nodeFinder->find($method->stmts, static function (Node $node): bool {
                    // User view: choose the findings list branch for this case.
                    if ($node instanceof Expr\MethodCall && $node->var instanceof Expr\Variable && $node->var->name === 'this') {
                        // A `$this->name()` call counts only with a literal method name we can key on.
                        return $node->name instanceof Node\Identifier;
                    }
                    // User view: choose the findings list branch for this case.
                    if ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Node\Identifier) {
                        // self/static/parent calls are still in-class dispatch; dynamic targets are not.
                        return in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true);
                    }

                    // Any other node is not a self-targeted call, so it must not inflate the tally.
                    return false;
                });

                // User view: add each item that can appear in findings list.
                foreach ($calls as $call) {
                    /** @var Expr\MethodCall|Expr\StaticCall $call NodeFinder predicate guarantees the union. */
                    // User view: choose the findings list branch for this case.
                    if (!$call->name instanceof Node\Identifier) {
                        continue;
                    }
                    $name                      = strtolower($call->name->toString());
                    // User view: missing data becomes a safe findings list default.
                    $counts[$classId][$name]   = ($counts[$classId][$name] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * Find public static self-factory methods when a class exposes multiple named alternatives.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose classes are scanned for named-factory pairs.
     *
     * @return array<int, true> - Method object ids that are exempt.
     */
    private function namedAlternativeFactoryMethodIds(AnalysisUnit $analysisUnit): array
    {
        $factoryIds = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Class_::class) as $class) {
            $factories = [];

            // User view: add each item that can appear in findings list.
            foreach ($class->getMethods() as $classMethod) {
                // User view: choose the findings list branch for this case.
                if ($this->isNamedAlternativeFactory($classMethod, $class)) {
                    $factories[] = $classMethod;
                }
            }

            // User view: add each item that can appear in findings list.
            foreach ($factories as $classMethod) {
                $factoryIds[spl_object_id($classMethod)] = true;
            }
        }

        return $factoryIds;
    }

    /**
     * Detect public static methods that return a new instance of their own class.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod $classMethod - Candidate method being tested as a named constructor.
     * @param Class_      $class - Declaring class, used to match `new ClassName()` against its own name.
     *
     * @return bool - True when the method is a named constructor/factory candidate.
     */
    private function isNamedAlternativeFactory(ClassMethod $classMethod, Class_ $class): bool
    {
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if (!$classMethod->isPublic() || !$classMethod->isStatic() || $classMethod->stmts === null || count($classMethod->stmts) !== 1) {
            // A named constructor must be a public static one-liner; anything else cannot be one of the pair.
            return false;
        }

        $statement = $classMethod->stmts[0];
        // User view: choose the findings list branch for this case.
        if (!$statement instanceof Return_ || !$statement->expr instanceof Expr\New_) {
            // The lone statement has to be `return new ...`; without that there is no instance to vend.
            return false;
        }

        $target = $statement->expr->class;
        // User view: choose the findings list branch for this case.
        if (!$target instanceof Name) {
            // A dynamic `new $class()` target cannot be matched to this class statically, so reject it.
            return false;
        }

        $targetName = strtolower($target->toString());
        // User view: choose the findings list branch for this case.
        if ($targetName === 'self' || $targetName === 'static') {
            // `new self`/`new static` always vends the declaring class, so this is a named constructor.
            return true;
        }

        $className = $class->name?->toString();

        // Otherwise it qualifies only when the `new` target spells out this same class by name.
        // User view: missing data becomes the expected findings list state.
        return $className !== null && strtolower($className) === $targetName;
    }

    /**
     * Resolve local methods that satisfy interface, abstract-parent, or trait contracts.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose same-file contracts and implementations are matched.
     *
     * @return array<int, true> - ClassMethod object ids that should not be treated as waste.
     */
    private function contractMethodIds(AnalysisUnit $analysisUnit): array
    {
        $interfaceMethods = $this->interfaceMethodSets($analysisUnit);
        $abstractMethods  = $this->abstractClassMethodSets($analysisUnit);
        $traitMethods     = $this->traitMethodSets($analysisUnit);

        $contractIds = [];
        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Class_::class) as $class) {
            $requiredMethods = $this->requiredContractMethods($class, $interfaceMethods, $abstractMethods, $traitMethods);
            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if ($requiredMethods === []) {
                continue;
            }

            // User view: add each item that can appear in findings list.
            foreach ($class->getMethods() as $classMethod) {
                // User view: choose the findings list branch for this case.
                if (isset($requiredMethods[strtolower($classMethod->name->toString())])) {
                    $contractIds[spl_object_id($classMethod)] = true;
                }
            }
        }

        return $contractIds;
    }

    /**
     * Map same-file interface names to their declared method-name sets.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose interface declarations are indexed.
     *
     * @return array<string, array<string, true>> - Lowercase interface name to its lowercase method-name set.
     */
    private function interfaceMethodSets(AnalysisUnit $analysisUnit): array
    {
        $sets = [];
        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Interface_::class) as $interface) {
            $name = $interface->name?->toString();
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name !== null) {
                $sets[strtolower($name)] = $this->methodNameSet($interface);
            }
        }

        return $sets;
    }

    /**
     * Map same-file abstract class names to their abstract method-name sets.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose abstract class declarations are indexed.
     *
     * @return array<string, array<string, true>> - Lowercase abstract class name to its lowercase abstract method-name set.
     */
    private function abstractClassMethodSets(AnalysisUnit $analysisUnit): array
    {
        $sets = [];
        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Class_::class) as $class) {
            $name = $class->name?->toString();
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name !== null && $class->isAbstract()) {
                $sets[strtolower($name)] = $this->abstractMethodNameSet($class);
            }
        }

        return $sets;
    }

    /**
     * Map same-file trait names to their abstract method-name sets.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose trait declarations are indexed.
     *
     * @return array<string, array<string, true>> - Lowercase trait name to its lowercase abstract method-name set.
     */
    private function traitMethodSets(AnalysisUnit $analysisUnit): array
    {
        $sets = [];
        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Trait_::class) as $trait) {
            $name = $trait->name?->toString();
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name !== null) {
                $sets[strtolower($name)] = $this->abstractMethodNameSet($trait);
            }
        }

        return $sets;
    }

    /**
     * Resolve the contract method names a class must define via its interfaces, abstract parent, and traits.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Class_                             $class - Class declaration whose implemented contracts are resolved.
     * @param array<string, array<string, true>> $interfaceMethods - Interface name to method-name set.
     * @param array<string, array<string, true>> $abstractMethods - Abstract class name to abstract method-name set.
     * @param array<string, array<string, true>> $traitMethods - Trait name to abstract method-name set.
     *
     * @return array<string, true> - Lowercase method names the class is contractually required to define.
     */
    private function requiredContractMethods(Class_ $class, array $interfaceMethods, array $abstractMethods, array $traitMethods): array
    {
        $requiredMethods = [];

        // User view: add each item that can appear in findings list.
        foreach ($class->implements as $interfaceName) {
            // User view: missing data becomes a safe findings list default.
            $requiredMethods += $interfaceMethods[strtolower($interfaceName->getLast())] ?? [];
        }

        // User view: choose the findings list branch for this case.
        if ($class->extends instanceof Name) {
            // User view: missing data becomes a safe findings list default.
            $requiredMethods += $abstractMethods[strtolower($class->extends->getLast())] ?? [];
        }

        // User view: add each item that can appear in findings list.
        foreach ($class->stmts as $stmt) {
            // User view: choose the findings list branch for this case.
            if (!$stmt instanceof TraitUse) {
                continue;
            }

            // User view: add each item that can appear in findings list.
            foreach ($stmt->traits as $traitName) {
                // User view: missing data becomes a safe findings list default.
                $requiredMethods += $traitMethods[strtolower($traitName->getLast())] ?? [];
            }
        }

        return $requiredMethods;
    }

    /**
     * Collect method names declared by a class-like contract.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassLike $classLike - Interface, trait, or abstract class declaration.
     *
     * @return array<string, true> - Lowercase method names.
     */
    private function methodNameSet(ClassLike $classLike): array
    {
        $names = [];

        // User view: add each item that can appear in findings list.
        foreach ($classLike->getMethods() as $method) {
            $names[strtolower($method->name->toString())] = true;
        }

        return $names;
    }

    /**
     * Collect abstract method names declared by a class-like contract.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassLike $classLike - Trait or abstract class declaration.
     *
     * @return array<string, true> - Lowercase abstract method names.
     */
    private function abstractMethodNameSet(ClassLike $classLike): array
    {
        $names = [];

        // User view: add each item that can appear in findings list.
        foreach ($classLike->getMethods() as $method) {
            // User view: choose the findings list branch for this case.
            if ($method->isAbstract()) {
                $names[strtolower($method->name->toString())] = true;
            }
        }

        return $names;
    }

    /**
     * Detect PHP's explicit override marker without depending on resolved names.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod $classMethod - Method whose attributes are scanned.
     *
     * @return bool - True when the method declares an Override attribute.
     */
    private function hasOverrideAttribute(ClassMethod $classMethod): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ($classMethod->attrGroups as $attributeGroup) {
            // User view: add each item that can appear in findings list.
            foreach ($attributeGroup->attrs as $attribute) {
                // User view: choose the findings list branch for this case.
                if (strtolower($attribute->name->getLast()) === 'override') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect public API shapes where the method name is the contract.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod $classMethod - Candidate one-line method.
     *
     * @return bool - True when a public/protected method should be treated as intentional API.
     */
    private function isIntentBearingPublicApi(ClassMethod $classMethod): bool
    {
        // User view: choose the findings list branch for this case.
        if ($classMethod->isPrivate()) {
            return false;
        }

        $parent = $classMethod->getAttribute('parent');
        // User view: choose the findings list branch for this case.
        if ($parent instanceof Class_ && ($parent->isReadonly() || $this->hasDataCarrierName($parent))) {
            return true;
        }

        // User view: missing data becomes a safe findings list default.
        $statement = $classMethod->stmts[0] ?? null;
        // User view: choose the findings list branch for this case.
        if ($classMethod->isStatic() && $classMethod->isPublic() && $statement instanceof Return_ && $statement->expr instanceof Expr\New_) {
            return true;
        }

        return false;
    }

    /**
     * Detect class names commonly used for immutable/read-model data carriers.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Class_ $class - Enclosing class declaration.
     *
     * @return bool - True when the class name signals a data-carrier public API.
     */
    private function hasDataCarrierName(Class_ $class): bool
    {
        // User view: missing data becomes a safe findings list default.
        $name = $class->name?->toString() ?? '';

        // User view: add each item that can appear in findings list.
        foreach (['Dto', 'DTO', 'ReadModel', 'ValueObject', 'Payload', 'Session', 'Envelope'] as $suffix) {
            // User view: choose the findings list branch for this case.
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect method names that add domain vocabulary or mark a boundary.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $name - Method name.
     *
     * @return bool - True when the wrapper's name communicates a predicate or transformation boundary.
     */
    private function hasSemanticBoundaryName(string $name): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ([
            'is', 'has', 'can', 'should', 'supports', 'allows', 'contains', 'matches',
            'requires', 'uses', 'accepts', 'permits', 'normalise', 'normalize', 'hydrate',
            'parse', 'cast', 'coerce', 'build', 'create', 'from', 'to',
        ] as $prefix) {
            // User view: choose the findings list branch for this case.
            if (str_starts_with($name, $prefix) && (strlen($name) === strlen($prefix) || ctype_upper($name[strlen($prefix)]))) {
                return true;
            }
        }

        return false;
    }
}
