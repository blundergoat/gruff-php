<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

use GruffPhp\Results\Baseline\BaselineReport;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Mutation\MutationAnalysisResult;
use GruffPhp\Output\Reporter\FindingDisplayFilter;
use GruffPhp\Output\Reporter\ThresholdTrip;
use GruffPhp\Results\Review\BranchReviewResult;
use GruffPhp\Results\Scoring\ScoreReport;
use GruffPhp\Engine\Source\IgnoredPath;
use GruffPhp\Results\Trend\TrendReport;

/**
 * The complete result of one analysis run - everything every reporter format needs to render, in one place.
 *
 * When gruff finishes analysing, it packs the whole outcome into this readonly report: the findings,
 * the file counts, the score, and every optional section a run might have produced (mutation, diff,
 * trend, baseline, branch review). Whichever way the user asked to see results - terminal text, JSON,
 * SARIF, HTML - the reporter reads from this one object, so the text they read and the JSON their CI
 * parses always describe the same run.
 *
 * @phpstan-type ReportScalar bool|float|int|object|string|null
 * @phpstan-type ReportValue ReportScalar|array<array-key, ReportScalar|array<array-key, ReportScalar|array<array-key, ReportScalar|array<array-key,
 *               ReportScalar|array<array-key, ReportScalar>>>>>
 */
final readonly class AnalysisReport
{
    /**
     * Tool name emitted in human-readable and machine-readable reports.
     */
    public const TOOL_NAME = 'gruff-php';

    /**
     * Stable schema identifier emitted in machine-readable reports.
     */
    public const SCHEMA_VERSION = 'gruff.analysis.v2';

    /**
     * Gathers everything one analysis run produced into the single object every reporter renders from.
     *
     * @param string                      $toolVersion - Gruff version used to produce the report.
     * @param list<string>                $requestedPaths - Paths requested for analysis.
     * @param string                      $format - Output format requested for report serialization.
     * @param string                      $failOn - Severity gate used to determine the process exit code.
     * @param int                         $filesDiscovered - Number of source files discovered before parsing.
     * @param int                         $filesParsed - Number of source files parsed successfully.
     * @param list<string>                $ignoredPaths - Requested paths ignored by discovery.
     * @param list<string>                $missingPaths - Requested paths that did not resolve to files.
     * @param list<RunDiagnostic>         $diagnostics - Non-finding diagnostics emitted during the run.
     * @param list<Finding>               $findings - Findings included in the report.
     * @param int                         $exitCode - Process exit code represented by the report.
     * @param string|null                 $configPath - Config file path used for the run; null when the run used no config.
     * @param MutationAnalysisResult|null $mutation - Mutation analysis result; null when mutation analysis was not run.
     * @param ScoreReport|null            $score - Score summary; null when scoring was not produced for the run.
     * @param DiffResult|null             $diff - Diff context; null when the run was not scoped to changed regions.
     * @param TrendReport|null            $trend - Trend history; null when no trend recording was requested.
     * @param BaselineReport|null         $baseline - Baseline application result; null when no baseline was applied.
     * @param BranchReviewResult|null     $review - Branch review result; null when the run was not a --diff-vs review.
     * @param FindingDisplayFilter|null   $filters - Display filters applied to the output; null when none were active.
     * @param int|null                    $suppressedCount - Findings hidden by changed-region filtering; null when no such filtering ran.
     * @param list<IgnoredPath>           $ignoredPathDetails - Ignored paths enriched with source and matching pattern.
     * @param bool                        $shouldListAbsentBaseline - Whether reporters should list resolved (absent) baseline entries.
     * @param ThresholdTrip|null          $failureReason - Gate threshold that tripped; null when the run did not fail a count threshold.
     * @param int|null                    $newFindingsCount - Size of the new-findings set; null when no new-findings gate is active.
     * @param list<SensitiveExclusionSummary> $sensitiveExclusions - One audit row per configured sensitive-data exclusion; empty when the run configured none.
     */
    public function __construct(
        public string                  $toolVersion,
        public array                   $requestedPaths,
        public string                  $format,
        public string                  $failOn,
        public int                     $filesDiscovered,
        public int                     $filesParsed,
        public array                   $ignoredPaths,
        public array                   $missingPaths,
        public array                   $diagnostics,
        public array                   $findings,
        public int                     $exitCode,
        public ?string                 $configPath = null,
        public ?MutationAnalysisResult $mutation = null,
        public ?ScoreReport            $score = null,
        public ?DiffResult             $diff = null,
        public ?TrendReport            $trend = null,
        public ?BaselineReport         $baseline = null,
        public ?BranchReviewResult     $review = null,
        public ?FindingDisplayFilter   $filters = null,
        public ?int                    $suppressedCount = null,
        public array                   $ignoredPathDetails = [],
        public bool                    $shouldListAbsentBaseline = false,
        public ?ThresholdTrip          $failureReason = null,
        public ?int                    $newFindingsCount = null,
        public array                   $sensitiveExclusions = [],
    ) {
    }

    /**
     * Tallies findings by severity for the report's headline summary line.
     *
     * @return array{advisory: int, warning: int, error: int, total: int} - per-severity finding tallies plus a precomputed total; each count is zero
     *                         when no finding hit that severity.
     */
    public function findingCounts(): array
    {
        $counts = [
            'advisory' => 0,
            'warning'  => 0,
            'error'    => 0,
            'total'    => count($this->findings),
        ];

        // Sort each finding into its severity bucket.
        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * Tallies findings per rule with a severity breakdown for triage views, surfacing the noisiest rule
     * first.
     *
     * Sorted by total descending then ruleId ascending so the noisiest rules surface first
     * and ordering stays deterministic across runs.
     *
     * @return list<array{ruleId: string, total: int, advisory: int, warning: int, error: int}> - one row per triggered rule, ordered noisiest-first
     *                            (total descending, then ruleId ascending); empty when there are no findings.
     */
    public function findingCountsByRule(): array
    {
        $byRule = [];

        // Tally each finding under its rule, with a per-severity breakdown.
        foreach ($this->findings as $finding) {
            $byRule[$finding->ruleId] ??= ['total' => 0, 'advisory' => 0, 'warning' => 0, 'error' => 0];
            $byRule[$finding->ruleId]['total']++;
            $byRule[$finding->ruleId][$finding->severity->value]++;
        }

        $rows = [];

        // Flatten the per-rule tallies into rows ready for sorting.
        foreach ($byRule as $ruleId => $counts) {
            $rows[] = [
                'ruleId'   => $ruleId,
                'total'    => $counts['total'],
                'advisory' => $counts['advisory'],
                'warning'  => $counts['warning'],
                'error'    => $counts['error'],
            ];
        }

        usort(
            $rows,
            static fn(array $left, array $right): int => $right['total'] <=> $left['total']
                ?: strcmp($left['ruleId'], $right['ruleId']),
        );

        return $rows;
    }

    /**
     * Counts how many files failed to parse, so the summary can warn the user some code went unscanned.
     *
     * @return int - count of parse-error diagnostics only; other diagnostic types (e.g. baseline-error) are not tallied here, so zero means every
     *             file parsed cleanly.
     */
    public function parseErrorCount(): int
    {
        return count(array_filter(
                         $this->diagnostics,
                         static fn(RunDiagnostic $diagnostic): bool => $diagnostic->type === 'parse-error',
                     ));
    }

    /**
     * Flattens the whole report into the reporter wire shape, so JSON, SARIF, and HTML all serialise the
     * same run - with each optional section present only when the run actually produced it.
     *
     * @return array<string, ReportValue> - the report serialized to the reporter wire shape; optional sections (mutation, score, diff, etc.) are
     *                       present only when populated.
     */
    public function toArray(): array
    {
        $report = [
            'schemaVersion'      => self::SCHEMA_VERSION,
            'tool'               => [
                'name'    => self::TOOL_NAME,
                'version' => $this->toolVersion,
            ],
            'run'                => [
                'format'  => $this->format,
                'failOn'  => $this->failOn,
                'config'  => $this->configPath,
                'paths'   => $this->requestedPaths,
                'filters' => $this->filters?->toArray(),
            ],
            'summary'            => [
                'filesDiscovered' => $this->filesDiscovered,
                'filesParsed'     => $this->filesParsed,
                'ignoredPaths'    => count($this->ignoredPaths),
                'missingPaths'    => count($this->missingPaths),
                'parseErrors'     => $this->parseErrorCount(),
                'findings'        => $this->findingCounts(),
                'exitCode'        => $this->exitCode,
            ],
            'ignoredPaths'       => $this->ignoredPaths,
            'ignoredPathDetails' => array_map(
                static fn(IgnoredPath $ignoredPath): array => $ignoredPath->toArray(),
                $this->ignoredPathDetails,
            ),
            'missingPaths'       => $this->missingPaths,
            'diagnostics'        => array_map(
                static fn(RunDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            ),
            'findings'           => array_map(
                static fn(Finding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            // Every configured sensitive exclusion publishes a row here, matched or not, so a suppressed
            // finding is accounted for rather than silently absent from the list above.
            'suppressions'       => array_map(
                static fn(SensitiveExclusionSummary $summary): array => $summary->toArray(),
                $this->sensitiveExclusions,
            ),
        ];

        // Include the suppressed count only when changed-region filtering actually hid some findings.
        if ($this->suppressedCount !== null) {
            $report['suppressedCount'] = $this->suppressedCount;
        }

        // Include the failure reason only when the run tripped a count threshold.
        if ($this->failureReason instanceof ThresholdTrip) {
            $report['failureReason'] = $this->failureReason->toArray();
        }

        // Include the new-findings count only when a new-findings gate was active.
        if ($this->newFindingsCount !== null) {
            $report['newFindingsCount'] = $this->newFindingsCount;
        }

        // Attach the mutation section only when mutation analysis ran.
        if ($this->mutation instanceof MutationAnalysisResult) {
            $report['mutation'] = $this->mutation->toArray();
        }

        // Attach the score section only when scoring was produced.
        if ($this->score instanceof ScoreReport) {
            $report['score'] = $this->score->toArray();
        }

        // Attach the diff section only on a changed-region run.
        if ($this->diff instanceof DiffResult) {
            $report['diff'] = $this->diff->toArray();
        }

        // Attach the trend section only when trend history was recorded.
        if ($this->trend instanceof TrendReport) {
            $report['trend'] = $this->trend->toArray();
        }

        // Attach the baseline section only when a baseline was applied.
        if ($this->baseline instanceof BaselineReport) {
            $report['baseline'] = $this->baseline->toArray();
        }

        // Attach the branch-review section only on a --diff-vs review run.
        if ($this->review instanceof BranchReviewResult) {
            $report['review'] = $this->review->toArray();
        }

        return $report;
    }

    /**
     * Reports whether any finding reached a given severity - the check the exit-code gate uses to decide
     * whether the run passed or failed.
     *
     * @param Severity $severity - Severity level to look for in the finding list.
     *
     * @return bool - true on the first finding at the requested severity (the gate only needs one); false means nothing in the report reached that
     *              level.
     */
    public function hasFindingsAtSeverity(Severity $severity): bool
    {
        // Scan for the first finding at the requested severity - one is all the gate needs.
        foreach ($this->findings as $finding) {
            // A finding at exactly this severity is enough to answer yes.
            if ($finding->severity === $severity) {
                return true;
            }
        }

        return false;
    }
}
