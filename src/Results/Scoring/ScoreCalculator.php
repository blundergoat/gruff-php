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
 * Turns a run's raw findings into the grades a user actually reads - the composite score, each pillar's
 * grade, and the worst-scoring files.
 *
 * This is the heart of gruff's scoring. It starts each pillar at 100 and subtracts weighted penalties
 * for the findings against it, averages the applicable pillars into one composite grade, folds in
 * mutation results when present, and ranks the files that cost the most. Two touches keep the grade
 * fair: findings from rules marked `excludeFromScore` inform but never dock points, and a cluster of
 * correlated size/complexity findings on one method is billed once instead of several times - so a
 * single over-large method cannot tank the grade from four angles at once.
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
     * Produces the full score report for a run: applies the scoring filter, grades every pillar,
     * averages them into the composite, and ranks the worst files - the numbers every reporter shows.
     *
     * @param list<Finding>               $findings - Findings included in the score calculation.
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Mutation result folded in as the Mutation pillar; null when mutation analysis was not run.
     * @param DiffResult|null             $diffResult - Diff result that sets the scope label; null or inactive means a full-project score.
     * @param int                         $fileScoreLimit - Maximum worst-file rows to keep in the report.
     * @param list<Pillar>|null           $scorePillars - Explicit pillar set to score; null derives the pillars from the findings and the built-in set.
     * @param AnalysisConfig|null         $analysisConfig - Config whose per-rule excludeFromScore flags drop informational findings from scoring
     *                                                            (ADR-016); null scores every finding.
     *
     * @return ScoreReport - The composite grade plus per-pillar and per-file scores for the run.
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

        // Average only the pillars that actually applied, so an ungraded pillar cannot drag the composite down.
        foreach ($pillars as $pillar) {
            // Skip pillars with no applicable rules - they have no grade to fold into the average.
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
     * Drops findings whose rule opts out of scoring, so an informational rule can flag issues in the
     * report without ever docking the user's grade.
     *
     * A rule marked `excludeFromScore: true` is informational: its findings still
     * flow through reports (the scorer never sees them after this filter), but
     * they do not affect the composite or pillar penalty buckets. See ADR-016.
     *
     * @param list<Finding>       $findings - Findings produced for the run, before the scoring filter.
     * @param AnalysisConfig|null $analysisConfig - Config whose per-rule excludeFromScore flags drop informational findings from scoring; null keeps
     *                                            every finding.
     *
     * @return list<Finding> - The input findings minus any whose rule opts out of scoring; empty when every finding was excluded.
     */
    private function scoredFindings(array $findings, ?AnalysisConfig $analysisConfig): array
    {
        // With no config there are no per-rule exclusions to honour, so every finding stays in scoring.
        if (!$analysisConfig instanceof AnalysisConfig) {
            // No config means no per-rule exclusions to honour, so every finding stays in scoring.
            return $findings;
        }

        $rules = $analysisConfig->rules();

        return array_values(array_filter(
                                $findings,
                                static function (Finding $finding) use ($rules): bool {
                                    // Look up this rule's config entry, if it has one.
                                    $settings = $rules[$finding->ruleId] ?? null;
                                    // A configured rule decides for itself whether it counts toward the score.
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
     * Writes the one-paragraph "how this score was reached" note shown under the grade, varying the
     * mutation sentence by whether a report was supplied.
     *
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Present means the note names the MSI-based mutation pillar; null means it states
     *                                                            mutation was skipped.
     *
     * @return string - One-paragraph plain-English summary of how the scores were derived, shown verbatim to the user in the report.
     */
    private function scoreExplanation(?MutationAnalysisResult $mutationAnalysisResult): string
    {
        $base = 'Per-pillar scores start at 100 and subtract weighted finding penalties; correlated size and complexity findings on one symbol share a single penalty; the composite is the average of applicable pillar scores.';

        // When a mutation report was supplied, the note explains the Mutation pillar is graded from its MSI.
        if ($mutationAnalysisResult instanceof MutationAnalysisResult) {
            return $base . ' Mutation uses the supplied Infection MSI as the mutation pillar score.';
        }

        return $base . ' Mutation is omitted when no Infection report is supplied.';
    }

    /**
     * Grades every pillar from its findings: each starts at 100 and loses weighted penalties, while the
     * Mutation pillar (when present) is graded straight from its MSI.
     *
     * @param list<Finding>               $findings - Scored findings bucketed into per-pillar penalties.
     * @param array<int, float>           $penalties - Clustered penalty per finding keyed by spl_object_id() (see findingPenalties()).
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Mutation report that adds the Mutation pillar graded from its MSI; null omits that
     *                                                            pillar.
     * @param list<Pillar>|null           $scorePillars - Explicit pillar set to score, or null to derive pillars from the findings and built-in set.
     *
     * @return list<PillarScore> - One score per resolved pillar in pillar-name order; inapplicable pillars are present but ungraded.
     */
    private function pillarScores(array $findings, array $penalties, ?MutationAnalysisResult $mutationAnalysisResult, ?array $scorePillars): array
    {
        // Start from the caller's explicit pillar set, or the built-in static-analysis pillars when none was given.
        $pillarNames = $scorePillars === null
            ? self::STATIC_PILLARS
            : array_values(array_unique(array_map(static fn(Pillar $pillar): string => $pillar->value, $scorePillars)));

        // When deriving pillars, make sure any pillar a finding belongs to earns a slot, even outside the built-in set.
        if ($scorePillars === null) {
            // Add any pillar seen on a finding that is not already listed.
            foreach ($findings as $finding) {
                // A finding in a pillar we have not listed yet earns that pillar a slot.
                if (!in_array($finding->pillar->value, $pillarNames, true)) {
                    $pillarNames[] = $finding->pillar->value;
                }
            }
        }

        // Only auto-add the Mutation pillar when we are deriving pillars and a mutation report is actually present.
        if (
            $scorePillars === null
            && $mutationAnalysisResult instanceof MutationAnalysisResult
            && !in_array(Pillar::Mutation->value, $pillarNames, true)
        ) {
            $pillarNames[] = Pillar::Mutation->value;
        }

        $scores = [];

        // Grade each pillar in turn - mutation from its MSI, every other pillar from finding penalties.
        foreach ($pillarNames as $pillarName) {
            // The Mutation pillar is special: it is graded from Infection's MSI, not from finding penalties.
            if ($pillarName === Pillar::Mutation->value) {
                // No mutation report, so the pillar exists but is marked inapplicable and ungraded.
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
     * Scores each file from its findings and ranks the worst, so the report's "fix these first" list
     * puts the most-penalised files on top.
     *
     * @param list<Finding>               $findings - Scored findings bucketed by file path.
     * @param array<int, float>           $penalties - Clustered penalty per finding keyed by spl_object_id() (see findingPenalties()).
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Mutation report whose per-file MSI summaries enrich each file score; null leaves
     *                                                            mutationScore unset.
     * @param int                         $limit - Maximum number of worst-scoring file scores to return.
     *
     * @return list<FileScore> - The worst-grade files first (ties broken by finding count, then path), capped at $limit.
     */
    private function fileScores(array $findings, array $penalties, ?MutationAnalysisResult $mutationAnalysisResult, int $limit): array
    {
        /** @var array<string, list<Finding>> $byFile Accumulator shape is built incrementally from finding file paths. */
        $byFile = [];

        // Group every finding under the file it belongs to.
        foreach ($findings as $finding) {
            $byFile[$finding->filePath]   ??= [];
            $byFile[$finding->filePath][] = $finding;
        }

        $mutationByFile = [];
        // When mutation ran, make sure every file it measured gets a score row, even with no other findings.
        if ($mutationAnalysisResult instanceof MutationAnalysisResult) {
            // Seed each mutation-measured file so its MSI still shows up even at zero findings.
            foreach ($mutationAnalysisResult->report->fileSummaries() as $summary) {
                $mutationByFile[$summary->filePath] = $summary;
                $byFile[$summary->filePath]         ??= [];
            }
        }

        $scores = [];

        // Turn each file's findings into a graded score row.
        foreach ($byFile as $filePath => $fileFindings) {
            $counts          = $this->severityCounts($fileFindings);
            $penalty         = $this->sumPenalties($fileFindings, $penalties) * 5.0;
            // Attach the file's mutation score when Infection measured it.
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
     * Buckets cyclomatic-complexity findings into fixed ranges for the report's histogram, so a user
     * can see at a glance how their methods' complexity is spread.
     *
     * @param list<Finding> $findings - Findings for one score calculation; only cyclomatic-complexity findings contribute to these buckets.
     *
     * @return array<string, int> - Fixed five-bucket cyclomatic histogram keyed by range label; every bucket present, zero when empty.
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

        // Sort each cyclomatic-complexity finding into its range bucket.
        foreach ($findings as $finding) {
            // Only cyclomatic-complexity findings feed the histogram; skip everything else.
            if ($finding->ruleId !== 'complexity.cyclomatic') {
                continue;
            }

            // Read the measured complexity off the finding.
            $complexity = $finding->metadata['complexity'] ?? null;
            // Without a numeric complexity there is nothing to bucket.
            if (!is_int($complexity)) {
                continue;
            }

            // Drop the complexity into its band, from the gentle 1-5 up to the 21+ danger zone.
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
     * Weighs one finding for scoring by multiplying its severity weight by its confidence weight, so a
     * high-confidence error costs far more than a low-confidence advisory.
     *
     * @param Finding $finding - Finding whose severity and confidence set the base weight, before any cluster sharing.
     *
     * @return float - The finding's base penalty (severity weight times confidence weight) before any cluster sharing; always non-negative.
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
     * Weighs every finding for scoring, then clusters correlated complexity/size findings so one root
     * cause is billed once (P5 / ADR-024).
     *
     * Findings that share a `(file, symbol, line)` and whose rule is in
     * {@see self::CORRELATED_COMPLEXITY_RULES} describe one over-large method
     * from different angles. Each such cluster of two or more contributes a
     * single shared weight - the largest member penalty divided by the member
     * count - so a method that is long *and* nested *and* cyclomatically complex
     * subtracts roughly one penalty rather than four. Every finding stays in the
     * report; only its scoring weight is divided across the cluster. Lone
     * findings, and any rule outside the correlated set, keep their full base
     * penalty. The map is keyed by spl_object_id() because the same penalty must
     * follow each finding into both the pillar and file penalty buckets.
     *
     * @param list<Finding> $findings - Scored findings to weight.
     *
     * @return array<int, float> - Penalty per finding, keyed by spl_object_id().
     */
    private function findingPenalties(array $findings): array
    {
        $penalties = [];
        // Give every finding its base weight first.
        foreach ($findings as $finding) {
            $penalties[spl_object_id($finding)] = $this->penaltyFor($finding);
        }

        /** @var array<string, list<Finding>> $clusters Correlated findings grouped by file|symbol|line key. */
        $clusters = [];
        // Now group the correlated size/complexity findings that sit on the same method.
        foreach ($findings as $finding) {
            // Only correlated-rule findings that name a symbol and a line can cluster; skip everything else.
            if (
                !in_array($finding->ruleId, self::CORRELATED_COMPLEXITY_RULES, true)
                || $finding->symbol === null
                || $finding->line === null
            ) {
                continue;
            }

            $key              = $finding->filePath . "\0" . $finding->symbol . "\0" . $finding->line;
            $clusters[$key][] = $finding;
        }

        // Any cluster of two or more findings is one root cause, so it is billed once.
        foreach ($clusters as $cluster) {
            // A lone finding is not a cluster, so it keeps its own full weight.
            if (count($cluster) < 2) {
                continue;
            }

            $shared = max(array_map(fn(Finding $finding): float => $this->penaltyFor($finding), $cluster)) / count($cluster);
            // Split the shared weight across the cluster's findings so together they cost about one penalty.
            foreach ($cluster as $finding) {
                $penalties[spl_object_id($finding)] = $shared;
            }
        }

        return $penalties;
    }

    /**
     * Totals the (already clustered) penalties for a subset of findings - the raw number a pillar or
     * file penalty is built from.
     *
     * @param list<Finding>     $findings - Findings whose weights to total.
     * @param array<int, float> $penalties - Penalty per finding keyed by spl_object_id(), from findingPenalties().
     *
     * @return float - Total post-clustering weight across the subset, fed into the pillar/file penalty multipliers; 0.0 when the subset is empty.
     */
    private function sumPenalties(array $findings, array $penalties): float
    {
        $total = 0.0;
        // Add up each finding's clustered weight, falling back to its base penalty if somehow unlisted.
        foreach ($findings as $finding) {
            $total += $penalties[spl_object_id($finding)] ?? $this->penaltyFor($finding);
        }

        return $total;
    }

    /**
     * Tallies findings by severity for the score summaries and the pillar and file rows.
     *
     * @param list<Finding> $findings - Findings to tally for score summaries; all severities are counted even when the input is empty.
     *
     * @return array{advisory: int, warning: int, error: int} - Finding tally per severity; all three keys always present, zero when none.
     */
    private function severityCounts(array $findings): array
    {
        $counts = ['advisory' => 0, 'warning' => 0, 'error' => 0];

        // Bump the bucket for each finding's severity.
        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * Finds the largest integer metadata value across a rule's findings - how a file's worst complexity
     * reaches its score row.
     *
     * @param list<Finding> $findings - Findings to scan for the requested rule metadata.
     * @param string        $ruleId - Only findings from this rule are considered; others are skipped before reading metadata.
     * @param string        $key - Metadata entry to maximise; non-integer or absent values are ignored.
     *
     * @return int|null - Largest integer metadata value across findings of the given rule; null when none carried the metric (distinct from a
     *                  real 0).
     */
    private function maxMetadataInt(array $findings, string $ruleId, string $key): ?int
    {
        $maximumValue = null;

        // Scan the rule's findings for the highest value of the metric.
        foreach ($findings as $finding) {
            // Only findings from the target rule carry the metric we want.
            if ($finding->ruleId !== $ruleId) {
                continue;
            }

            // Read the metric off the finding's metadata.
            $metricValue = $finding->metadata[$key] ?? null;
            // Skip findings whose metric is missing or not a whole number.
            if (!is_int($metricValue)) {
                continue;
            }

            // Keep the largest value seen so far.
            $maximumValue = $maximumValue === null ? $metricValue : max($maximumValue, $metricValue);
        }

        return $maximumValue;
    }

    /**
     * Finds the largest reported line count across a file's size findings - the "longest thing here"
     * figure shown on the file's score row.
     *
     * @param list<Finding> $findings - Findings for a single file score; only size rules with integer `lines` metadata contribute.
     *
     * @return int|null - Largest `lines` count across file/method/class size findings; null when the file had no size finding reporting a line
     *                  count.
     */
    private function maxLineMetric(array $findings): ?int
    {
        $maximumLines = null;

        // Scan the file's size findings for the biggest line count.
        foreach ($findings as $finding) {
            // Only the file/method/class length rules report a line count.
            if (!in_array($finding->ruleId, ['size.file-length', 'size.method-length', 'size.class-length'], true)) {
                continue;
            }

            // Read the reported line count off the finding.
            $lineCount = $finding->metadata['lines'] ?? null;
            // Skip a finding whose line count is missing or not a whole number.
            if (!is_int($lineCount)) {
                continue;
            }

            // Keep the largest line count seen so far.
            $maximumLines = $maximumLines === null ? $lineCount : max($maximumLines, $lineCount);
        }

        return $maximumLines;
    }
}
