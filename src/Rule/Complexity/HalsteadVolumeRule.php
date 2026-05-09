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

        $body = $node->stmts ?? [];
        $finder = new NodeFinder();

        $all = $finder->find($body, static fn (): bool => true);

        foreach ($all as $child) {
            if ($child instanceof BinaryOp
                || $child instanceof Expr\AssignOp
                || $child instanceof Expr\Assign
            ) {
                $key = $child::class;
                $operators[$key] = true;
                $totalOperators++;
            }

            if ($child instanceof Stmt\If_
                || $child instanceof Stmt\For_
                || $child instanceof Stmt\Foreach_
                || $child instanceof Stmt\While_
                || $child instanceof Stmt\Do_
                || $child instanceof Stmt\Switch_
                || $child instanceof Stmt\Catch_
                || $child instanceof Stmt\Return_
            ) {
                $key = $child::class;
                $operators[$key] = true;
                $totalOperators++;
            }

            if ($child instanceof Expr\Variable) {
                $name = $child->name;

                if (is_string($name)) {
                    $operands['$' . $name] = true;
                    $totalOperands++;
                }
            }

            if ($child instanceof Node\Scalar) {
                $key = 'scalar:' . $child::class;
                $operands[$key] = true;
                $totalOperands++;
            }

            if ($child instanceof Node\Param && $child->var instanceof Expr\Variable && is_string($child->var->name)) {
                $operands['$' . $child->var->name] = true;
                $totalOperands++;
            }
        }

        $n1 = count($operators);
        $n2 = count($operands);
        $bigN1 = $totalOperators;
        $bigN2 = $totalOperands;
        $bigN = $bigN1 + $bigN2;
        $n = $n1 + $n2;

        if ($n === 0 || $n2 === 0 || $bigN2 === 0) {
            return ['volume' => 0.0, 'difficulty' => 0.0, 'effort' => 0.0, 'vocabulary' => 0, 'length' => 0];
        }

        $volume = $bigN * log($n, 2);
        $difficulty = ($n1 / 2) * ($bigN2 / $n2);
        $effort = $volume * $difficulty;

        return [
            'volume' => $volume,
            'difficulty' => $difficulty,
            'effort' => $effort,
            'vocabulary' => $n,
            'length' => $bigN,
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
