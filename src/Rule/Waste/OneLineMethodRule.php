<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Waste;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Trait_;
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
     * @return RuleDefinition Rule metadata, defaults, and options.
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
                'minParameters' => 'Minimum parameter count before flagging (skips zero-arg accessors).',
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for one-line wrapper methods.
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
        $findings           = [];

        foreach (NodeIndex::nodesOf($analysisUnit, ClassMethod::class) as $classMethod) {
            $enclosingClassId = $this->enclosingClassId($classMethod);
            $selfCallerCount  = $enclosingClassId !== null
                ? ($selfCallCounts[$enclosingClassId][strtolower($classMethod->name->toString())] ?? 0)
                : 0;

            if ($this->shouldSkip(
                classMethod:      $classMethod,
                minParameters:    $minParameters,
                minInFileCallers: $minInFileCallers,
                selfCallerCount:  $selfCallerCount,
                factoryMethodIds: $factoryMethodIds,
            )) {
                continue;
            }

            $statement = $classMethod->stmts[0] ?? null;
            if (!$statement instanceof Return_ && !$statement instanceof Expression) {
                continue;
            }

            if ($statement->getStartLine() !== $statement->getEndLine()) {
                continue;
            }

            $expression = $statement instanceof Return_ ? $statement->expr : $statement->expr;
            if (!$expression instanceof Expr || !$this->containsCall($expression, $nodeFinder)) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($classMethod);
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
     * @param int                $selfCallerCount  Count of in-class `$this->name()` / `self::name()` / `static::name()` / `parent::name()` calls.
     * @param array<int, true>   $factoryMethodIds Alternative named-factory method object ids.
     *
     * @return bool True when the method should not be reported.
     */
    private function shouldSkip(
        ClassMethod $classMethod,
        int $minParameters,
        int $minInFileCallers,
        int $selfCallerCount,
        array $factoryMethodIds,
    ): bool
    {
        $name = $classMethod->name->toString();

        if ($classMethod->isAbstract() || in_array($name, self::SKIPPED_METHODS, true)) {
            return true;
        }

        if (str_starts_with($name, 'test') || str_starts_with($name, 'provide') || str_ends_with($name, 'Provider')) {
            return true;
        }

        if (count($classMethod->params) < $minParameters || $classMethod->stmts === null || count($classMethod->stmts) !== 1) {
            return true;
        }

        if (isset($factoryMethodIds[spl_object_id($classMethod)])) {
            return true;
        }

        return $minInFileCallers > 0 && $selfCallerCount >= $minInFileCallers;
    }

    /**
     * Walk up from a ClassMethod to its enclosing class-like declaration so caller
     * counts can be scoped by owning class. Returns null when the method is not
     * enclosed by a class/trait/enum (e.g. interface methods, which the rule
     * already skips via `isAbstract()`).
     *
     * @return int|null Object id of the enclosing class-like, or null when unresolved.
     */
    private function enclosingClassId(ClassMethod $classMethod): ?int
    {
        $node = $classMethod->getAttribute('parent');
        while ($node instanceof Node) {
            if ($node instanceof Class_ || $node instanceof Trait_ || $node instanceof Enum_) {
                return spl_object_id($node);
            }
            $node = $node->getAttribute('parent');
        }

        return null;
    }

    /**
     * Detect whether an expression contains any call or object creation.
     *
     * @return bool True when the expression contains callable work.
     */
    private function containsCall(Expr $expression, NodeFinder $nodeFinder): bool
    {
        return $nodeFinder->findFirst([$expression], static function (Node $node): bool {
            return $node instanceof Expr\MethodCall
                || $node instanceof Expr\StaticCall
                || $node instanceof Expr\FuncCall
                || $node instanceof Expr\New_;
        }) !== null;
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
     * @return array<int, array<string, int>> Counts keyed by class object id, then lowercase method name.
     */
    private function selfCallCountsByClass(AnalysisUnit $analysisUnit, NodeFinder $nodeFinder): array
    {
        /** @var array<int, array<string, int>> $counts Accumulator shape is built incrementally per class. */
        $counts = [];

        foreach (NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]) as $class) {
            /** @var Class_|Trait_|Enum_ $class NodeIndex query is constrained to these class-like kinds. */
            $classId          = spl_object_id($class);
            $counts[$classId] = [];

            foreach ($class->getMethods() as $method) {
                if ($method->stmts === null) {
                    continue;
                }

                $calls = $nodeFinder->find($method->stmts, static function (Node $node): bool {
                    if ($node instanceof Expr\MethodCall && $node->var instanceof Expr\Variable && $node->var->name === 'this') {
                        return $node->name instanceof Node\Identifier;
                    }
                    if ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Node\Identifier) {
                        return in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true);
                    }

                    return false;
                });

                foreach ($calls as $call) {
                    /** @var Expr\MethodCall|Expr\StaticCall $call NodeFinder predicate guarantees the union. */
                    if (!$call->name instanceof Node\Identifier) {
                        continue;
                    }
                    $name                      = strtolower($call->name->toString());
                    $counts[$classId][$name]   = ($counts[$classId][$name] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * Find public static self-factory methods when a class exposes multiple named alternatives.
     *
     * @return array<int, true> Method object ids that are exempt.
     */
    private function namedAlternativeFactoryMethodIds(AnalysisUnit $analysisUnit): array
    {
        $factoryIds = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Class_::class) as $class) {
            $factories = [];

            foreach ($class->getMethods() as $classMethod) {
                if ($this->isNamedAlternativeFactory($classMethod, $class)) {
                    $factories[] = $classMethod;
                }
            }

            if (count($factories) < 2) {
                continue;
            }

            foreach ($factories as $classMethod) {
                $factoryIds[spl_object_id($classMethod)] = true;
            }
        }

        return $factoryIds;
    }

    /**
     * Detect public static methods that return a new instance of their own class.
     *
     * @return bool True when the method is a named constructor/factory candidate.
     */
    private function isNamedAlternativeFactory(ClassMethod $classMethod, Class_ $class): bool
    {
        if (!$classMethod->isPublic() || !$classMethod->isStatic() || $classMethod->stmts === null || count($classMethod->stmts) !== 1) {
            return false;
        }

        $statement = $classMethod->stmts[0];
        if (!$statement instanceof Return_ || !$statement->expr instanceof Expr\New_) {
            return false;
        }

        $target = $statement->expr->class;
        if (!$target instanceof Name) {
            return false;
        }

        $targetName = strtolower($target->toString());
        if ($targetName === 'self' || $targetName === 'static') {
            return true;
        }

        $className = $class->name?->toString();

        return $className !== null && strtolower($className) === $targetName;
    }
}
