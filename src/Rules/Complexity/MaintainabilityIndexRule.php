<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Complexity;

use GruffPhp\Engine\Analysis\SensitiveExclusionFilter;
use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Scores each function or method with a maintainability index - a blend of cyclomatic complexity,
 * Halstead volume, and length - and flags the ones that fall below the configured floor.
 *
 * Runs over function-like nodes outside the built-in test-path class. A low index (default advisory below 35) marks a refactor
 * candidate for the user to weigh, not a confirmed defect, so it ships at the lowest severity. The
 * finding names the computed score and the threshold it missed.
 */
final readonly class MaintainabilityIndexRule implements RuleInterface
{
    /**
     * Stable rule identifier for maintainability index findings.
     */
    public const ID = 'complexity.maintainability-index';

    /**
     * Describes the maintainability-index rule for the registry and reports.
     *
     * @return RuleDefinition - Registry entry with the advisory floor and reporting metadata.
     */
    public function definition(): RuleDefinition
    {
        // Advisory: a low index flags code to consider refactoring, not a confirmed defect, so it uses the
        // lowest severity tier and sorts below warnings/errors. The shipped --fail-on default is advisory, so this
        // does fail the gate; a consumer who wants it non-blocking raises --fail-on to warning.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Maintainability index',
            pillar:            Pillar::Maintainability,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Low,
            severityThreshold: new SeverityThreshold(35, Severity::Advisory),
            // Off by default since precision-floor M09: right on 8 of 14 judged findings on unseen code.
            isEnabledByDefault: false,
            falsePositiveShapes: [
                [
                    'shape'      => 'A long but linear method - wiring, data setup, or generated code - that branches very little.',
                    'mitigation' => 'The logical-line term dominates the index, so a low score here means length rather than complexity; split the method or tune this rule\'s threshold and severity.',
                ],
            ],
        );
    }

    /**
     * Reports below-floor maintainability scores outside the built-in test paths.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context carrying thresholds.
     *
     * @return list<Finding> - One finding per below-floor node; empty for excluded paths or no threshold breaches.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Built-in test, fixture, and example paths are outside this rule's reporting scope.
        if (SensitiveExclusionFilter::isBuiltInTestPath($analysisUnit->file->displayPath)) {
            return [];
        }

        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];


        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node - NodeIndex query is constrained to function-like classes. */
            $mi             = self::computeMaintainabilityIndex($node, $analysisUnit);
            $thresholdMatch = $settings->lowValueThresholdMatch($mi);


            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = FunctionComplexity::resolveSymbol($node);

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
     * Computes the 0-100 maintainability index for one node from its complexity, volume, and length.
     *
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
        $ccn      = FunctionComplexity::computeCyclomaticComplexity($node);
        $halstead = HalsteadVolumeRule::computeHalsteadMetrics($node);
        $volume   = max(1.0, $halstead['volume']);

        $mi = (171.0 - 5.2 * log($volume) - 0.23 * $ccn - 16.2 * log($lloc)) * 100.0 / 171.0;

        // Clamp at zero so dense methods stay within the index's 0-100 range.
        return max(0.0, $mi);
    }

    /**
     * Formats a threshold number for the message, dropping a whole number's ".0" tail.
     *
     * @param int|float $number - Configured maintainability threshold; an integral float drops its ".0" tail.
     *
     * @return string - Human-readable threshold with fractions kept and whole values shown without decimals.
     */
    private static function formatNumber(int|float $number): string
    {

        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
