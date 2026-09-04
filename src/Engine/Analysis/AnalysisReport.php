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
use GruffPhp\Engine\Source\PathIgnoreResolver;
use GruffPhp\Support\PathHelper;
use GruffPhp\Results\Trend\TrendReport;
use LogicException;

/**
 * The complete result of one analysis run - everything every reporter format needs to render, in one place.
 *
 * When gruff finishes analysing, it packs the whole outcome into this readonly report: the findings,
 * the file counts, the score, and every optional section a run might have produced (mutation, diff,
 * trend, baseline, branch review). Whichever way the user asked to see results - terminal text, JSON,
 * SARIF, HTML - the reporter reads from this one object, so the text they read and the JSON their CI
 * parses always describe the same run.
 *
 * @phpstan-type ReportValue bool|float|int|object|string|null|array<array-key, mixed>
 * @phpstan-type MachinePathDetail array{path: string, reason: string, source: string, pattern?: string}
 * @phpstan-type MachineReportContext array{
 *     ignoredPathDetails: list<IgnoredPath>,
 *     shouldIncludeIgnored: bool,
 *     projectRoot: string,
 *     unfilteredFindings: list<Finding>|null
 * }
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
    public const SCHEMA_VERSION = 'gruff.analysis.v3';

    /**
     * Stable schema identifier for the findings-free projection of an analysis report.
     */
    public const SUMMARY_SCHEMA_VERSION = 'gruff.summary.v3';

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
     * @param bool                        $shouldListAbsentBaseline - Whether reporters should list resolved (absent) baseline entries.
     * @param ThresholdTrip|null          $failureReason - Gate threshold that tripped; null when the run did not fail a count threshold.
     * @param int|null                    $newFindingsCount - Size of the new-findings set; null when no new-findings gate is active.
     * @param list<SensitiveExclusionSummary> $sensitiveExclusions - One audit row per configured sensitive-data exclusion; empty when the run configured none.
     * @param MachineReportContext        $machineContext - Serializer-only paths, run flags, root, and pre-display finding data.
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
        public bool                    $shouldListAbsentBaseline = false,
        public ?ThresholdTrip          $failureReason = null,
        public ?int                    $newFindingsCount = null,
        public array                   $sensitiveExclusions = [],
        private array                  $machineContext = [
            'ignoredPathDetails' => [],
            'shouldIncludeIgnored' => false,
            'projectRoot' => '.',
            'unfilteredFindings' => null,
        ],
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
     * Projects the in-memory PHP report into the strict family analysis envelope.
     *
     * @return array<string, ReportValue> - Canonical v3 analysis document with optional data omitted when absent.
     */
    public function toArray(): array
    {
        $details         = array_map(fn(IgnoredPath $ignoredPath): array => $this->machinePathDetail($ignoredPath), $this->machineContext['ignoredPathDetails']);
        $ignoredPaths    = array_map(static fn(array $detail): string => $detail['path'], $details);
        $summaryFindings = $this->machineContext['unfilteredFindings'] ?? $this->findings;
        $runMetadata     = [
            'failOn' => $this->failOn,
            'format' => $this->format,
            'inputs' => $this->machinePaths($this->requestedPaths),
            'projectRoot' => '.',
        ];

        if ($this->configPath !== null) {
            $configPath = $this->machineRelativePath($this->configPath);
            if ($configPath !== null) {
                $runMetadata['config'] = $configPath;
            }
        }

        if ($this->filters instanceof FindingDisplayFilter && $this->filters->isActive()) {
            $runMetadata['filters'] = $this->machineRunFilters($this->filters);
        }

        if ($this->machineContext['shouldIncludeIgnored']) {
            $runMetadata['includeIgnored'] = true;
        }

        $report = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'tool' => [
                'name' => self::TOOL_NAME,
                'version' => $this->toolVersion,
            ],
            'run' => $runMetadata,
            'summary' => [
                'analysedFiles' => $this->filesParsed,
                'diagnostics' => count($this->diagnostics),
                'discoveredFiles' => $this->filesDiscovered,
                'exitCode' => $this->exitCode,
                'findings' => self::findingCountsFor($summaryFindings),
                'findingsByPillar' => self::findingCountsByPillarFor($summaryFindings),
                'ignoredPaths' => count($ignoredPaths),
                'missingPaths' => count($this->missingPaths),
                'parseErrors' => $this->parseErrorCount(),
                'parsedFiles' => $this->filesParsed,
                'skippedFiles' => count($details),
            ],
            'score' => $this->machineScore(),
            'diagnostics' => array_map(fn(RunDiagnostic $diagnostic): array => $this->machineDiagnostic($diagnostic), $this->diagnostics),
            'findings' => array_map(fn(Finding $finding): array => $this->machineFinding($finding), $this->findings),
            'paths' => [
                'analysedFiles' => $this->filesParsed,
                'details' => $details,
                'ignoredPaths' => $ignoredPaths,
                'missingPaths' => $this->machinePaths($this->missingPaths),
            ],
            'suppressions' => array_map(
                fn(SensitiveExclusionSummary $summary): array => $this->machineSuppression($summary),
                $this->sensitiveExclusions,
            ),
        ];

        if ($this->suppressedCount !== null) {
            $report['summary']['suppressedFindings'] = $this->suppressedCount;
        }

        if ($this->baseline instanceof BaselineReport || $this->newFindingsCount !== null) {
            $report['baseline'] = $this->machineBaseline($this->baseline);
        }

        if ($this->diff instanceof DiffResult && $this->diff->active) {
            $report['diff'] = $this->machineDiff($this->diff);
        }

        if ($this->filters instanceof FindingDisplayFilter && $this->filters->isActive()) {
            $report['displayFilter'] = $this->machineDisplayFilter($this->filters, count($summaryFindings) - count($this->findings));
        }

        $extensions = $this->machineExtensions();
        if ($extensions !== []) {
            $report['extensions'] = $extensions;
        }

        return $report;
    }

    /**
     * Produces the only supported compact machine payload from the canonical analysis document.
     *
     * @return array<string, ReportValue> - Analysis v3 with only findings removed and the schema identifier changed.
     */
    public function toSummaryArray(): array
    {
        $summary                  = $this->toArray();
        $summary['schemaVersion'] = self::SUMMARY_SCHEMA_VERSION;
        unset($summary['findings']);

        return $summary;
    }

    /**
     * Counts a complete finding collection by canonical severity without changing its order or identity.
     *
     * @param list<Finding> $findings - Findings whose severities contribute to the machine summary.
     *
     * @return array{advisory: int, warning: int, error: int, total: int} - Canonical severity totals, including zero-valued buckets.
     */
    private static function findingCountsFor(array $findings): array
    {
        $counts = ['advisory' => 0, 'warning' => 0, 'error' => 0, 'total' => count($findings)];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * Counts a complete finding collection by its primary pillar for the machine summary.
     *
     * @param list<Finding> $findings - Findings whose primary pillars contribute to the summary.
     *
     * @return array<string, int>|object - Sorted pillar totals, or an empty JSON object when there are no findings.
     */
    private static function findingCountsByPillarFor(array $findings): array|object
    {
        $counts = [];

        foreach ($findings as $finding) {
            $counts[$finding->pillar->value] = ($counts[$finding->pillar->value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts === [] ? (object)[] : $counts;
    }

    /**
     * Adapts PHP display-filter settings to the canonical run metadata shape.
     *
     * @param FindingDisplayFilter $filters - Active filter settings recorded for this run.
     *
     * @return array<string, bool|string|list<string>> - Filter metadata with an absent minimum severity omitted.
     */
    private function machineRunFilters(FindingDisplayFilter $filters): array
    {
        $payload = $filters->toArray();
        if ($payload['minSeverity'] === null) {
            unset($payload['minSeverity']);
        }

        return $payload;
    }

    /**
     * Maps one native finding to the v3 wire shape while preserving both identity values.
     *
     * @param Finding $finding - Native finding to serialize without changing its identity inputs.
     *
     * @return array<string, ReportValue> - Canonical finding fields with unavailable optional locations omitted.
     */
    private function machineFinding(Finding $finding): array
    {
        $metadata                      = $finding->metadata;
        $metadata['locationPrecision'] = $finding->column === null ? 'line-only' : 'scanner-pinpointed';
        $payload                       = [
            'ruleId' => $finding->ruleId,
            'message' => $finding->message,
            'file' => $this->machinePath($finding->filePath),
            'line' => max(1, $finding->line ?? 1),
            'severity' => $finding->severity->value,
            'pillar' => $finding->pillar->value,
            'secondaryPillars' => array_map(static fn($pillar): string => $pillar->value, $finding->secondaryPillars),
            'tier' => $finding->tier->value,
            'confidence' => $finding->confidence->value,
            'remediation' => $finding->remediation ?? '',
            'fingerprint' => $finding->fingerprint(),
            'stableIdentity' => $finding->stableIdentity(),
            'metadata' => $metadata,
        ];

        if ($finding->endLine !== null) {
            $payload['endLine'] = max(1, $finding->endLine);
        }

        if ($finding->column !== null) {
            $payload['column'] = max(1, $finding->column);
        }

        if ($finding->symbol !== null && $finding->symbol !== '') {
            $payload['symbol'] = $finding->symbol;
        }

        return $payload;
    }

    /**
     * Maps one run diagnostic to the canonical v3 diagnostic shape.
     *
     * @param RunDiagnostic $diagnostic - Native diagnostic emitted while loading or parsing the run.
     *
     * @return array<string, ReportValue> - Canonical diagnostic with optional file and line data omitted when absent.
     */
    private function machineDiagnostic(RunDiagnostic $diagnostic): array
    {
        $payload = [
            'type' => $diagnostic->type,
            'message' => $diagnostic->message,
            'invalidatesRun' => $diagnostic->isFatal,
        ];
        $filePath = $diagnostic->filePath ?? $diagnostic->path;

        if ($filePath !== null && $filePath !== '') {
            $payload['file'] = $this->machinePath($filePath);
        }

        if ($diagnostic->line !== null && $diagnostic->line > 0) {
            $payload['line'] = $diagnostic->line;
        }

        return $payload;
    }

    /**
     * Projects one ignored path into the auditable M15 detail shape.
     *
     * @param IgnoredPath $ignoredPath - Native ignored-path record carrying its source and optional pattern.
     *
     * @return MachinePathDetail - Project-relative path detail with a canonical skip reason.
     */
    private function machinePathDetail(IgnoredPath $ignoredPath): array
    {
        $payload = [
            'path' => $this->machinePath($ignoredPath->path),
            'reason' => self::skipReason($ignoredPath),
            'source' => $ignoredPath->source,
        ];

        if ($ignoredPath->pattern !== null && $ignoredPath->pattern !== '') {
            $payload['pattern'] = $ignoredPath->pattern;
        }

        return $payload;
    }

    /**
     * Translates a native ignore source and pattern into the closed family reason vocabulary.
     *
     * @param IgnoredPath $ignoredPath - Native ignored-path record to classify.
     *
     * @return string - Canonical M15 reason associated with the observed source and pattern.
     * @throws LogicException When the source or default pattern has no canonical reason.
     */
    private static function skipReason(IgnoredPath $ignoredPath): string
    {
        if ($ignoredPath->source === PathIgnoreResolver::SOURCE_CONFIG) {
            return 'config-ignore';
        }

        if ($ignoredPath->source === PathIgnoreResolver::SOURCE_GITIGNORE) {
            return 'gitignored';
        }

        if ($ignoredPath->source === PathIgnoreResolver::SOURCE_GENERATED) {
            return 'generated';
        }

        if ($ignoredPath->source !== PathIgnoreResolver::SOURCE_DEFAULT) {
            throw new LogicException(sprintf('Unknown ignored-path source "%s".', $ignoredPath->source));
        }

        return match ($ignoredPath->pattern) {
            '.git', '.hg', '.svn' => 'vcs',
            'node_modules', 'vendor' => 'dependency',
            'build', 'coverage', 'dist' => 'build-output',
            '.fleet', '.idea', '.vscode' => 'local-tooling',
            '.gruff-cache', '.phpunit.cache', 'var/cache' => 'tool-cache',
            default => throw new LogicException(sprintf('Unknown default ignored-path pattern "%s".', $ignoredPath->pattern ?? '')),
        };
    }

    /**
     * Projects one configured sensitive exclusion into its non-secret audit record.
     *
     * @param SensitiveExclusionSummary $summary - Exclusion result containing only approved audit fields.
     *
     * @return array<string, ReportValue> - Canonical suppression record with an optional symbol.
     */
    private function machineSuppression(SensitiveExclusionSummary $summary): array
    {
        $payload = [
            'index' => $summary->index,
            'rule' => $summary->rule,
            'paths' => $this->machinePaths([$summary->path]),
            'reason' => $summary->reason,
            'suppressed' => $summary->suppressed,
        ];

        if ($summary->symbol !== null && $summary->symbol !== '') {
            $payload['symbol'] = $summary->symbol;
        }

        return $payload;
    }

    /**
     * Adapts the native score container without recalculating any M06-owned score value.
     *
     * @return array<string, ReportValue> - Canonical score data, or the structural unscored representation.
     */
    private function machineScore(): array
    {
        if (!$this->score instanceof ScoreReport) {
            return [
                // No scoring ran, so there is no number to report; a 0.0 here would read as a failing grade.
                'composite' => ['grade' => null, 'score' => null],
                'clusters' => [],
                'ruleAttribution' => [],
                'pillars' => [],
                'topOffenders' => [],
                'coverage' => [
                    'contributingPillars' => [],
                    'caveat' => 'No score was produced for this run.',
                ],
            ];
        }

        $payload                 = $this->score->toArray();
        $payload['pillars']      = array_map(self::withoutNullValues(...), $payload['pillars']);
        $payload['topOffenders'] = array_map(function (array $offender): array {
            $offender['file'] = $this->machinePath($offender['file']);

            return self::withoutNullValues($offender);
        }, $payload['topOffenders']);

        if ($payload['complexityDistribution'] === []) {
            $payload['complexityDistribution'] = (object)[];
        }

        return $payload;
    }

    /**
     * Adapts baseline structure while leaving M07-owned matching semantics unchanged.
     *
     * @param BaselineReport|null $baseline - Applied native baseline result, or null for a gate-only count.
     *
     * @return array<string, ReportValue> - Canonical baseline fields plus PHP-owned bucket extensions.
     */
    private function machineBaseline(?BaselineReport $baseline): array
    {
        if (!$baseline instanceof BaselineReport) {
            return ['newFindings' => $this->newFindingsCount ?? 0];
        }

        $legacy  = $baseline->toArray();
        $payload = [
            'entries' => $baseline->totalEntries,
            'generated' => $baseline->generated,
            'source' => $baseline->source,
            'stale' => $legacy['stale'],
            'staleEntries' => count($baseline->staleEntries),
            'staleEvaluation' => $baseline->staleEvaluation,
            'suppressedFindings' => $baseline->suppressedFindings,
            'extensions' => [
                'php' => ['baseline' => ['buckets' => $legacy['buckets']]],
            ],
        ];
        $baselinePath = $this->machineRelativePath($baseline->path);
        if ($baselinePath !== null) {
            $payload['path'] = $baselinePath;
        }

        if ($this->newFindingsCount !== null) {
            $payload['newFindings'] = $this->newFindingsCount;
        }

        return $payload;
    }

    /**
     * Projects active changed-region context into the canonical diff container.
     *
     * @param DiffResult $diff - Active native diff result whose paths are made project-relative.
     *
     * @return array<string, ReportValue> - Canonical diff fields with absent optional values omitted.
     */
    private function machineDiff(DiffResult $diff): array
    {
        $payload = [
            'changedFileCount' => count($diff->changedFiles),
            'changedFiles' => $this->machinePaths($diff->changedFiles),
            'enabled' => true,
            'message' => $diff->message,
            'mode' => $diff->mode,
        ];

        if ($diff->base !== null && $diff->base !== '') {
            $payload['base'] = $diff->base;
        }

        if ($diff->suppressedCount !== null) {
            $payload['filteredFindings'] = $diff->suppressedCount;
        }

        return $payload;
    }

    /**
     * Records how an active display filter changed only the rendered finding list.
     *
     * @param FindingDisplayFilter $filters        - Active filter settings applied to rendered findings.
     * @param int                  $hiddenFindings - Number of full-run findings omitted from the rendered list.
     *
     * @return array<string, ReportValue> - Canonical display-filter metadata and hidden count.
     */
    private function machineDisplayFilter(FindingDisplayFilter $filters, int $hiddenFindings): array
    {
        $payload                   = $this->machineRunFilters($filters);
        $payload['applied']        = true;
        $payload['hiddenFindings'] = max(0, $hiddenFindings);
        unset($payload['active'], $payload['minSeverity']);

        return $payload;
    }

    /**
     * Isolates native top-level features under PHP's named extension namespace.
     *
     * @return array<string, ReportValue> - PHP extension data, or an empty array when no native feature is active.
     */
    private function machineExtensions(): array
    {
        $topLevel = [];

        if ($this->failureReason instanceof ThresholdTrip) {
            $topLevel['failureReason'] = $this->failureReason->toArray();
        }

        if ($this->mutation instanceof MutationAnalysisResult) {
            $topLevel['mutation'] = $this->mutation->toArray();
        }

        if ($this->review instanceof BranchReviewResult) {
            $topLevel['review'] = $this->review->toArray();
        }

        if ($this->trend instanceof TrendReport) {
            $topLevel['trend'] = $this->trend->toArray();
        }

        return $topLevel === [] ? [] : ['php' => ['topLevel' => $topLevel]];
    }

    /**
     * Removes unavailable optional values from one native score row before serialization.
     *
     * `score` and `grade` are kept even when null. Under the ratified scoring contract a null there
     * is a statement - nothing applicable was evaluated - and omitting the key would leave a consumer
     * unable to tell that apart from a port that never published the field at all.
     *
     * @param array<string, ReportValue> $values - Native row whose null entries mean the field is unavailable.
     *
     * @return array<string, ReportValue> - The same row with null-valued optional fields omitted, except the two ratified score fields.
     */
    private static function withoutNullValues(array $values): array
    {
        $ratifiedNullableKeys = ['score', 'grade'];

        return array_filter(
            $values,
            static fn(mixed $fieldValue, string $fieldName): bool => $fieldValue !== null || in_array($fieldName, $ratifiedNullableKeys, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Normalizes and deduplicates a path list for deterministic machine output.
     *
     * @param list<string> $paths - Native paths to express relative to the run root.
     *
     * @return list<string> - Unique project-relative POSIX paths in first-seen order.
     */
    private function machinePaths(array $paths): array
    {
        return array_values(array_unique(array_map(fn(string $path): string => $this->machinePath($path), $paths)));
    }

    /**
     * Requires one native path to resolve inside the run root before serialization.
     *
     * @param string $path - Native path that must belong to the analysed project.
     *
     * @return string - Project-relative POSIX path accepted by the v3 schema.
     * @throws LogicException When the path cannot be represented beneath the project root.
     */
    private function machinePath(string $path): string
    {
        $normalized = $this->machineRelativePath($path);

        if ($normalized === null || $normalized === '') {
            throw new LogicException(sprintf('Machine path "%s" is outside the project root.', $path));
        }

        return $normalized;
    }

    /**
     * Attempts to express one native path relative to the canonical run root.
     *
     * @param string $path - Native absolute or root-relative path to normalize.
     *
     * @return string|null - Project-relative POSIX path, or null when the path lies outside the root.
     */
    private function machineRelativePath(string $path): ?string
    {
        $root     = PathHelper::canonical($this->machineContext['projectRoot']);
        $absolute = PathHelper::resolveAgainst($root, $path);

        return PathHelper::relativeToRoot($absolute, $root);
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
