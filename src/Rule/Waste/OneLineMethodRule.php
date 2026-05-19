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
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
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
                'minInFileCallers' => 0,
                'namedAlternativeFactoryExempt' => false,
                'allowedSymbols' => [],
            ],
            description: 'Flags trivial methods that only wrap a one-line call expression.',
        );
    }

    /**
     * Find trivial methods that only wrap a single call expression.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
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
        $methodCallCounts   = $this->methodCallCounts($analysisUnit->statements, $nodeFinder);
        $factoryMethodIds   = $factoryExempt ? $this->namedAlternativeFactoryMethodIds($analysisUnit->statements, $nodeFinder) : [];
        $findings           = [];

        foreach ($nodeFinder->findInstanceOf($analysisUnit->statements, ClassMethod::class) as $method) {
            if ($this->shouldSkip(
                method:           $method,
                minParameters:    $minParameters,
                minInFileCallers: $minInFileCallers,
                methodCallCounts: $methodCallCounts,
                factoryMethodIds: $factoryMethodIds,
            )) {
                continue;
            }

            $statement = $method->stmts[0] ?? null;
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

            $symbol = CyclomaticComplexityRule::resolveSymbol($method);
            if (isset($allowedSymbols[$symbol])) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s only wraps a one-line call expression.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $method->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                endLine:     $method->getEndLine() > 0 ? $method->getEndLine() : null,
                symbol:      $symbol,
                remediation: 'Inline the expression at the call site or expand the method so it owns a meaningful contract.',
                metadata:    [
                    'method' => $method->name->toString(),
                    'parameterCount' => count($method->params),
                    'statementKind' => $statement instanceof Return_ ? 'return' : 'expression',
                ],
            );
        }

        return $findings;
    }

    /**
     * Decide whether a method shape is exempt from one-line wrapper checks.
     *
     * @param array<string, int> $methodCallCounts Method-call counts keyed by lowercase method name.
     * @param array<int, true>   $factoryMethodIds Alternative named-factory method object ids.
     *
     * @return bool True when the method should not be reported.
     */
    private function shouldSkip(
        ClassMethod $method,
        int $minParameters,
        int $minInFileCallers,
        array $methodCallCounts,
        array $factoryMethodIds,
    ): bool
    {
        $name = $method->name->toString();

        if ($method->isAbstract() || in_array($name, self::SKIPPED_METHODS, true)) {
            return true;
        }

        if (str_starts_with($name, 'test') || str_starts_with($name, 'provide') || str_ends_with($name, 'Provider')) {
            return true;
        }

        if (count($method->params) < $minParameters || $method->stmts === null || count($method->stmts) !== 1) {
            return true;
        }

        if (isset($factoryMethodIds[spl_object_id($method)])) {
            return true;
        }

        return $minInFileCallers > 0 && ($methodCallCounts[strtolower($name)] ?? 0) >= $minInFileCallers;
    }

    /**
     * Detect whether an expression contains any call or object creation.
     *
     * @return bool True when the expression contains callable work.
     */
    private function containsCall(Expr $expression, NodeFinder $finder): bool
    {
        return $finder->findFirst([$expression], static function (Node $node): bool {
            return $node instanceof Expr\MethodCall
                || $node instanceof Expr\StaticCall
                || $node instanceof Expr\FuncCall
                || $node instanceof Expr\New_;
        }) !== null;
    }

    /**
     * Count method and static-call names inside the current file.
     *
     * @param list<Node> $statements Parsed statements to inspect.
     * @return array<string, int> Counts keyed by lowercase method name.
     */
    private function methodCallCounts(array $statements, NodeFinder $finder): array
    {
        $counts = [];

        foreach ($finder->find($statements, static fn (Node $node): bool => $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) as $call) {
            if (!$call instanceof Expr\MethodCall && !$call instanceof Expr\StaticCall) {
                continue;
            }

            if (!$call->name instanceof Node\Identifier) {
                continue;
            }

            $name          = strtolower($call->name->toString());
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Find public static self-factory methods when a class exposes multiple named alternatives.
     *
     * @param list<Node> $statements Parsed statements to inspect.
     * @return array<int, true> Method object ids that are exempt.
     */
    private function namedAlternativeFactoryMethodIds(array $statements, NodeFinder $finder): array
    {
        $factoryIds = [];

        foreach ($finder->findInstanceOf($statements, Class_::class) as $class) {
            $factories = [];

            foreach ($class->getMethods() as $method) {
                if ($this->isNamedAlternativeFactory($method, $class)) {
                    $factories[] = $method;
                }
            }

            if (count($factories) < 2) {
                continue;
            }

            foreach ($factories as $method) {
                $factoryIds[spl_object_id($method)] = true;
            }
        }

        return $factoryIds;
    }

    /**
     * Detect public static methods that return a new instance of their own class.
     *
     * @return bool True when the method is a named constructor/factory candidate.
     */
    private function isNamedAlternativeFactory(ClassMethod $method, Class_ $class): bool
    {
        if (!$method->isPublic() || !$method->isStatic() || $method->stmts === null || count($method->stmts) !== 1) {
            return false;
        }

        $statement = $method->stmts[0];
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
