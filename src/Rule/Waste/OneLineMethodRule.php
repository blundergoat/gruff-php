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
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                'minParameters' => 1,
            ],
            description: 'Flags trivial methods that only wrap a one-line call expression.',
        );
    }

    /**
     * Find trivial methods that only wrap a single call expression.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for one-line wrapper methods.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition         = $this->definition();
        $settings           = $context->settingsFor($definition);
        $minParameterOption = $settings->option('minParameters');
        $minParameters      = is_int($minParameterOption) ? max(0, $minParameterOption) : 1;
        $finder             = new NodeFinder();
        $findings           = [];

        foreach ($finder->findInstanceOf($unit->statements, ClassMethod::class) as $method) {
            if ($this->shouldSkip($method, $minParameters)) {
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
            if (!$expression instanceof Expr || !$this->containsCall($expression, $finder)) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($method);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s only wraps a one-line call expression.', $symbol),
                filePath:    $unit->file->displayPath,
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
     * @return bool True when the method should not be reported.
     */
    private function shouldSkip(ClassMethod $method, int $minParameters): bool
    {
        $name = $method->name->toString();

        if ($method->isAbstract() || in_array($name, self::SKIPPED_METHODS, true)) {
            return true;
        }

        if (str_starts_with($name, 'test') || str_starts_with($name, 'provide') || str_ends_with($name, 'Provider')) {
            return true;
        }

        return count($method->params) < $minParameters || $method->stmts === null || count($method->stmts) !== 1;
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
}
