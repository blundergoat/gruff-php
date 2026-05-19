<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Complexity;

use GruffPhp\Config\SeverityThreshold;
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

/**
 * Measures function-like information density with Halstead metrics.
 */
final readonly class HalsteadVolumeRule implements RuleInterface
{
    /**
     * Stable rule identifier for Halstead volume findings.
     */
    public const ID = 'complexity.halstead-volume';

    /**
     * Describe the Halstead-volume rule for the registry and reports.
     *
     * @return RuleDefinition Rule metadata and default thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Halstead volume',
            pillar:            Pillar::Complexity,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::Medium,
            severityThreshold: new SeverityThreshold(8000, Severity::Error),
        );
    }

    /**
     * Detect functions and methods whose Halstead volume exceeds configured thresholds.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Halstead-volume findings for the analysed unit.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodeFinder = new NodeFinder();
        $nodes      = $nodeFinder->find($analysisUnit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod
                || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $metrics        = self::computeHalsteadMetrics($node);
            $volume         = $metrics['volume'];
            $thresholdMatch = $settings->highValueThresholdMatch($volume);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has a Halstead volume of %.1f, above the %s threshold of %s.',
                    $symbol,
                    $volume,
                    $thresholdMatch->severity->value,
                    self::formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $node->getStartLine(),
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol:           $symbol,
                remediation:      'Simplify logic or extract sub-expressions to reduce information content.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'volume' => round($volume, 1),
                    'difficulty' => round($metrics['difficulty'], 1),
                    'effort' => round($metrics['effort'], 1),
                    'vocabulary' => $metrics['vocabulary'],
                    'length' => $metrics['length'],
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node
     * @return array{volume: float, difficulty: float, effort: float, vocabulary: int, length: int}
     */
    public static function computeHalsteadMetrics(Node $node): array
    {
        static $metricsCache = null;
        if (!$metricsCache instanceof \WeakMap) {
            $metricsCache = new \WeakMap();
        }

        if (isset($metricsCache[$node])) {
            $cached = self::validatedMetrics($metricsCache[$node]);
            if ($cached !== null) {
                return $cached;
            }
        }

        $operators      = [];
        $operands       = [];
        $totalOperators = 0;
        $totalOperands  = 0;

        $nodeFinder      = new NodeFinder();
        $descendantNodes = $nodeFinder->find($node->stmts ?? [], static fn (): bool => true);

        foreach ($descendantNodes as $childNode) {
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

        $metrics             = self::metricsForCounts(count($operators), count($operands), $totalOperators, $totalOperands);
        $metricsCache[$node] = $metrics;

        return $metrics;
    }

    /**
     * @return array{volume: float, difficulty: float, effort: float, vocabulary: int, length: int}|null
     */
    private static function validatedMetrics(mixed $rawMetrics): ?array
    {
        if (!is_array($rawMetrics)) {
            return null;
        }

        $volume     = $rawMetrics['volume'] ?? null;
        $difficulty = $rawMetrics['difficulty'] ?? null;
        $effort     = $rawMetrics['effort'] ?? null;
        $vocabulary = $rawMetrics['vocabulary'] ?? null;
        $length     = $rawMetrics['length'] ?? null;

        if (!is_float($volume) || !is_float($difficulty) || !is_float($effort) || !is_int($vocabulary) || !is_int($length)) {
            return null;
        }

        return [
            'volume' => $volume,
            'difficulty' => $difficulty,
            'effort' => $effort,
            'vocabulary' => $vocabulary,
            'length' => $length,
        ];
    }

    /**
     * Classify a node as a Halstead operator when it contributes executable structure.
     *
     * @return string|null Stable operator key, or null when the node is not an operator.
     */
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

    /**
     * Classify a node as a Halstead operand when it contributes a value reference.
     *
     * @return string|null Stable operand key, or null when the node is not an operand.
     */
    private static function operandKey(Node $node): ?string
    {
        return match (true) {
            $node instanceof Expr\Variable => is_string($node->name) ? '$' . $node->name : null,
            $node instanceof Node\Scalar => 'scalar:' . $node::class,
            $node instanceof Node\Param => self::parameterOperandKey($node),
            default => null,
        };
    }

    /**
     * Build the operand key for a function or method parameter.
     *
     * @return string|null Parameter operand key, or null for unsupported parameter shapes.
     */
    private static function parameterOperandKey(Node\Param $parameter): ?string
    {
        if (!$parameter->var instanceof Expr\Variable) {
            return null;
        }

        return is_string($parameter->var->name) ? '$' . $parameter->var->name : null;
    }

    /**
     * @return array{volume: float, difficulty: float, effort: float, vocabulary: int, length: int}
     */
    private static function metricsForCounts(int $uniqueOperators, int $uniqueOperands, int $totalOperators, int $totalOperands): array
    {
        $length     = $totalOperators + $totalOperands;
        $vocabulary = $uniqueOperators + $uniqueOperands;

        if ($vocabulary === 0 || $uniqueOperands === 0 || $totalOperands === 0) {
            return ['volume' => 0.0, 'difficulty' => 0.0, 'effort' => 0.0, 'vocabulary' => 0, 'length' => 0];
        }

        $volume     = $length * log($vocabulary, 2);
        $difficulty = ($uniqueOperators / 2) * ($totalOperands / $uniqueOperands);

        return [
            'volume' => $volume,
            'difficulty' => $difficulty,
            'effort' => $volume * $difficulty,
            'vocabulary' => $vocabulary,
            'length' => $length,
        ];
    }

    /**
     * Render a configured numeric threshold for finding messages.
     *
     * @return string The threshold without unnecessary decimal places.
     */
    private static function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
