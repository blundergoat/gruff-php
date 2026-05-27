<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\Severity;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Mutation\MutationFileSummary;

/**
 * Calculates composite, pillar, and file scores from findings and optional mutation data.
 */
final readonly class ScoreCalculator
{
    /**
     * Built-in static-analysis pillars included even when a run has no findings.
     */
    private const STATIC_PILLARS = [
        'size',
        'complexity',
        'maintainability',
        'dead-code',
        'naming',
        'documentation',
        'modernisation',
        'security',
        'sensitive-data',
        'test-quality',
    ];

    /**
     * @param list<Finding>               $findings               Findings included in the score calculation.
     * @param MutationAnalysisResult|null $mutationAnalysisResult Optional mutation result included in scoring.
     * @param DiffResult|null             $diffResult             Optional diff result limiting the scoring scope label.
     * @param int                         $fileScoreLimit         Maximum file offender rows to retain.
     * @param list<Pillar>|null           $scorePillars           Optional pillar set included in composite scoring.
     * @param AnalysisConfig|null         $analysisConfig         Optional config used to filter findings from rules marked `excludeFromScore` (ADR-016).
     * @return ScoreReport Calculated composite, pillar, and file-level scores.
     */
    public function calculate(
        array $findings,
        ?MutationAnalysisResult $mutationAnalysisResult,
        ?DiffResult $diffResult,
        int $fileScoreLimit = 10,
        ?array $scorePillars = null,
        ?AnalysisConfig $analysisConfig = null,
    ): ScoreReport {
        $findings   = $this->scoredFindings($findings, $analysisConfig);
        $pillars    = $this->pillarScores($findings, $mutationAnalysisResult, $scorePillars);
        $scoreTotal = 0.0;
        $scoreCount = 0;

        foreach ($pillars as $pillar) {
            if (!$pillar->applicable || !$pillar->grade instanceof Grade) {
                continue;
            }

            $scoreTotal += $pillar->grade->score;
            $scoreCount++;
        }

        $averageScore = $scoreCount === 0 ? 100.0 : $scoreTotal / $scoreCount;

        $scope = $diffResult instanceof DiffResult && $diffResult->active ? 'diff' : 'full-project';

        return new ScoreReport(
            composite:              Grade::fromScore($averageScore),
            pillars:                $pillars,
            topOffenders:           $this->fileScores($findings, $mutationAnalysisResult, $fileScoreLimit),
            complexityDistribution: $this->complexityDistribution($findings),
            scope:                  $scope,
            explanation:            $this->scoreExplanation($mutationAnalysisResult),
        );
    }

    /**
     * Filter the input findings to only those that contribute to scoring penalties.
     * A rule marked `excludeFromScore: true` is informational: its findings still
     * flow through reports (the scorer never sees them after this filter), but
     * they do not affect the composite or pillar penalty buckets. See ADR-016.
     *
     * Synthetic composite findings (e.g. `design.god-method` from
     * {@see CompositeFindingFactory}) are not in the registry; they carry their
     * component rule IDs in `metadata.componentRules`. A composite is excluded
     * iff EVERY component rule is excluded — otherwise a single non-excluded
     * component should still let the composite penalty land.
     *
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function scoredFindings(array $findings, ?AnalysisConfig $analysisConfig): array
    {
        if (!$analysisConfig instanceof AnalysisConfig) {
            return $findings;
        }

        $rules = $analysisConfig->rules();

        return array_values(array_filter(
            $findings,
            static function (Finding $finding) use ($rules): bool {
                $settings = $rules[$finding->ruleId] ?? null;
                if ($settings !== null) {
                    return !$settings->isExcludedFromScore();
                }

                // Synthetic finding: walk componentRules metadata when present.
                $componentRules = $finding->metadata['componentRules'] ?? null;
                if (!is_array($componentRules) || $componentRules === []) {
                    return true;
                }

                foreach ($componentRules as $componentRuleId) {
                    if (!is_string($componentRuleId)) {
                        continue;
                    }
                    $componentSettings = $rules[$componentRuleId] ?? null;
                    if ($componentSettings === null || !$componentSettings->isExcludedFromScore()) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * @return string Human-readable score calculation summary.
     */
    private function scoreExplanation(?MutationAnalysisResult $mutationAnalysisResult): string
    {
        $base = 'Per-pillar scores start at 100 and subtract weighted finding penalties; the composite is the average of applicable pillar scores.';

        if ($mutationAnalysisResult instanceof MutationAnalysisResult) {
            return $base . ' Mutation uses the supplied Infection MSI as the mutation pillar score.';
        }

        return $base . ' Mutation is omitted when no Infection report is supplied.';
    }

    /**
     * Calculate per-pillar scores from the active finding set.
     *
     * @param list<Finding>     $findings
     * @param list<Pillar>|null $scorePillars
     * @return list<PillarScore>
     */
    private function pillarScores(array $findings, ?MutationAnalysisResult $mutationAnalysisResult, ?array $scorePillars): array
    {
        $pillarNames = $scorePillars === null
            ? self::STATIC_PILLARS
            : array_values(array_unique(array_map(static fn (Pillar $pillar): string => $pillar->value, $scorePillars)));

        if ($scorePillars === null) {
            foreach ($findings as $finding) {
                if (!in_array($finding->pillar->value, $pillarNames, true)) {
                    $pillarNames[] = $finding->pillar->value;
                }
            }
        }

        if (
            $scorePillars === null
            && $mutationAnalysisResult instanceof MutationAnalysisResult
            && !in_array(Pillar::Mutation->value, $pillarNames, true)
        ) {
            $pillarNames[] = Pillar::Mutation->value;
        }

        $scores = [];

        foreach ($pillarNames as $pillarName) {
            if ($pillarName === Pillar::Mutation->value && !$mutationAnalysisResult instanceof MutationAnalysisResult) {
                $scores[] = new PillarScore($pillarName, false, null, 0, 0, 0, 0, 0.0);
                continue;
            }

            if ($pillarName === Pillar::Mutation->value && $mutationAnalysisResult instanceof MutationAnalysisResult) {
                $mutationFindings = array_values(array_filter(
                    $findings,
                    static fn (Finding $finding): bool => $finding->pillar === Pillar::Mutation,
                ));
                $counts   = $this->severityCounts($mutationFindings);
                $scores[] = new PillarScore(
                    pillar:     $pillarName,
                    applicable: true,
                    grade:      Grade::fromScore($mutationAnalysisResult->report->msi()),
                    findings:   count($mutationFindings),
                    advisory:   $counts['advisory'],
                    warning:    $counts['warning'],
                    error:      $counts['error'],
                    penalty:    max(0.0, 100.0 - $mutationAnalysisResult->report->msi()),
                );
                continue;
            }

            $pillarFindings = array_values(array_filter(
                $findings,
                static fn (Finding $finding): bool => $finding->pillar->value === $pillarName,
            ));
            $penalty = array_sum(array_map(fn (Finding $finding): float => $this->penaltyFor($finding), $pillarFindings)) * 4.0;
            $counts  = $this->severityCounts($pillarFindings);

            $scores[] = new PillarScore(
                pillar:     $pillarName,
                applicable: true,
                grade:      Grade::fromScore(100.0 - $penalty),
                findings:   count($pillarFindings),
                advisory:   $counts['advisory'],
                warning:    $counts['warning'],
                error:      $counts['error'],
                penalty:    $penalty,
            );
        }

        return $scores;
    }

    /**
     * Calculate per-file scores from the active finding set.
     *
     * @param list<Finding> $findings
     * @return list<FileScore>
     */
    private function fileScores(array $findings, ?MutationAnalysisResult $mutationAnalysisResult, int $limit): array
    {
        /** @var array<string, list<Finding>> $byFile Accumulator shape is built incrementally from finding file paths. */
        $byFile = [];

        foreach ($findings as $finding) {
            $byFile[$finding->filePath] ??= [];
            $byFile[$finding->filePath][] = $finding;
        }

        $mutationByFile = [];
        if ($mutationAnalysisResult instanceof MutationAnalysisResult) {
            foreach ($mutationAnalysisResult->report->fileSummaries() as $summary) {
                $mutationByFile[$summary->filePath] = $summary;
                $byFile[$summary->filePath] ??= [];
            }
        }

        $scores = [];

        foreach ($byFile as $filePath => $fileFindings) {
            $counts          = $this->severityCounts($fileFindings);
            $penalty         = array_sum(array_map(fn (Finding $finding): float => $this->penaltyFor($finding), $fileFindings)) * 5.0;
            $mutationSummary = $mutationByFile[$filePath] ?? null;

            $scores[] = new FileScore(
                filePath:      $filePath,
                grade:         Grade::fromScore(100.0 - $penalty),
                findings:      count($fileFindings),
                advisory:      $counts['advisory'],
                warning:       $counts['warning'],
                error:         $counts['error'],
                penalty:       $penalty,
                maxCyclomatic: $this->maxMetadataInt($fileFindings, 'complexity.cyclomatic', 'complexity'),
                maxCognitive:  $this->maxMetadataInt($fileFindings, 'complexity.cognitive', 'complexity'),
                maxLines:      $this->maxLineMetric($fileFindings),
                mutationScore: $mutationSummary instanceof MutationFileSummary ? $mutationSummary->msi : null,
            );
        }

        usort($scores, static function (FileScore $leftFileScore, FileScore $rightFileScore): int {
            return $leftFileScore->grade->score <=> $rightFileScore->grade->score
                ?: $rightFileScore->findings <=> $leftFileScore->findings
                ?: strcmp($leftFileScore->filePath, $rightFileScore->filePath);
        });

        return array_slice($scores, 0, $limit);
    }

    /**
     * Bucket complexity findings by rule identifier.
     *
     * @param list<Finding> $findings
     * @return array<string, int>
     */
    private function complexityDistribution(array $findings): array
    {
        $buckets = [
            '1-5' => 0,
            '6-10' => 0,
            '11-15' => 0,
            '16-20' => 0,
            '21+' => 0,
        ];

        foreach ($findings as $finding) {
            if ($finding->ruleId !== 'complexity.cyclomatic') {
                continue;
            }

            $complexity = $finding->metadata['complexity'] ?? null;
            if (!is_int($complexity)) {
                continue;
            }

            if ($complexity <= 5) {
                $buckets['1-5']++;
            } elseif ($complexity <= 10) {
                $buckets['6-10']++;
            } elseif ($complexity <= 15) {
                $buckets['11-15']++;
            } elseif ($complexity <= 20) {
                $buckets['16-20']++;
            } else {
                $buckets['21+']++;
            }
        }

        return $buckets;
    }

    /**
     * Convert one finding severity and confidence into a score penalty.
     *
     * @return float Weighted penalty contribution for the finding.
     */
    private function penaltyFor(Finding $finding): float
    {
        $severityWeight = match ($finding->severity) {
            Severity::Advisory => 1.0,
            Severity::Warning => 4.0,
            Severity::Error => 12.0,
        };

        $confidenceWeight = match ($finding->confidence) {
            Confidence::Low => 0.5,
            Confidence::Medium => 0.75,
            Confidence::High => 1.0,
        };

        return $severityWeight * $confidenceWeight;
    }

    /**
     * Count findings by severity for scoring and summaries.
     *
     * @param list<Finding> $findings
     * @return array{advisory: int, warning: int, error: int}
     */
    private function severityCounts(array $findings): array
    {
        $counts = ['advisory' => 0, 'warning' => 0, 'error' => 0];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * @param list<Finding> $findings
     * @return int|null Maximum integer metadata value for the selected rule and key.
     */
    private function maxMetadataInt(array $findings, string $ruleId, string $key): ?int
    {
        $maximumValue = null;

        foreach ($findings as $finding) {
            if ($finding->ruleId !== $ruleId) {
                continue;
            }

            $metricValue = $finding->metadata[$key] ?? null;
            if (!is_int($metricValue)) {
                continue;
            }

            $maximumValue = $maximumValue === null ? $metricValue : max($maximumValue, $metricValue);
        }

        return $maximumValue;
    }

    /**
     * @param list<Finding> $findings
     * @return int|null Maximum line metric found in size-rule metadata.
     */
    private function maxLineMetric(array $findings): ?int
    {
        $maximumLines = null;

        foreach ($findings as $finding) {
            if (!in_array($finding->ruleId, ['size.file-length', 'size.method-length', 'size.class-length'], true)) {
                continue;
            }

            $lineCount = $finding->metadata['lines'] ?? null;
            if (!is_int($lineCount)) {
                continue;
            }

            $maximumLines = $maximumLines === null ? $lineCount : max($maximumLines, $lineCount);
        }

        return $maximumLines;
    }
}
