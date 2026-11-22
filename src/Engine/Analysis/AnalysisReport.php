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
 * Carries findings, file counts and optional results from one analysis run for report renderers.
 *
 * Optional results include scoring, mutation, diff, trend, baseline and branch review data.
 * Machine serializers expose canonical analysis and summary documents from the stored data.
 *
 * @phpstan-type ReportValue bool|float|int|object|string|null|array<array-key, mixed>
 * @phpstan-type MachinePathDetail array{path: string, reason: string, source: string, pattern?: string}
 * @phpstan-type MachineReportContext array{
 *     ignoredPathDetails: list<IgnoredPath>,
 *     shouldIncludeIgnored: bool,
 *     projectRoot: string,
 *     unfilteredFindings: list<Finding>|null,
 *     suppressedFindings?: list<array{finding: Finding, justification: string}>
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
     * @param string              $toolVersion     - Gruff version used to produce the report.
     * @param list<string>        $requestedPaths  - Scan operands. An empty list leaves discovery to the project defaults.
     * @param string              $format          - Output format requested for report serialization.
     * @param string              $failOn          - Severity gate used to determine the process exit code.
     * @param int                 $filesDiscovered - Number of source files discovered before parsing.
     * @param int                 $filesParsed     - Number of source files parsed successfully.
     * @param list<string>        $ignoredPaths    - Requested paths ignored by discovery.
     * @param list<string>        $missingPaths    - Requested paths that did not resolve to files.
     * @param list<RunDiagnostic> $diagnostics     - Run diagnostics. An empty list means there are no scan problems or notes to show.
     * @param list<Finding>       $findings        - Visible findings. Check diagnostics before treating an empty report as a clean scan.
     * @param int                 $exitCode        - Process exit code represented by the report.
     *
     * @param string|null                 $configPath - Config file path used for the run; null when the run used no config.
     * @param MutationAnalysisResult|null $mutation   - Mutation analysis result; null when mutation analysis was not run.
     * @param ScoreReport|null            $score      - Score summary; null when scoring was not produced for the run.
     * @param DiffResult|null             $diff       - Diff context; null when no diff result is available.
     * @param TrendReport|null            $trend      - Trend history; null when no trend recording was requested.
     * @param BaselineReport|null         $baseline   - Baseline application or generation result; null when none was supplied.
     * @param BranchReviewResult|null     $review     - Branch review result; null when the run was not a --diff-vs review.
     * @param FindingDisplayFilter|null   $filters    - Display-filter settings; null when none were supplied.
     *
     * @param int|null           $suppressedCount          - Findings hidden by changed-region filtering; null when no such filtering ran.
     * @param bool               $shouldListAbsentBaseline - Whether reporters should list resolved (absent) baseline entries.
     * @param ThresholdTrip|null $failureReason            - Gate threshold that tripped; null when the run did not fail a count threshold.
     * @param int|null           $newFindingsCount         - Size of the new-findings set; null when no new-findings gate is active.
     *
     * @param list<SensitiveExclusionSummary> $sensitiveExclusions - Audit rows for suppressions. An empty list means nothing was recorded.
     *
     * @param MachineReportContext $machineContext - Serializer paths, flags, root, pre-display findings and optional suppressed SARIF results.
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
     * Returns findings hidden by inline comments or duplicate claims for suppressed SARIF results.
     *
     * @return list<array{finding: Finding, justification: string}> - Findings with audit reasons; empty when none were suppressed.
     */
    public function suppressedFindings(): array
    {
        return $this->machineContext['suppressedFindings'] ?? [];
    }

    /**
     * Tallies findings by severity for the report's headline summary line.
     *
     * @return array{advisory: int, warning: int, error: int, total: int} - Severity counts and total; all zero for an empty report.
     */
    public function findingCounts(): array
    {
        $counts = [
            'advisory' => 0,
            'warning' => 0,
            'error' => 0,
            'total' => count($this->findings),
        ];


        // Group findings by severity for the totals shown in the report.
        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * Tallies findings per rule with a severity breakdown, ordered by total descending then rule ID ascending.
     *
     * @return list<array{ruleId: string, total: int, advisory: int, warning: int, error: int}> - One row per triggered rule; empty if none.
     */
    public function findingCountsByRule(): array
    {
        $byRule = [];


        // Collect counts under each rule ID so a renderer can rank the issues the caller sees.
        foreach ($this->findings as $finding) {
            $byRule[$finding->ruleId] ??= ['total' => 0, 'advisory' => 0, 'warning' => 0, 'error' => 0];
            $byRule[$finding->ruleId]['total']++;
            $byRule[$finding->ruleId][$finding->severity->value]++;
        }

        $rows = [];


        // Expose a row per rule with the same severity totals used elsewhere in the report.
        foreach ($byRule as $ruleId => $counts) {
            $rows[] = [
                'ruleId' => $ruleId,
                'total' => $counts['total'],
                'advisory' => $counts['advisory'],
                'warning' => $counts['warning'],
                'error' => $counts['error'],
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
     * Counts parse-error diagnostics for the report summary.
     *
     * @return int - Number of diagnostics typed parse-error; other diagnostic types do not contribute.
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
            'inputs' => $this->machineInputs($this->requestedPaths),
            'projectRoot' => '.',
        ];

        // Publish the config only when the run actually used one.
        if ($this->configPath !== null) {
            $configPath = $this->machineRelativePath($this->configPath);
            // Only a path relative to the scanned project is portable in report JSON.
            if ($configPath !== null) {
                $runMetadata['config'] = $configPath;
            }
        }

        // An active display filter explains why the visible findings differ from the full counts.
        if ($this->filters instanceof FindingDisplayFilter && $this->filters->isActive()) {
            $runMetadata['filters'] = $this->machineRunFilters($this->filters);
        }

        // Record the explicit option when a caller chose to inspect normally ignored files.
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
                'missingPaths' => $this->machineInputs($this->missingPaths),
            ],
            'suppressions' => $this->suppressionRows(),
        ];

        // Changed-code runs publish how many findings the selected region removed. Full scans have no such count.
        if ($this->suppressedCount !== null) {
            $report['summary']['suppressedFindings'] = $this->suppressedCount;
        }

        // Give machine readers a baseline section for applied debt or a gate that counted new findings.
        if ($this->baseline instanceof BaselineReport || $this->newFindingsCount !== null) {
            $report['baseline'] = $this->machineBaseline($this->baseline);
        }

        // Include comparison details only when Git or explicit ranges narrowed the result.
        if ($this->diff instanceof DiffResult && $this->diff->active) {
            $report['diff'] = $this->machineDiff($this->diff);
        }

        // Publish what the display filters hid without changing the score or the summary totals.
        if ($this->filters instanceof FindingDisplayFilter && $this->filters->isActive()) {
            $report['displayFilter'] = $this->machineDisplayFilter($this->filters, count($summaryFindings) - count($this->findings));
        }

        $extensions = $this->machineExtensions();
        // Keep optional PHP-specific data out of the envelope when none was collected.
        if ($extensions !== []) {
            $report['extensions'] = $extensions;
        }

        return $report;
    }

    /**
     * Produces a compact machine payload from the canonical analysis document.
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
     * Counts a complete finding collection by canonical severity for the machine summary.
     *
     * @param list<Finding> $findings - Findings whose severities contribute to the machine summary.
     *
     * @return array{advisory: int, warning: int, error: int, total: int} - Canonical severity totals, including zero-valued buckets.
     */
    private static function findingCountsFor(array $findings): array
    {
        $counts = ['advisory' => 0, 'warning' => 0, 'error' => 0, 'total' => count($findings)];

        // Count by severity from the same findings the caller's summary represents.
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

        // Add only pillars that contributed findings so the counts can stay sparse.
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
        // An omitted severity floor should not become a null filter setting in the contract.
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

        // Carry an end line only when the rule provides a range the caller can locate.
        if ($finding->endLine !== null) {
            $payload['endLine'] = max(1, $finding->endLine);
        }

        // A scanner-supplied column can point to an exact occurrence on a crowded line.
        if ($finding->column !== null) {
            $payload['column'] = max(1, $finding->column);
        }

        // A file-only finding has no symbol to display.
        if ($finding->symbol !== null && $finding->symbol !== '') {
            $payload['symbol'] = $finding->symbol;
        }

        return $payload;
    }

    /**
     * Maps one run diagnostic to the canonical v3 diagnostic shape.
     *
     * @param RunDiagnostic $diagnostic - Native non-finding diagnostic to serialize.
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
        $filePath    = $diagnostic->filePath ?? $diagnostic->path;
        $machineFile = $filePath === null || $filePath === '' ? null : $this->machineRelativePath($filePath);

        // A file outside the project, such as a baseline kept beside it, has no project-relative form and a host path
        // may not be published, so the optional key is left out rather than failing the whole report.
        if ($machineFile !== null && $machineFile !== '') {
            $payload['file'] = $machineFile;
        }

        // Add a source line only when the diagnostic can direct the caller to a real location.
        if ($diagnostic->line !== null && $diagnostic->line > 0) {
            $payload['line'] = $diagnostic->line;
        }

        return $payload;
    }

    /**
     * Projects one ignored path into an auditable record of its path, skip reason and source.
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

        // Show the matching ignore pattern when discovery can tell the caller which pattern applied.
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
     * @return string - Canonical skip reason associated with the observed source and pattern.
     * @throws LogicException - When the source or default pattern has no canonical reason.
     */
    private static function skipReason(IgnoredPath $ignoredPath): string
    {
        // Tag a path ignored through project config so the caller knows which setting to inspect.
        if ($ignoredPath->source === PathIgnoreResolver::SOURCE_CONFIG) {
            return 'config-ignore';
        }

        // Keep Git ignores distinct from the scanner's own ignore defaults.
        if ($ignoredPath->source === PathIgnoreResolver::SOURCE_GITIGNORE) {
            return 'gitignored';
        }

        // Mark generated paths so callers can tell why they were skipped.
        if ($ignoredPath->source === PathIgnoreResolver::SOURCE_GENERATED) {
            return 'generated';
        }

        // Reject an unsupported internal reason instead of publishing an invented explanation.
        if ($ignoredPath->source !== PathIgnoreResolver::SOURCE_DEFAULT) {
            throw new LogicException(sprintf('Unknown ignored-path source "%s".', $ignoredPath->source));
        }

        return match ($ignoredPath->pattern) {
            '.git', '.hg', '.svn' => 'vcs',
            'node_modules', 'vendor', 'vendors', 'third_party', 'third-party', 'bower_components' => 'dependency',
            'build', 'coverage', 'dist' => 'build-output',
            PathIgnoreResolver::MINIFIED_FILE_PATTERN => 'generated',
            '.fleet', '.idea', '.vscode' => 'local-tooling',
            '.gruff-cache', '.phpunit.cache', 'var/cache' => 'tool-cache',
            default => throw new LogicException(sprintf('Unknown default ignored-path pattern "%s".', $ignoredPath->pattern ?? '')),
        };
    }

    /**
     * Projects a configured, built-in, inline or duplicate suppression into its audit record.
     *
     * @param SensitiveExclusionSummary $summary - Suppression result carrying its scope, reason and suppressed count.
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

        // A whole-file exclusion has no symbol. Only declaration-specific audit rows carry one.
        if ($summary->symbol !== null && $summary->symbol !== '') {
            $payload['symbol'] = $summary->symbol;
        }

        // Built-in, inline and duplicate rows name their source; configured rows omit it.
        if ($summary->source !== null) {
            $payload['source'] = $summary->source;
        }

        return $payload;
    }

    /**
     * Gives machine formats the same suppression audit rows.
     *
     * @return list<array<string, ReportValue>> - Configured and observed suppressions.
     */
    public function suppressionRows(): array
    {
        return array_map($this->machineSuppression(...), $this->sensitiveExclusions);
    }

    /**
     * Adapts the native score container without recalculating its scores.
     *
     * @return array<string, ReportValue> - Canonical score data, or the structural unscored representation.
     */
    private function machineScore(): array
    {
        // If analysis produced no score, provide the empty score shape expected by JSON readers.
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

        // An empty complexity distribution is still a JSON mapping, so clients can treat it like populated distributions.
        if ($payload['complexityDistribution'] === []) {
            $payload['complexityDistribution'] = (object)[];
        }

        return $payload;
    }

    /**
     * Serializes a baseline result without repeating baseline matching.
     *
     * @param BaselineReport|null $baseline - Native baseline application or generation result, or null for a gate-only count.
     *
     * @return array<string, ReportValue> - Baseline fields and PHP bucket extensions, or only newFindings (default 0) for null input.
     */
    private function machineBaseline(?BaselineReport $baseline): array
    {
        // A diff-based gate can report new findings without applying a baseline file.
        if (!$baseline instanceof BaselineReport) {
            return ['newFindings' => $this->newFindingsCount ?? 0];
        }

        $legacy = $baseline->toArray();
        // Common baseline fields let consumers read every port without a PHP-specific branch.
        // Generation and migration keep movement fields present, even when no comparison produced counts.
        $payload = [
            'applied' => !$baseline->generated,
            'entries' => $baseline->totalEntries,
            'generated' => $baseline->generated,
            'newFindings' => $this->newFindingsCount ?? ($baseline->newCount + $baseline->collisionCount + $baseline->notEligibleCount),
            'resolvedFindings' => $baseline->absentCount,
            'source' => $baseline->source,
            'stale' => $legacy['stale'],
            'staleEntries' => count($baseline->staleEntries),
            'staleEvaluation' => $baseline->staleEvaluation,
            'suppressedFindings' => $baseline->suppressedFindings,
            'unchangedFindings' => $baseline->unchangedCount,
            'extensions' => [
                'php' => ['baseline' => ['buckets' => $legacy['buckets']]],
            ],
        ];
        $baselinePath = $this->machineRelativePath($baseline->path);
        // Include the baseline's path only when the reader can resolve it inside this project.
        if ($baselinePath !== null) {
            $payload['path'] = $baselinePath;
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

        // Explicit ranges may narrow the scan without naming a Git comparison ref.
        if ($diff->base !== null && $diff->base !== '') {
            $payload['base'] = $diff->base;
        }

        // Show the number filtered out only after the changed-region filter has run.
        if ($diff->suppressedCount !== null) {
            $payload['filteredFindings'] = $diff->suppressedCount;
        }

        return $payload;
    }

    /**
     * Records how an active display filter changed only the rendered finding list.
     *
     * @param FindingDisplayFilter $filters        - Active filter settings applied to rendered findings.
     * @param int                  $hiddenFindings - Omitted full-run finding count; negative values are recorded as zero.
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

        // Include what tripped the exit gate when the report has threshold evidence to explain it.
        if ($this->failureReason instanceof ThresholdTrip) {
            $topLevel['failureReason'] = $this->failureReason->toArray();
        }

        // Only a requested mutation result adds Infection data to the report.
        if ($this->mutation instanceof MutationAnalysisResult) {
            $topLevel['mutation'] = $this->mutation->toArray();
        }

        // Branch-review details appear after comparison against a Git base ref.
        if ($this->review instanceof BranchReviewResult) {
            $topLevel['review'] = $this->review->toArray();
        }

        // The trend section exists only when the caller requested history output and a report was produced.
        if ($this->trend instanceof TrendReport) {
            $topLevel['trend'] = $this->trend->toArray();
        }

        return $topLevel === [] ? [] : ['php' => ['topLevel' => $topLevel]];
    }

    /**
     * Removes unavailable optional values from one native score row before serialization.
     * Keeps null 'score' and 'grade' fields so consumers can distinguish an unevaluated score from an omitted field.
     *
     * @param array<string, ReportValue> $values - Native score row; unavailable nulls are removed, while unevaluated score and grade remain.
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
     * Publishes the requested inputs that have a project-relative form and leaves out the rest.
     * Outside-root inputs are omitted so startup-failure reports remain serializable without exposing host paths.
     *
     * @param list<string> $paths - Requested paths as the caller passed them.
     *
     * @return list<string> - Unique project-relative POSIX paths in first-seen order, without any that lie outside the root.
     */
    private function machineInputs(array $paths): array
    {
        $inputs = [];
        // Keep operands in report JSON only when they can be named relative to the project.
        foreach ($paths as $path) {
            $normalized = $this->machineRelativePath($path);
            // An input with no project-relative form is left out rather than published as a host path.
            if ($normalized !== null && $normalized !== '') {
                $inputs[] = $normalized;
            }
        }

        return array_values(array_unique($inputs));
    }

    /**
     * Requires one native path to resolve inside the run root before serialization.
     *
     * @param string $path - Native path that must belong to the analysed project.
     *
     * @return string - Project-relative POSIX path accepted by the v3 schema.
     * @throws LogicException - When the path cannot be represented beneath the project root.
     */
    private function machinePath(string $path): string
    {
        $normalized = $this->machineRelativePath($path);

        // Required machine paths must point inside the scan root or the report would give clients an unusable location.
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
     * Reports whether the finding list contains the exact requested severity.
     *
     * @param Severity $severity - Severity level to look for in the finding list.
     *
     * @return bool - True for an exact severity match; false for an empty list or no match.
     */
    public function hasFindingsAtSeverity(Severity $severity): bool
    {

        // Look at the visible findings when a renderer asks whether it should show a severity.
        foreach ($this->findings as $finding) {

            // Stop at the first matching finding because one is enough to make the severity visible.
            if ($finding->severity === $severity) {
                return true;
            }
        }

        return false;
    }
}
