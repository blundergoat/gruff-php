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
 * Builds the score report shown at the top of `gruff-php analyse`, `summary`, and `dashboard` outputs.
 *
 * Converts weighted finding density per evaluated file into pillar grades, a composite grade, and a fix-first offender list:
 * - Static grades use the family-ratified bounded density curve so larger repositories are not penalised for file count.
 * - Correlated size and complexity findings on one file and symbol share one weight instead of double-counting one bad method.
 * - Optional Infection mutation reports populate the Mutation pillar from MSI rather than finding penalties.
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
     * Returns the built-in static-analysis pillars in canonical report order.
     *
     * @return list<Pillar> - Static pillar enum cases matching STATIC_PILLARS order.
     */
    public static function staticPillarOrder(): array
    {
        return [
            Pillar::Size,
            Pillar::Complexity,
            Pillar::Maintainability,
            Pillar::DeadCode,
            Pillar::Naming,
            Pillar::Documentation,
            Pillar::Modernisation,
            Pillar::Security,
            Pillar::SensitiveData,
            Pillar::TestQuality,
        ];
    }

    /**
     * Floor for static density grades; one static pillar can lower an N-pillar composite by at most (100 - floor) / N points.
     *
     * Together with DENSITY_SCALE, this belongs to the family-ratified bounded-normalized-density-floored curve.
     * Changing either value requires a family decision.
     */
    private const SCORE_FLOOR = 50.0;

    /**
     * Finding density, per evaluated file, at which a pillar sits half way between the floor and 100.
     * Ratified alongside SCORE_FLOOR and identical in every port.
     */
    private const DENSITY_SCALE = 0.1;

    /**
     * Correlated size and complexity rules share weight when at least two findings name the same file and symbol.
     *
     * The group contributes its largest base penalty, split equally among members; reported line numbers do not split it.
     * The family scoring contract uses this grouping to avoid charging several symptoms of one method independently.
     */
    private const CORRELATED_COMPLEXITY_RULES = [
        'complexity.cognitive',
        'complexity.cyclomatic',
        'complexity.nesting-depth',
        'size.method-length',
        'size.parameter-count',
    ];

    /**
     * Produces the full score report for a run: applies the scoring filter, builds each pillar row,
     * averages applicable grades into the composite, and ranks the worst files.
     *
     * @param list<Finding>               $findings - Findings considered for scoring; rule settings may exclude some before penalties are calculated.
     * @param int $evaluatedFiles - Parsed PHP file count used as the static scoring denominator; zero leaves static grades unavailable.
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Result for an included Mutation pillar; null means analysis was not run.
     * @param DiffResult|null             $diffResult - Diff result that sets the scope label; null or inactive means a full-project score.
     * @param int                         $fileScoreLimit - Maximum worst-file rows to keep in the report.
     * @param list<Pillar>|null $scorePillars - Pillars to score; null derives them from findings and the built-in set.
     * @param AnalysisConfig|null $analysisConfig - Rule settings whose excludeFromScore flags omit findings; null scores every finding.
     *
     * @return ScoreReport - The composite grade plus per-pillar and per-file scores for the run.
     */
    public function calculate(
        array                   $findings,
        int                     $evaluatedFiles,
        ?MutationAnalysisResult $mutationAnalysisResult,
        ?DiffResult             $diffResult,
        int                     $fileScoreLimit = 10,
        ?array                  $scorePillars = null,
        ?AnalysisConfig         $analysisConfig = null,
    ): ScoreReport {
        $findings   = $this->scoredFindings($findings, $analysisConfig);
        $penalties  = $this->findingPenalties($findings);
        $pillars    = $this->pillarScores($findings, $penalties, $mutationAnalysisResult, $scorePillars, $evaluatedFiles);
        $scoreTotal = 0.0;
        $scoreCount = 0;

        // Average only the pillars that actually applied, so an ungraded pillar cannot drag the composite down.
        foreach ($pillars as $pillar) {
            // Skip inapplicable or ungraded pillars (such as Mutation when no Infection report was supplied).
            if (!$pillar->applicable || !$pillar->grade instanceof Grade) {
                continue;
            }

            $scoreTotal += $pillar->grade->score;
            $scoreCount++;
        }

        // With no applicable graded pillar, the composite is unknown rather than a perfect score.

        $composite = $scoreCount === 0 ? null : Grade::fromScore($scoreTotal / $scoreCount);

        $scope = $diffResult instanceof DiffResult && $diffResult->active ? 'diff' : 'full-project';


        return new ScoreReport(
            composite:              $composite,
            clusters:               $this->correlatedClusters($findings, $penalties),
            ruleAttribution:        $this->ruleAttribution($findings, $penalties),
            evaluatedFiles:         $evaluatedFiles,
            scoredPillars:          array_map(static fn(PillarScore $pillar): string => $pillar->pillar, $pillars),
            pillars:                $pillars,
            topOffenders:           $this->fileScores($findings, $penalties, $mutationAnalysisResult, $fileScoreLimit, $evaluatedFiles),
            complexityDistribution: $this->complexityDistribution($findings),
            scope:                  $scope,
            explanation:            $this->scoreExplanation($mutationAnalysisResult),
        );
    }

    /**
     * Exclude findings from rules configured with excludeFromScore; missing configuration keeps findings in scoring.
     *
     * @param list<Finding> $findings - Findings to filter for scoring.
     * @param AnalysisConfig|null $analysisConfig - Per-rule settings; null leaves the input unchanged.
     *
     * @return list<Finding> - Findings not excluded by rule settings; empty if no input finding remains.
     */
    private function scoredFindings(array $findings, ?AnalysisConfig $analysisConfig): array
    {
        // When no config was supplied (for example in unit tests), keep every finding in the score calculation.
        if (!$analysisConfig instanceof AnalysisConfig) {
            return $findings;
        }

        $rules = $analysisConfig->rules();

        return array_values(array_filter(
                                $findings,
                                static function (Finding $finding) use ($rules): bool {
                                    $settings = $rules[$finding->ruleId] ?? null;

                                    // Honour `excludeFromScore: true` for configured rules so advisory-only rules do not lower the grade.
                                    if ($settings !== null) {
                                        return !$settings->isExcludedFromScore();
                                    }

                                    return true;
                                },
                            ));
    }

    /**
     * Writes the one-paragraph "how this score was reached" note shown under the grade, varying the
     * mutation sentence by whether a report was supplied.
     *
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Selects the MSI explanation when supplied, or the omission explanation when null.
     *
     * @return string - One-paragraph plain-English summary of how the scores were derived, shown verbatim to the user in the report.
     */
    private function scoreExplanation(?MutationAnalysisResult $mutationAnalysisResult): string
    {
        $base = 'Each pillar scores on the density of its weighted findings per evaluated file, on a curve from 50 to 100, so a larger project is not penalised for its size; correlated size and complexity findings on one symbol share a single weight; the composite is the average of applicable pillar scores.';

        // Explain the MSI contribution when the user ran with an Infection mutation report; otherwise explain why Mutation is omitted.
        if ($mutationAnalysisResult instanceof MutationAnalysisResult) {
            return $base . ' Mutation uses the supplied Infection MSI as the mutation pillar score.';
        }

        return $base . ' Mutation is omitted when no Infection report is supplied.';
    }

    /**
     * Grades static pillars from finding density and grades an included Mutation pillar from its MSI.
     *
     * @param list<Finding>               $findings - Scored findings bucketed into per-pillar penalties.
     * @param int $evaluatedFiles - Parsed PHP file count used as the static scoring denominator; zero leaves static grades unavailable.
     * @param array<int, float>           $penalties - Clustered penalty per finding keyed by spl_object_id() (see findingPenalties()).
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Used when Mutation is included; null makes that pillar inapplicable.
     * @param list<Pillar>|null $scorePillars - Pillars to score; null derives them from findings and the built-in set.
     *
     * @return list<PillarScore> - One score per resolved pillar in resolved order; inapplicable pillars remain ungraded.
     */
    private function pillarScores(array $findings, array $penalties, ?MutationAnalysisResult $mutationAnalysisResult, ?array $scorePillars, int $evaluatedFiles): array
    {

        $pillarNames = $scorePillars === null
            ? self::STATIC_PILLARS
            : array_values(array_unique(array_map(static fn(Pillar $pillar): string => $pillar->value, $scorePillars)));

        // When deriving pillars, make sure any pillar a finding belongs to earns a slot, even outside the built-in set.
        if ($scorePillars === null) {
            // Walk scored findings so custom or non-static pillars still appear in the report breakdown.
            foreach ($findings as $finding) {
                // Append unseen pillars once while preserving canonical built-in pillar order first.
                if (!in_array($finding->pillar->value, $pillarNames, true)) {
                    $pillarNames[] = $finding->pillar->value;
                }
            }
        }

        // A supplied mutation report adds its pillar when derivation has not already included it from findings.
        if (
            $scorePillars === null
            && $mutationAnalysisResult instanceof MutationAnalysisResult
            && !in_array(Pillar::Mutation->value, $pillarNames, true)
        ) {
            $pillarNames[] = Pillar::Mutation->value;
        }

        $scores = [];

        // Build one PillarScore row per resolved pillar in report order.
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
            $weight         = $this->sumPenalties($pillarFindings, $penalties);
            $counts         = $this->severityCounts($pillarFindings);

            $scores[] = new PillarScore(
                pillar:     $pillarName,
                applicable: true,
                grade:      $this->curveGrade($weight, $evaluatedFiles),
                findings:   count($pillarFindings),
                advisory:   $counts['advisory'],
                warning:    $counts['warning'],
                error:      $counts['error'],
                penalty:    $weight,
            );
        }

        return $scores;
    }

    /**
     * Converts summed finding weight to a static grade, or returns null when no files were evaluated.
     *
     * Density is weight / evaluated files; the curve is floor + (100 - floor) / (1 + density / DENSITY_SCALE).
     * Proportional increases in finding weight and evaluated files therefore preserve the score.
     *
     * @param float $weight - Non-negative total severity-by-confidence weight after cluster sharing.
     * @param int $evaluatedFiles - Evaluated-file denominator; zero or less leaves the grade unavailable.
     *
     * @return Grade|null - The graded score between the ratified floor and 100, or null when nothing was evaluated.
     */
    private function curveGrade(float $weight, int $evaluatedFiles): ?Grade
    {
        // No evaluated files means no evidence for a grade, rather than evidence of clean code.
        if ($evaluatedFiles <= 0) {
            return null;
        }

        $density = $weight / $evaluatedFiles;

        return Grade::fromScore(self::SCORE_FLOOR + (100.0 - self::SCORE_FLOOR) / (1.0 + $density / self::DENSITY_SCALE));
    }

    /**
     * Builds per-file score rows and orders the report's fix-first list by grade, finding count, and path.
     *
     * @param list<Finding>               $findings - Scored findings bucketed by file path.
     * @param array<int, float>           $penalties - Clustered penalty per finding keyed by spl_object_id() (see findingPenalties()).
     * @param MutationAnalysisResult|null $mutationAnalysisResult - Per-file MSI summaries; null leaves mutationScore unset.
     * @param int                         $limit - Maximum number of worst-scoring file scores to return.
     * @param int $evaluatedFiles - Parsed PHP file count used to decide whether file grades are available; zero leaves them null.
     *
     * @return list<FileScore> - The worst-grade files first (ties broken by finding count, then path), capped at $limit.
     */
    private function fileScores(array $findings, array $penalties, ?MutationAnalysisResult $mutationAnalysisResult, int $limit, int $evaluatedFiles): array
    {
        /** @var array<string, list<Finding>> $byFile - Includes mutation-measured files even when they have no findings. */
        $byFile = [];


        // Group scored findings by their display path so each file's worst metrics can be aggregated.
        foreach ($findings as $finding) {
            $byFile[$finding->filePath]   ??= [];
            $byFile[$finding->filePath][] = $finding;
        }

        $mutationByFile = [];
        // When mutation ran, make sure every file it measured gets a score row, even with no other findings.
        if ($mutationAnalysisResult instanceof MutationAnalysisResult) {
            // Index each Infection per-file summary so file rows can attach its MSI score.
            foreach ($mutationAnalysisResult->report->fileSummaries() as $summary) {
                $mutationByFile[$summary->filePath] = $summary;
                $byFile[$summary->filePath]         ??= [];
            }
        }

        $scores = [];

        // Compute one FileScore row per file with findings or mutation data.
        foreach ($byFile as $filePath => $fileFindings) {
            $counts          = $this->severityCounts($fileFindings);
            // File grades apply the project density curve to that file's weighted findings with a denominator of one.
            $weight          = $this->sumPenalties($fileFindings, $penalties);
            $fileGrade       = $evaluatedFiles <= 0 ? null : $this->curveGrade($weight, 1);

            $mutationSummary = $mutationByFile[$filePath] ?? null;

            $scores[] = new FileScore(
                filePath:      $filePath,
                grade:         $fileGrade,
                findings:      count($fileFindings),
                advisory:      $counts['advisory'],
                warning:       $counts['warning'],
                error:         $counts['error'],
                penalty:       $weight,
                maxCyclomatic: $this->maxMetadataInt($fileFindings, 'complexity.cyclomatic', 'complexity'),
                maxCognitive:  $this->maxMetadataInt($fileFindings, 'complexity.cognitive', 'complexity'),
                maxLines:      $this->maxLineMetric($fileFindings),
                mutationScore: $mutationSummary instanceof MutationFileSummary ? $mutationSummary->msi : null,
            );
        }

        usort($scores, static function (FileScore $leftFileScore, FileScore $rightFileScore): int {
            // Without grades, files rank by descending finding count, then path.
            return ($leftFileScore->grade->score ?? 100.0) <=> ($rightFileScore->grade->score ?? 100.0)
                ?: $rightFileScore->findings <=> $leftFileScore->findings
                    ?: strcmp($leftFileScore->filePath, $rightFileScore->filePath);
        });

        return array_slice($scores, 0, $limit);
    }

    /**
     * Count integer complexity values on scored cyclomatic findings in the report's fixed buckets.
     * This is a finding histogram, so methods without a contributing finding are absent.
     *
     * @param list<Finding> $findings - Scored findings; other rules and non-integer complexity metadata are ignored.
     *
     * @return array<string, int> - Each report range label with its finding count; zero for buckets with no matches.
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


        // Bucket cyclomatic complexity findings into fixed histogram ranges shown in reports.
        foreach ($findings as $finding) {
            // Only `complexity.cyclomatic` carries the cyclomatic branch count used by this histogram.
            if ($finding->ruleId !== 'complexity.cyclomatic') {
                continue;
            }

            $complexity = $finding->metadata['complexity'] ?? null;
            // Ignore malformed or missing complexity metadata rather than skewing the lowest bucket.
            if (!is_int($complexity)) {
                continue;
            }

            // Place the method's cyclomatic score into the matching report histogram range.
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
     * Assigns base penalties and shares the largest penalty across correlated findings with the same file and non-null symbol.
     *
     * Each group of two or more contributes its largest base penalty in total; other findings keep their base penalties.
     * Object IDs keep the same weights attached to findings in both pillar and file calculations.
     *
     * @param list<Finding> $findings - Scored findings to weight.
     *
     * @return array<int, float> - Penalty per finding, keyed by spl_object_id().
     */
    private function findingPenalties(array $findings): array
    {
        $penalties = [];

        // Start every finding at its unclustered severity-by-confidence base penalty.
        foreach ($findings as $finding) {
            $penalties[spl_object_id($finding)] = $this->penaltyFor($finding);
        }

        /** @var array<string, list<Finding>> $clusters - Includes different reported lines for the same file and symbol. */
        $clusters = [];

        // Group correlated size and complexity findings that point to the same file and qualified symbol.
        foreach ($findings as $finding) {
            // Only correlated rules with an enclosing symbol can share a single method-level penalty.
            if (!in_array($finding->ruleId, self::CORRELATED_COMPLEXITY_RULES, true) || $finding->symbol === null) {
                continue;
            }

            // The ratified contract keys clustering on file and qualified symbol without line identity: correlated rules
            // report different lines for one root cause, and gruff-php emits qualified symbols so the key stays exact.
            $key              = $finding->filePath . "\0" . $finding->symbol;
            $clusters[$key][] = $finding;
        }

        // Split the single largest base penalty equally across members of each multi-finding cluster.
        foreach ($clusters as $cluster) {
            // Solo findings are not part of a correlated cluster and keep their full base penalty.
            if (count($cluster) < 2) {
                continue;
            }

            $shared = max(array_map(fn(Finding $finding): float => $this->penaltyFor($finding), $cluster)) / count($cluster);

            // Assign the shared slice to every finding in the cluster so file and pillar totals stay consistent.
            foreach ($cluster as $finding) {
                $penalties[spl_object_id($finding)] = $shared;
            }
        }

        return $penalties;
    }

    /**
     * Lists correlated finding groups that share one scoring weight in the report.
     * Rows are sorted by file and symbol for stable output.
     *
     * @param list<Finding>     $findings - Scored findings for the run.
     * @param array<int, float> $penalties - Clustered weight per finding keyed by spl_object_id().
     *
     * @return list<array{file: string, symbol: string, ruleIds: list<string>, findings: int, weight: float}> - One row per cluster of two or more.
     */
    private function correlatedClusters(array $findings, array $penalties): array
    {
        /** @var array<string, list<Finding>> $groups - Includes different reported lines for the same file and symbol. */
        $groups = [];

        // Bucket correlated findings by file and symbol so report rows match the penalties calculated above.
        foreach ($findings as $finding) {
            // Skip uncorrelated rules and file-level findings without a qualified symbol.
            if (!in_array($finding->ruleId, self::CORRELATED_COMPLEXITY_RULES, true) || $finding->symbol === null) {
                continue;
            }

            $groups[$finding->filePath . "\0" . $finding->symbol][] = $finding;
        }

        $clusters = [];

        // Format each group of two or more findings into a report-ready cluster summary row.
        foreach ($groups as $group) {
            // A single finding did not share weight with anything, so omit it from the cluster breakdown.
            if (count($group) < 2) {
                continue;
            }

            $ruleIds = array_map(static fn(Finding $finding): string => $finding->ruleId, $group);
            sort($ruleIds);
            $first      = $group[0];
            $clusters[] = [
                'file'     => $first->filePath,
                'symbol'   => (string)$first->symbol,
                'ruleIds'  => $ruleIds,
                'findings' => count($group),
                'weight'   => round($this->sumPenalties($group, $penalties), 2),
            ];
        }

        usort($clusters, static fn(array $left, array $right): int => [$left['file'], $left['symbol']] <=> [$right['file'], $right['symbol']]);

        return $clusters;
    }

    /**
     * Groups scored findings by native rule ID and reports each rule's total finding weight.
     *
     * These are weights before the density curve, not points subtracted from a grade.
     * Native rule IDs remain the attribution keys; rows are sorted by those IDs.
     *
     * @param list<Finding>     $findings - Scored findings for the run.
     * @param array<int, float> $penalties - Clustered weight per finding keyed by spl_object_id().
     *
     * @return list<array{ruleId: string, findings: int, weight: float}> - One row per native rule in the findings, sorted by rule ID.
     */
    private function ruleAttribution(array $findings, array $penalties): array
    {
        $counts  = [];
        $weights = [];

        // Accumulate finding count and clustered weight per rule ID so users can see which rules cost the most grade weight.
        foreach ($findings as $finding) {
            $counts[$finding->ruleId]  = ($counts[$finding->ruleId] ?? 0) + 1;
            $weights[$finding->ruleId] = ($weights[$finding->ruleId] ?? 0.0) + ($penalties[spl_object_id($finding)] ?? $this->penaltyFor($finding));
        }

        ksort($counts);
        $attribution = [];

        // Build deterministic sorted attribution rows rounded to two decimal places for display.
        foreach ($counts as $ruleId => $count) {
            $attribution[] = ['ruleId' => (string)$ruleId, 'findings' => $count, 'weight' => round($weights[$ruleId], 2)];
        }

        return $attribution;
    }

    /**
     * Totals the (already clustered) penalties for a subset of findings - the raw number a pillar or
     * file penalty is built from.
     *
     * @param list<Finding>     $findings - Findings whose weights to total.
     * @param array<int, float> $penalties - Penalty per finding keyed by spl_object_id(), from findingPenalties().
     *
     * @return float - Total supplied weights, using base penalties for missing entries; 0.0 for an empty list.
     */
    private function sumPenalties(array $findings, array $penalties): float
    {
        $total = 0.0;

        // Add each finding's clustered penalty, falling back to its base penalty if absent from the map.
        foreach ($findings as $finding) {
            $total += $penalties[spl_object_id($finding)] ?? $this->penaltyFor($finding);
        }

        return $total;
    }

    /**
     * Tallies findings by severity for the score summaries and the pillar and file rows.
     *
     * @param list<Finding> $findings - Findings to tally for score summaries; an empty list yields zero counts.
     *
     * @return array{advisory: int, warning: int, error: int} - Finding tally per severity; all three keys always present, zero when none.
     */
    private function severityCounts(array $findings): array
    {
        $counts = ['advisory' => 0, 'warning' => 0, 'error' => 0];

        // Count advisory, warning, and error findings separately for summary tables.
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
     * @return int|null - Maximum matching integer, including 0; null when no matching integer exists.
     */
    private function maxMetadataInt(array $findings, string $ruleId, string $key): ?int
    {
        $maximumValue = null;

        // Scan the file's findings for the highest integer metric emitted by the requested rule.
        foreach ($findings as $finding) {
            // Skip findings emitted by other rules.
            if ($finding->ruleId !== $ruleId) {
                continue;
            }

            $metricValue = $finding->metadata[$key] ?? null;
            // Ignore absent or non-integer metadata values so malformed entries never crash scoring.
            if (!is_int($metricValue)) {
                continue;
            }

            $maximumValue = $maximumValue === null ? $metricValue : max($maximumValue, $metricValue);
        }

        return $maximumValue;
    }

    /**
     * Finds the largest reported line count across a file's size findings - the "longest thing here"
     * figure shown on the file's score row.
     *
     * @param list<Finding> $findings - Findings for one file; only size rules with integer lines metadata contribute.
     *
     * @return int|null - Largest integer lines value from file-, method-, or class-length findings; null if none exists.
     */
    private function maxLineMetric(array $findings): ?int
    {
        $maximumLines = null;

        // Find the largest reported line count across file, method, and class size findings for this file.
        foreach ($findings as $finding) {
            // Only the three length-based size rules record a line-count metric.
            if (!in_array($finding->ruleId, ['size.file-length', 'size.method-length', 'size.class-length'], true)) {
                continue;
            }

            $lineCount = $finding->metadata['lines'] ?? null;
            // Skip findings that omitted or non-integer-encoded their line count.
            if (!is_int($lineCount)) {
                continue;
            }

            $maximumLines = $maximumLines === null ? $lineCount : max($maximumLines, $lineCount);
        }

        return $maximumLines;
    }
}
