<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Mutation\MutationAnalysisResult;
use GruffPhp\Results\Mutation\MutationFileSummary;

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
     * Size and complexity rules that describe one over-large method as separate
     * symptoms of a single root cause (P5 / ADR-024).
     *
     * When two or more of these fire on the same `(file, symbol, line)` they are
     * the same god-method seen from different angles: too long, too nested, too
     * branchy, too many parameters. Scoring bills that cluster once instead of
     * once per symptom so one root cause cannot tank the grade four times. The
     * set deliberately omits the retired `design.god-method` composite (ADR-023);
     * the real component findings now carry the signal directly.
     */
    private const CORRELATED_COMPLEXITY_RULES = [
        'complexity.cognitive',
        'complexity.cyclomatic',
        'complexity.nesting-depth',
        'size.method-length',
        'size.parameter-count',
    ];

    /**
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding>               $findings - Findings included in the score calculation.
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Optional mutation result included in scoring.
     * @param DiffResult|null             $diffResult - Optional diff result limiting the scoring scope label.
     * @param int                         $fileScoreLimit - Maximum file offender rows to retain.
     * @param list<Pillar>|null           $scorePillars - Optional pillar set included in composite scoring.
     * @param AnalysisConfig|null         $analysisConfig - Optional config used to filter findings from rules marked `excludeFromScore`
     *                                                            (ADR-016).
     *
     * @return ScoreReport - Calculated composite, pillar, and file-level scores.
     */
    public function calculate(
        array                   $findings,
        ?MutationAnalysisResult $mutationAnalysisResult,
        ?DiffResult             $diffResult,
        int                     $fileScoreLimit = 10,
        ?array                  $scorePillars = null,
        ?AnalysisConfig         $analysisConfig = null,
    ): ScoreReport {
        $findings   = $this->scoredFindings($findings, $analysisConfig);
        $penalties  = $this->findingPenalties($findings);
        $pillars    = $this->pillarScores($findings, $penalties, $mutationAnalysisResult, $scorePillars);
        $scoreTotal = 0.0;
        $scoreCount = 0;

        // User view: add each item that can appear in score output.
        foreach ($pillars as $pillar) {
            // User view: choose the score output branch for this case.
            if (!$pillar->applicable || !$pillar->grade instanceof Grade) {
                continue;
            }

            $scoreTotal += $pillar->grade->score;
            $scoreCount++;
        }

        $averageScore = $scoreCount === 0 ? 100.0 : $scoreTotal / $scoreCount;

        $scope = $diffResult instanceof DiffResult && $diffResult->active ? 'diff' : 'full-project';

        // Composite is the mean of applicable pillars only; an all-inapplicable run scored 100 above keeps a clean grade.
        return new ScoreReport(
            composite:              Grade::fromScore($averageScore),
            pillars:                $pillars,
            topOffenders:           $this->fileScores($findings, $penalties, $mutationAnalysisResult, $fileScoreLimit),
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
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding>       $findings - Findings produced for the run, before the scoring filter.
     * @param AnalysisConfig|null $analysisConfig - Config whose per-rule excludeFromScore flags drop informational findings from scoring; null keeps
     *                                            every finding.
     *
     * @return list<Finding> - the input findings minus any whose rule opts out of scoring; empty when every finding was excluded
     */
    private function scoredFindings(array $findings, ?AnalysisConfig $analysisConfig): array
    {
        // User view: choose the score output branch for this case.
        if (!$analysisConfig instanceof AnalysisConfig) {
            // No config means no per-rule exclusions to honour, so every finding stays in scoring.
            return $findings;
        }

        $rules = $analysisConfig->rules();

        return array_values(array_filter(
                                $findings,
                                static function (Finding $finding) use ($rules): bool {
                                    // User view: missing data becomes a safe score output default.
                                    $settings = $rules[$finding->ruleId] ?? null;
                                    // User view: choose the score output branch for this case.
                                    // User view: missing data becomes the expected score output state.
                                    if ($settings !== null) {
                                        // Configured rule decides: drop the finding from scoring when it is marked excludeFromScore.
                                        return !$settings->isExcludedFromScore();
                                    }

                                    // A rule with no config entry is scored by default.
                                    return true;
                                },
                            ));
    }

    /**
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Present means the summary names the MSI-based mutation pillar; null means it states
     *                                                            mutation was skipped.
     *
     * @return string - one-paragraph summary of how scores are derived, with the mutation sentence varying by whether a report was supplied; shown
     *                verbatim to readers in the report
     */
    private function scoreExplanation(?MutationAnalysisResult $mutationAnalysisResult): string
    {
        $base = 'Per-pillar scores start at 100 and subtract weighted finding penalties; correlated size and complexity findings on one symbol share a single penalty; the composite is the average of applicable pillar scores.';

        // User view: choose the score output branch for this case.
        if ($mutationAnalysisResult instanceof MutationAnalysisResult) {
            return $base . ' Mutation uses the supplied Infection MSI as the mutation pillar score.';
        }

        return $base . ' Mutation is omitted when no Infection report is supplied.';
    }

    /**
     * Calculate per-pillar scores from the active finding set.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding>               $findings - Scored findings bucketed into per-pillar penalties.
     * @param array<int, float>           $penalties - Clustered penalty per finding keyed by spl_object_id() (see findingPenalties()).
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Mutation report that adds the Mutation pillar graded from its MSI; null omits that
     *                                                            pillar.
     * @param list<Pillar>|null           $scorePillars - Explicit pillar set to score, or null to derive pillars from the findings.
     *
     * @return list<PillarScore> - one score per resolved pillar in pillar-name order; inapplicable pillars are present but ungraded
     */
    private function pillarScores(array $findings, array $penalties, ?MutationAnalysisResult $mutationAnalysisResult, ?array $scorePillars): array
    {
        // User view: missing data becomes the expected score output state.
        $pillarNames = $scorePillars === null
            ? self::STATIC_PILLARS
            : array_values(array_unique(array_map(static fn(Pillar $pillar): string => $pillar->value, $scorePillars)));

        // User view: choose the score output branch for this case.
        // User view: missing data becomes the expected score output state.
        if ($scorePillars === null) {
            // User view: add each item that can appear in score output.
            foreach ($findings as $finding) {
                // User view: choose the score output branch for this case.
                if (!in_array($finding->pillar->value, $pillarNames, true)) {
                    $pillarNames[] = $finding->pillar->value;
                }
            }
        }

        // User view: choose the score output branch for this case.
        if (
            // User view: missing data becomes the expected score output state.
            $scorePillars === null
            && $mutationAnalysisResult instanceof MutationAnalysisResult
            && !in_array(Pillar::Mutation->value, $pillarNames, true)
        ) {
            $pillarNames[] = Pillar::Mutation->value;
        }

        $scores = [];

        // User view: add each item that can appear in score output.
        foreach ($pillarNames as $pillarName) {
            // User view: choose the score output branch for this case.
            if ($pillarName === Pillar::Mutation->value) {
                // User view: choose the score output branch for this case.
                if (!$mutationAnalysisResult instanceof MutationAnalysisResult) {
                    $scores[] = new PillarScore($pillarName, false, null, 0, 0, 0, 0, 0.0);
                    continue;
                }

                $mutationReport   = $mutationAnalysisResult->report;
                $mutationFindings = array_values(array_filter(
                                                     $findings,
                                                     static fn(Finding $finding): bool => $finding->pillar === Pillar::Mutation,
                                                 ));
                $counts           = $this->severityCounts($mutationFindings);
                $scores[]         = new PillarScore(
                    pillar:     $pillarName,
                    applicable: true,
                    grade:      Grade::fromScore($mutationReport->msi()),
                    findings:   count($mutationFindings),
                    advisory:   $counts['advisory'],
                    warning:    $counts['warning'],
                    error:      $counts['error'],
                    penalty:    max(0.0, 100.0 - $mutationReport->msi()),
                );
                continue;
            }

            $pillarFindings = array_values(array_filter(
                                               $findings,
                                               static fn(Finding $finding): bool => $finding->pillar->value === $pillarName,
                                           ));
            $penalty        = $this->sumPenalties($pillarFindings, $penalties) * 4.0;
            $counts         = $this->severityCounts($pillarFindings);

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
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding>               $findings - Scored findings bucketed by file path.
     * @param array<int, float>           $penalties - Clustered penalty per finding keyed by spl_object_id() (see findingPenalties()).
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Mutation report whose per-file MSI summaries enrich each file score; null leaves
     *                                                            mutationScore unset.
     * @param int                         $limit - Maximum number of worst-scoring file scores to return.
     *
     * @return list<FileScore> - the worst-grade files first (ties broken by finding count then path), capped at $limit
     */
    private function fileScores(array $findings, array $penalties, ?MutationAnalysisResult $mutationAnalysisResult, int $limit): array
    {
        /** @var array<string, list<Finding>> $byFile Accumulator shape is built incrementally from finding file paths. */
        $byFile = [];

        // User view: add each item that can appear in score output.
        foreach ($findings as $finding) {
            $byFile[$finding->filePath]   ??= [];
            $byFile[$finding->filePath][] = $finding;
        }

        $mutationByFile = [];
        // User view: choose the score output branch for this case.
        if ($mutationAnalysisResult instanceof MutationAnalysisResult) {
            // User view: add each item that can appear in score output.
            foreach ($mutationAnalysisResult->report->fileSummaries() as $summary) {
                $mutationByFile[$summary->filePath] = $summary;
                $byFile[$summary->filePath]         ??= [];
            }
        }

        $scores = [];

        // User view: add each item that can appear in score output.
        foreach ($byFile as $filePath => $fileFindings) {
            $counts          = $this->severityCounts($fileFindings);
            $penalty         = $this->sumPenalties($fileFindings, $penalties) * 5.0;
            // User view: missing data becomes a safe score output default.
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
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding> $findings - Findings for one score calculation; only cyclomatic-complexity findings contribute to these buckets.
     *
     * @return array<string, int> - fixed five-bucket cyclomatic histogram keyed by range label; every bucket present, zero when empty
     */
    private function complexityDistribution(array $findings): array
    {
        $buckets = [
            '1-5'   => 0,
            '6-10'  => 0,
            '11-15' => 0,
            '16-20' => 0,
            '21+'   => 0,
        ];

        // User view: add each item that can appear in score output.
        foreach ($findings as $finding) {
            // User view: choose the score output branch for this case.
            if ($finding->ruleId !== 'complexity.cyclomatic') {
                continue;
            }

            // User view: missing data becomes a safe score output default.
            $complexity = $finding->metadata['complexity'] ?? null;
            // User view: choose the score output branch for this case.
            if (!is_int($complexity)) {
                continue;
            }

            // User view: choose the score output branch for this case.
            if ($complexity <= 5) {
                $buckets['1-5']++;
            }
            // User view: choose the next score output branch for this case.
            elseif ($complexity <= 10) {
                $buckets['6-10']++;
            }
            // User view: choose the next score output branch for this case.
            elseif ($complexity <= 15) {
                $buckets['11-15']++;
            }
            // User view: choose the next score output branch for this case.
            elseif ($complexity <= 20) {
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
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param Finding $finding - Finding whose severity and confidence set the base weight, before any cluster sharing.
     *
     * @return float - the finding's base penalty (severity weight times confidence weight) before any cluster sharing; always non-negative
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
     * Weight every finding for scoring, clustering correlated complexity/size
     * findings so one root cause is billed once (P5 / ADR-024).
     *
     * Findings that share a `(file, symbol, line)` and whose rule is in
     * {@see self::CORRELATED_COMPLEXITY_RULES} describe one over-large method
     * from different angles. Each such cluster of two or more contributes a
     * single shared weight — the largest member penalty divided by the member
     * count — so a method that is long *and* nested *and* cyclomatically complex
     * subtracts roughly one penalty rather than four. Every finding stays in the
     * report; only its scoring weight is divided across the cluster. Lone
     * findings, and any rule outside the correlated set, keep their full base
     * penalty. The map is keyed by spl_object_id() because the same penalty must
     * follow each finding into both the pillar and file penalty buckets.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding> $findings - Scored findings to weight.
     *
     * @return array<int, float> - penalty per finding, keyed by spl_object_id().
     */
    private function findingPenalties(array $findings): array
    {
        $penalties = [];
        // User view: add each item that can appear in score output.
        foreach ($findings as $finding) {
            $penalties[spl_object_id($finding)] = $this->penaltyFor($finding);
        }

        /** @var array<string, list<Finding>> $clusters Correlated findings grouped by file|symbol|line key. */
        $clusters = [];
        // User view: add each item that can appear in score output.
        foreach ($findings as $finding) {
            // User view: choose the score output branch for this case.
            if (
                !in_array($finding->ruleId, self::CORRELATED_COMPLEXITY_RULES, true)
                // User view: missing data becomes the expected score output state.
                || $finding->symbol === null
                // User view: missing data becomes the expected score output state.
                || $finding->line === null
            ) {
                continue;
            }

            $key              = $finding->filePath . "\0" . $finding->symbol . "\0" . $finding->line;
            $clusters[$key][] = $finding;
        }

        // User view: add each item that can appear in score output.
        foreach ($clusters as $cluster) {
            // User view: choose the score output branch for this case.
            if (count($cluster) < 2) {
                continue;
            }

            $shared = max(array_map(fn(Finding $finding): float => $this->penaltyFor($finding), $cluster)) / count($cluster);
            // User view: add each item that can appear in score output.
            foreach ($cluster as $finding) {
                $penalties[spl_object_id($finding)] = $shared;
            }
        }

        return $penalties;
    }

    /**
     * Total the clustered penalties for a subset of findings.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding>     $findings - Findings whose weights to total.
     * @param array<int, float> $penalties - Penalty per finding keyed by spl_object_id(), from findingPenalties().
     *
     * @return float - total post-clustering weight across the subset, fed into the pillar/file penalty multipliers; 0.0 when the subset is empty
     */
    private function sumPenalties(array $findings, array $penalties): float
    {
        $total = 0.0;
        // User view: add each item that can appear in score output.
        foreach ($findings as $finding) {
            // User view: missing data becomes a safe score output default.
            $total += $penalties[spl_object_id($finding)] ?? $this->penaltyFor($finding);
        }

        return $total;
    }

    /**
     * Count findings by severity for scoring and summaries.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding> $findings - Findings to tally for score summaries; all severities are counted even when the input is empty.
     *
     * @return array{advisory: int, warning: int, error: int} - finding tally per severity; all three keys always present, zero when none
     */
    private function severityCounts(array $findings): array
    {
        $counts = ['advisory' => 0, 'warning' => 0, 'error' => 0];

        // User view: add each item that can appear in score output.
        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding> $findings - Findings to scan for the requested rule metadata.
     * @param string        $ruleId - Only findings from this rule are considered; others are skipped before reading metadata.
     * @param string        $key - Metadata entry to maximise; non-integer or absent values are ignored.
     *
     * @return int|null - largest integer metadata value across findings of the given rule, or null when none carried the metric (distinct from a
     *                  real 0)
     */
    private function maxMetadataInt(array $findings, string $ruleId, string $key): ?int
    {
        $maximumValue = null;

        // User view: add each item that can appear in score output.
        foreach ($findings as $finding) {
            // User view: choose the score output branch for this case.
            if ($finding->ruleId !== $ruleId) {
                continue;
            }

            // User view: missing data becomes a safe score output default.
            $metricValue = $finding->metadata[$key] ?? null;
            // User view: choose the score output branch for this case.
            if (!is_int($metricValue)) {
                continue;
            }

            // User view: missing data becomes the expected score output state.
            $maximumValue = $maximumValue === null ? $metricValue : max($maximumValue, $metricValue);
        }

        return $maximumValue;
    }

    /**
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<Finding> $findings - Findings for a single file score; only size rules with integer `lines` metadata contribute.
     *
     * @return int|null - largest `lines` count across file/method/class size findings, or null when the file had no size finding reporting a line
     *                  count
     */
    private function maxLineMetric(array $findings): ?int
    {
        $maximumLines = null;

        // User view: add each item that can appear in score output.
        foreach ($findings as $finding) {
            // User view: choose the score output branch for this case.
            if (!in_array($finding->ruleId, ['size.file-length', 'size.method-length', 'size.class-length'], true)) {
                continue;
            }

            // User view: missing data becomes a safe score output default.
            $lineCount = $finding->metadata['lines'] ?? null;
            // User view: choose the score output branch for this case.
            if (!is_int($lineCount)) {
                continue;
            }

            // User view: missing data becomes the expected score output state.
            $maximumLines = $maximumLines === null ? $lineCount : max($maximumLines, $lineCount);
        }

        return $maximumLines;
    }
}
