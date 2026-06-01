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
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

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
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        // Advisory: a low index flags a refactor candidate to weigh, not a confirmed defect, so it ships at the
        // lowest severity tier and sorts below warnings/errors. The shipped --fail-on default is advisory, so this
        // does fail the gate; a consumer who wants it non-blocking raises --fail-on to warning.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Maintainability index',
            pillar:            Pillar::Maintainability,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Medium,
            severityThreshold: new SeverityThreshold(35, Severity::Advisory),
        );
    }

    /**
     * Find function-like declarations whose maintainability index falls below thresholds.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context carrying thresholds.
     *
     * @return list<Finding> - One finding per node below the maintainability threshold; empty when all nodes pass.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node NodeIndex query is constrained to function-like classes. */
            $mi             = self::computeMaintainabilityIndex($node, $analysisUnit);
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
                filePath:         $analysisUnit->file->displayPath,
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
     * @param ClassMethod|Function_ $node - Function-like node to score.
     * @param AnalysisUnit          $analysisUnit - Parsed unit that owns the node.
     *
     * @return float - Maintainability index score.
     */
    public static function computeMaintainabilityIndex(Node $node, AnalysisUnit $analysisUnit): float
    {
        $startLine = $node->getStartLine();
        $endLine   = $node->getEndLine();

        if ($startLine < 0 || $endLine < 0) {
            // No line info to measure (synthetic node), so award a perfect score rather than penalise on no evidence.
            return 100.0;
        }

        $lloc     = max(1, NodeIndex::logicalStatementLineCount($node));
        $ccn      = CyclomaticComplexityRule::computeCyclomaticComplexity($node);
        $halstead = HalsteadVolumeRule::computeHalsteadMetrics($node);
        $volume   = max(1.0, $halstead['volume']);

        $mi = (171.0 - 5.2 * log($volume) - 0.23 * $ccn - 16.2 * log($lloc)) * 100.0 / 171.0;

        // Clamp at zero: the normalised SEI index has a 0-100 floor, so a very dense method cannot report negative.
        return max(0.0, $mi);
    }

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
     * @param int|float $number - Configured maintainability threshold; an integral float drops its ".0" tail.
     *
     * @return string - Human-readable threshold value with fractional values preserved and whole values stripped.
     */
    private static function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
