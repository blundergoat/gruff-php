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
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeFinder;

/**
 * Measures maintainability by combining complexity, volume, and length signals.
 */
final readonly class MaintainabilityIndexRule implements RuleInterface
{
    /**
     * Stable rule identifier for maintainability index findings.
     */
    public const ID = 'complexity.maintainability-index';

    /**
     * Describe the maintainability index rule.
     *
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Maintainability index',
            pillar:            Pillar::Maintainability,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Warning,
            confidence:        Confidence::Medium,
            defaultThresholds: [
                'warning' => 55,
                'error' => 35,
            ],
        );
    }

    /**
     * Find function-like declarations whose maintainability index falls below thresholds.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context carrying thresholds.
     * @return list<Finding> Findings for low maintainability index scores.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings   = $context->settingsFor($definition);

        $finder = new NodeFinder();
        $nodes  = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod
                || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $mi             = self::computeMaintainabilityIndex($node, $unit);
            $thresholdMatch = $settings->lowValueThresholdMatch($mi);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has a maintainability index of %.1f, below the %s threshold of %s.',
                    $symbol,
                    $mi,
                    $thresholdMatch->severity->value,
                    self::formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $unit->file->displayPath,
                line:             $node->getStartLine(),
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol:           $symbol,
                remediation:      'Simplify the method by reducing complexity, shortening it, or improving readability.',
                secondaryPillars: [Pillar::Complexity],
                metadata:         [
                    'maintainabilityIndex' => round($mi, 1),
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node Function-like node to score.
     * @param AnalysisUnit          $unit Parsed unit that owns the node.
     *
     * @return float Maintainability index score.
     */
    public static function computeMaintainabilityIndex(Node $node, AnalysisUnit $unit): float
    {
        $startLine = $node->getStartLine();
        $endLine   = $node->getEndLine();

        if ($startLine < 0 || $endLine < 0) {
            return 100.0;
        }

        $lloc     = max(1, self::logicalLineCount($node));
        $ccn      = CyclomaticComplexityRule::computeCyclomaticComplexity($node);
        $halstead = HalsteadVolumeRule::computeHalsteadMetrics($node);
        $volume   = max(1.0, $halstead['volume']);

        $mi = (171.0 - 5.2 * log($volume) - 0.23 * $ccn - 16.2 * log($lloc)) * 100.0 / 171.0;

        return max(0.0, $mi);
    }

    /**
     * @param ClassMethod|Function_ $node
     *
     * @return int Logical statement line count.
     */
    private static function logicalLineCount(Node $node): int
    {
        $finder = new NodeFinder();
        $lines  = [];

        foreach ($finder->find($node->stmts ?? [], static fn (Node $child): bool => $child instanceof Stmt && !$child instanceof Nop) as $statement) {
            $line = $statement->getStartLine();

            if ($line > 0) {
                $lines[$line] = true;
            }
        }

        return count($lines);
    }

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
     * @return string Human-readable threshold value.
     */
    private static function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
