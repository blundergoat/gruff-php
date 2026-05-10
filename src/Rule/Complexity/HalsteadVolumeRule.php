<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Complexity;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

final readonly class HalsteadVolumeRule implements RuleInterface
{
    public const ID = 'complexity.halstead-volume';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Halstead volume',
            pillar: Pillar::Complexity,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
            defaultThresholds: [
                'warning' => 1000,
                'error' => 2000,
            ],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings = $context->settingsFor($definition);
        $warningThreshold = $settings->numericThreshold('warning');
        $errorThreshold = $settings->numericThreshold('error');

        $finder = new NodeFinder();
        $nodes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod
                || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node */
            $metrics = self::compute($node);
            $volume = $metrics['volume'];

            if ($volume <= $warningThreshold) {
                continue;
            }

            $severity = $volume > $errorThreshold ? Severity::Error : Severity::Warning;
            $threshold = $severity === Severity::Error ? $errorThreshold : $warningThreshold;
            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    '%s has a Halstead volume of %.1f, above the %s threshold of %s.',
                    $symbol,
                    $volume,
                    $severity->value,
                    self::formatNumber($threshold),
                ),
                filePath: $unit->file->displayPath,
                line: $node->getStartLine(),
                severity: $severity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                endLine: $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol: $symbol,
                remediation: 'Simplify logic or extract sub-expressions to reduce information content.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'volume' => round($volume, 1),
                    'difficulty' => round($metrics['difficulty'], 1),
                    'effort' => round($metrics['effort'], 1),
                    'vocabulary' => $metrics['vocabulary'],
                    'length' => $metrics['length'],
                    'threshold' => $threshold,
                    'thresholdType' => $severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node
     * @return array{volume: float, difficulty: float, effort: float, vocabulary: int, length: int}
     */
    public static function compute(Node $node): array
    {
        $operators = [];
        $operands = [];
        $totalOperators = 0;
        $totalOperands = 0;

        $finder = new NodeFinder();
        $all = $finder->find($node->stmts ?? [], static fn (): bool => true);

        foreach ($all as $childNode) {
            $operatorKey = self::operatorKey($childNode);
            if ($operatorKey !== null) {
                $operators[$operatorKey] = true;
                $totalOperators++;
            }

            $operandKey = self::operandKey($childNode);
            if ($operandKey !== null) {
                $operands[$operandKey] = true;
                $totalOperands++;
            }
        }

        return self::metricsForCounts(count($operators), count($operands), $totalOperators, $totalOperands);
    }

    private static function operatorKey(Node $node): ?string
    {
        return match (true) {
            $node instanceof BinaryOp,
            $node instanceof Expr\AssignOp,
            $node instanceof Expr\Assign,
            $node instanceof Stmt\If_,
            $node instanceof Stmt\For_,
            $node instanceof Stmt\Foreach_,
            $node instanceof Stmt\While_,
            $node instanceof Stmt\Do_,
            $node instanceof Stmt\Switch_,
            $node instanceof Stmt\Catch_,
            $node instanceof Stmt\Return_ => $node::class,
            default => null,
        };
    }

    private static function operandKey(Node $node): ?string
    {
        return match (true) {
            $node instanceof Expr\Variable => self::variableOperandKey($node),
            $node instanceof Node\Scalar => 'scalar:' . $node::class,
            $node instanceof Node\Param => self::parameterOperandKey($node),
            default => null,
        };
    }

    private static function variableOperandKey(Expr\Variable $variable): ?string
    {
        return is_string($variable->name) ? '$' . $variable->name : null;
    }

    private static function parameterOperandKey(Node\Param $parameter): ?string
    {
        if (!$parameter->var instanceof Expr\Variable) {
            return null;
        }

        return self::variableOperandKey($parameter->var);
    }

    /**
     * @return array{volume: float, difficulty: float, effort: float, vocabulary: int, length: int}
     */
    private static function metricsForCounts(int $uniqueOperators, int $uniqueOperands, int $totalOperators, int $totalOperands): array
    {
        $length = $totalOperators + $totalOperands;
        $vocabulary = $uniqueOperators + $uniqueOperands;

        if ($vocabulary === 0 || $uniqueOperands === 0 || $totalOperands === 0) {
            return ['volume' => 0.0, 'difficulty' => 0.0, 'effort' => 0.0, 'vocabulary' => 0, 'length' => 0];
        }

        $volume = $length * log($vocabulary, 2);
        $difficulty = ($uniqueOperators / 2) * ($totalOperands / $uniqueOperands);

        return [
            'volume' => $volume,
            'difficulty' => $difficulty,
            'effort' => $volume * $difficulty,
            'vocabulary' => $vocabulary,
            'length' => $length,
        ];
    }

    private static function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
