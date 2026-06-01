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
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

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
     * @return RuleDefinition - identity, pillar, tier, and the default advisory volume threshold the registry reads
     */
    public function definition(): RuleDefinition
    {
        // Advisory: high volume is a weigh-it signal, not proof of a defect, so it ships at the lowest severity tier
        // and sorts below warnings/errors. The shipped --fail-on default is advisory, so this does fail the gate;
        // a consumer who wants it non-blocking raises --fail-on to warning.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Halstead volume',
            pillar:            Pillar::Complexity,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Medium,
            severityThreshold: new SeverityThreshold(8000, Severity::Advisory),
        );
    }

    /**
     * Detect functions and methods whose Halstead volume exceeds configured thresholds.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per function-like node over threshold, empty when every node stayed under
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node NodeIndex query is constrained to function-like classes. */
            $metrics        = self::computeHalsteadMetrics($node);
            $volume         = $metrics['volume'];
            $thresholdMatch = $settings->highValueThresholdMatch($volume);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:           $definition->id,
                message:          sprintf(
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
                                      'volume'        => round($volume, 1),
                                      'difficulty'    => round($metrics['difficulty'], 1),
                                      'effort'        => round($metrics['effort'], 1),
                                      'vocabulary'    => $metrics['vocabulary'],
                                      'length'        => $metrics['length'],
                                      'threshold'     => $thresholdMatch->threshold,
                                      'thresholdType' => $thresholdMatch->severity->value,
                                  ],
            );
        }

        // One finding per function-like node that breached a threshold; empty when every node stayed under it.
        return $findings;
    }

    /**
     * Compute Halstead volume inputs for one function-like node.
     *
     * @param ClassMethod|Function_ $node
     *
     * @return array{volume: float, difficulty: float, effort: float, vocabulary: int, length: int} - the full Halstead set for the node; a trivial
     *                       body yields all-zero figures that trip no threshold
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
                // Reuse the prior result for this node; the maintainability rule re-asks for the same metrics.
                return $cached;
            }
        }

        $operators      = [];
        $operands       = [];
        $totalOperators = 0;
        $totalOperands  = 0;

        foreach (NodeIndex::bodyDescendants($node) as $childNode) {
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

        // Freshly computed metrics, now cached against this node for the next caller.
        return $metrics;
    }

    /**
     * Return Halstead metrics only when enough operands and operators exist.
     *
     * @param mixed $rawMetrics Value previously stored in the WeakMap cache; trusted to be a metrics array but
     *                          re-validated because the cache is untyped and a malformed entry must be recomputed.
     *
     * @return array{volume: float, difficulty: float, effort: float, vocabulary: int, length: int}|null - the validated metrics, or null when the
     *                       cache entry is missing a field or mistyped and must be recomputed
     */
    private static function validatedMetrics(mixed $rawMetrics): ?array
    {
        if (!is_array($rawMetrics)) {
            // Cache entry is not even an array; null tells the caller to recompute from scratch.
            return null;
        }

        $volume     = $rawMetrics['volume'] ?? null;
        $difficulty = $rawMetrics['difficulty'] ?? null;
        $effort     = $rawMetrics['effort'] ?? null;
        $vocabulary = $rawMetrics['vocabulary'] ?? null;
        $length     = $rawMetrics['length'] ?? null;

        if (!is_float($volume) || !is_float($difficulty) || !is_float($effort) || !is_int($vocabulary) || !is_int($length)) {
            // A field is missing or mistyped, so the entry is unusable; null forces a recompute.
            return null;
        }

        // Every field passed its type check, so rebuild the shape the type signature promises.
        return [
            'volume'     => $volume,
            'difficulty' => $difficulty,
            'effort'     => $effort,
            'vocabulary' => $vocabulary,
            'length'     => $length,
        ];
    }

    /**
     * Classify a node as a Halstead operator when it contributes executable structure.
     *
     * @param Node $node Any AST node visited while walking the body; only control-flow and operator nodes count.
     *
     * @return string|null - operator class name keying each distinct operator kind, or null when the node is not an operator
     */
    private static function operatorKey(Node $node): ?string
    {
        // Binary/assign ops and control-flow statements are the operators; the class name keys each distinct kind.
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
     * @param Node $node Any AST node visited while walking the body; only variables, scalars, and params count.
     *
     * @return string|null - operand key collapsing repeats of the same value, or null when the node is not an operand
     */
    private static function operandKey(Node $node): ?string
    {
        // Operands are named variables, scalar literals, and params; the key collapses repeats of the same value.
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
     * @param Node\Param $parameter Declared parameter; only a plain `$name` variable yields a key, so destructured
     *                              or expression-named params are skipped.
     *
     * @return string|null - the `$name` operand key, or null for destructured or dynamic-named params with no name
     */
    private static function parameterOperandKey(Node\Param $parameter): ?string
    {
        if (!$parameter->var instanceof Expr\Variable) {
            // Not a simple variable (e.g. an error-recovery node), so it carries no operand name.
            return null;
        }

        // Share the variable operand key shape ($name); a dynamic ${$expr} name has no static key, so null.
        return is_string($parameter->var->name) ? '$' . $parameter->var->name : null;
    }

    /**
     * Calculate Halstead metrics from operator and operand counts.
     *
     * @param int $uniqueOperators Distinct operator kinds (n1); drives vocabulary and the difficulty numerator.
     * @param int $uniqueOperands  Distinct operand names (n2); a zero short-circuits to the empty-metrics result
     *                             to avoid dividing by it in the difficulty term.
     * @param int $totalOperators  Every operator occurrence (N1), counting repeats; feeds program length.
     * @param int $totalOperands   Every operand occurrence (N2), counting repeats; a zero also yields empty metrics.
     *
     * @return array{volume: float, difficulty: float, effort: float, vocabulary: int, length: int} - the computed metrics; all-zero when vocabulary
     *                       or operand counts are zero so log() and the difficulty division stay defined
     */
    private static function metricsForCounts(int $uniqueOperators, int $uniqueOperands, int $totalOperators, int $totalOperands): array
    {
        $length     = $totalOperators + $totalOperands;
        $vocabulary = $uniqueOperators + $uniqueOperands;

        if ($vocabulary === 0 || $uniqueOperands === 0 || $totalOperands === 0) {
            // Trivial body: zeroes keep log() and the difficulty division below defined, never tripping a threshold.
            return ['volume' => 0.0, 'difficulty' => 0.0, 'effort' => 0.0, 'vocabulary' => 0, 'length' => 0];
        }

        $volume     = $length * log($vocabulary, 2);
        $difficulty = ($uniqueOperators / 2) * ($totalOperands / $uniqueOperands);

        // Full Halstead set; volume is the threshold-bearing figure, effort and difficulty enrich the finding.
        return [
            'volume'     => $volume,
            'difficulty' => $difficulty,
            'effort'     => $volume * $difficulty,
            'vocabulary' => $vocabulary,
            'length'     => $length,
        ];
    }

    /**
     * Render a configured numeric threshold for finding messages.
     *
     * @param int|float $number Configured volume threshold; an integral float is shown without its ".0" tail.
     *
     * @return string - the threshold for the message, with an integral float's ".0" dropped and real fractions kept
     */
    private static function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            // Genuine fraction: keep every digit so a precise threshold reads back exactly.
            return (string)$number;
        }

        // Integral value: cast through int to drop the ".0" a float would otherwise print.
        return (string)(int)$number;
    }
}
