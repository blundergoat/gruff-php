<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Finding\Finding;
use GruffPhp\Mutation\MutationAnalysisResult;

/**
 * Renders human-readable analysis output for the terminal.
 */
final readonly class TextReporter
{
    /**
     * Total-finding floor above which the flat per-finding list is no longer
     * browsable and the footer hint suggests `summary` instead. Chosen at the
     * empirical break-point where consumers report needing to write a Python
     * summariser to triage; revisit if telemetry signals a different number.
     */
    private const OUTPUT_VOLUME_HINT_THRESHOLD = 50;

    /**
     * Render an analysis report as the default human-readable text output.
     *
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return string - Text report with summary, diagnostics, and findings.
     */
    public function render(AnalysisReport $report): string
    {
        $counts = $report->findingCounts();
        $lines  = [sprintf('%s %s analyse', AnalysisReport::TOOL_NAME, $report->toolVersion)];

        if ($report->score !== null) {
            $lines[] = sprintf('Composite: %s (%.2f / 100)', $report->score->composite->letter, $report->score->composite->score);
        }

        $lines[] = sprintf(
            'Findings: %d total · %d error · %d warning · %d advisory',
            $counts['total'],
            $counts['error'],
            $counts['warning'],
            $counts['advisory'],
        );
        $lines[] = sprintf('Format: %s', $report->format);
        $lines[] = sprintf('Fail threshold: %s', $report->failOn);
        $lines[] = '';
        $lines[] = 'Files';
        $lines[] = sprintf('  Discovered: %d', $report->filesDiscovered);
        $lines[] = sprintf('  Parsed: %d', $report->filesParsed);
        $lines[] = sprintf('  Ignored: %d', count($report->ignoredPaths));
        $lines[] = sprintf('  Missing: %d', count($report->missingPaths));
        $lines[] = sprintf('  Parse errors: %d', $report->parseErrorCount());

        $this->appendPathSection($lines, 'Ignored paths', $report->ignoredPaths);
        $this->appendPathSection($lines, 'Missing paths', $report->missingPaths);
        $this->appendDiagnostics($lines, $report->diagnostics);
        $this->appendRuleDeltas($lines, $report);
        $this->appendScore($lines, $report);
        $this->appendBaseline($lines, $report);
        $this->appendMutation($lines, $report->mutation);
        $this->appendReview($lines, $report);
        $this->appendFindings($lines, $report->findings);

        $lines[] = '';
        $lines[] = 'Summary';
        $lines[] = sprintf('  Exit code: %d', $report->exitCode);

        if ($report->failureReason !== null) {
            $lines[] = sprintf('  Failed: %s.', $report->failureReason->message());
        }

        if ($report->newFindingsCount !== null) {
            $lines[] = sprintf('  New findings: %d', $report->newFindingsCount);
        }

        $this->appendOutputVolumeHint($lines, $counts['total']);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Suggest `summary` when the flat text report crosses the volume floor.
     * The flat per-finding list stops being browsable past ~50 findings; the
     * hint short-circuits the "open a Python summariser to triage" workaround
     * that consumers were writing externally. See M08.
     *
     * @param list<string> $lines - Output buffer appended in place; the hint is added only past the floor.
     * @param int          $findingCount - Total finding count this report rendered; gates whether the hint appears.
     *
     * @return void
     */
    private function appendOutputVolumeHint(array &$lines, int $findingCount): void
    {
        if ($findingCount < self::OUTPUT_VOLUME_HINT_THRESHOLD) {
            return;
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Hint: %d findings is a lot to read flat. For a rule-grouped overview, try:',
            $findingCount,
        );
        $lines[] = '  php bin/gruff-php summary <paths>';
    }

    /**
     * Render the top-5 most-improved and most-regressed rules from a branch-review comparison.
     * Composite scores are one number that can mask churn; the per-rule deltas direct
     * attention to which rules actually shifted since the base. Block is silent when no
     * branch-review is in scope. See M06 / ADR-016.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when no review is attached.
     * @param AnalysisReport $report - Report whose attached branch-review supplies the per-rule deltas, if any.
     *
     * @return void
     */
    private function appendRuleDeltas(array &$lines, AnalysisReport $report): void
    {
        if ($report->review === null) {
            return;
        }

        $rows = $report->review->perRuleDelta();
        if ($rows === []) {
            return;
        }

        $improved  = array_slice(array_filter($rows, static fn (array $ruleDelta): bool => $ruleDelta['net'] < 0), 0, 5);
        $regressed = array_slice(
            array_reverse(array_filter($rows, static fn (array $ruleDelta): bool => $ruleDelta['net'] > 0)),
            0,
            5,
        );

        if ($improved === [] && $regressed === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Rule deltas';

        if ($improved !== []) {
            $lines[] = sprintf(
                '  Top %d improved: %s',
                count($improved),
                implode(', ', array_map(
                    static fn (array $ruleDelta): string => sprintf('%d %s', $ruleDelta['net'], $ruleDelta['ruleId']),
                    $improved,
                )),
            );
        }

        if ($regressed !== []) {
            $lines[] = sprintf(
                '  Top %d regressed: %s',
                count($regressed),
                implode(', ', array_map(
                    static fn (array $ruleDelta): string => sprintf('+%d %s', $ruleDelta['net'], $ruleDelta['ruleId']),
                    $regressed,
                )),
            );
        }
    }

    /**
     * Append review details to report output.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when no review is attached.
     * @param AnalysisReport $report - Report whose attached branch-review supplies the base ref and finding sets.
     *
     * @return void
     */
    private function appendReview(array &$lines, AnalysisReport $report): void
    {
        if ($report->review === null) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Branch review';
        $lines[] = sprintf('  Base: %s', $report->review->base);
        $lines[] = sprintf('  Changed only: %s', $report->review->isChangedOnly ? 'yes' : 'no');
        $lines[] = sprintf(
            '  Findings: %d introduced, %d removed, %d unchanged',
            count($report->review->introduced),
            count($report->review->removed),
            count($report->review->unchanged),
        );

        if ($report->review->deltaScore !== null) {
            $lines[] = sprintf('  Score delta: %+.2f', $report->review->deltaScore);
        }

        if ($report->review->introduced === []) {
            return;
        }

        $lines[] = '  Introduced:';
        foreach ($report->review->introduced as $finding) {
            $location = $finding->filePath;
            if ($finding->line !== null) {
                $location .= sprintf(':%d', $finding->line);
            }

            $lines[] = sprintf('    [%s] %s %s', $finding->severity->value, $finding->ruleId, $location);
            $lines[] = sprintf('      %s', $finding->message);
        }
    }

    /**
     * Append score details to report output.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when the report has no score.
     * @param AnalysisReport $report - Report supplying the composite score, per-pillar grades, and diff context.
     *
     * @return void
     */
    private function appendScore(array &$lines, AnalysisReport $report): void
    {
        if ($report->score === null) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Score';
        $lines[] = sprintf('  Scope: %s', $report->score->scope);
        $lines[] = sprintf('  Score drivers: %s', $report->score->explanation);

        if ($report->diff !== null && $report->diff->active) {
            $lines[] = sprintf(
                '  Diff: %s, %d changed files',
                $report->diff->mode,
                count($report->diff->changedFiles),
            );
            $lines[] = sprintf('  Diff note: %s', $report->diff->message);
        }

        if ($report->filters !== null && $report->filters->isActive()) {
            $lines[] = '  Display filters: score and exit code use the scored finding set; filters only change rendered findings.';
        }

        $lines[] = '  Pillars:';
        foreach ($report->score->pillars as $pillar) {
            $grade   = $pillar->grade === null ? 'n/a' : $pillar->grade->letter;
            $score   = $pillar->grade === null ? 'n/a' : sprintf('%.2f', $pillar->grade->score);
            $lines[] = sprintf(
                '    %s: %s (%s) findings=%d',
                $pillar->pillar,
                $grade,
                $score,
                $pillar->findings,
            );
        }
    }

    /**
     * Append baseline details to report output.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when no baseline was applied.
     * @param AnalysisReport $report - Report supplying baseline movement counts and the stale-entry resolution flag.
     *
     * @return void
     */
    private function appendBaseline(array &$lines, AnalysisReport $report): void
    {
        if ($report->baseline === null) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Baseline';
        $lines[] = sprintf('  Path: %s', $report->baseline->path);
        $lines[] = sprintf('  Source: %s', $report->baseline->source);
        $lines[] = sprintf('  Entries: %d', $report->baseline->totalEntries);
        $lines[] = sprintf('  Generated: %s', $report->baseline->generated ? 'yes' : 'no');
        $lines[] = sprintf('  Suppressed findings: %d', $report->baseline->suppressedFindings);
        $lines[] = sprintf(
            '  Movement: %d new, %d unchanged, %d resolved',
            $report->baseline->newCount,
            $report->baseline->unchangedCount,
            $report->baseline->absentCount,
        );
        $lines[] = sprintf('  Stale evaluation: %s', $report->baseline->staleEvaluation);
        $lines[] = sprintf('  Stale entries: %d', count($report->baseline->staleEntries));
        $lines[] = '  Note: suppressed findings are accepted debt and are removed before scoring.';

        if ($report->baseline->generated) {
            $lines[] = sprintf(
                '  Tip: commit %s and rerun `gruff-php analyse` to apply it; pass --baseline %s for explicit application.',
                $report->baseline->path,
                $report->baseline->path,
            );

            return;
        }

        if ($report->baseline->staleEntries !== []) {
            $lines[] = sprintf(
                '  Tip: %d stale baseline entries no longer match a finding. Regenerate with `gruff-php analyse --generate-baseline %s` after reviewing the diff.',
                count($report->baseline->staleEntries),
                $report->baseline->path,
            );
        }

        if ($report->shouldListAbsentBaseline && $report->baseline->staleEntries !== []) {
            $lines[] = '  Resolved entries:';
            foreach ($report->baseline->staleEntries as $resolvedEntry) {
                $lines[] = sprintf(
                    '    %s %s%s',
                    $resolvedEntry->ruleId,
                    $resolvedEntry->filePath,
                    $resolvedEntry->line !== null ? ':' . $resolvedEntry->line : '',
                );
            }
        }
    }

    /**
     * Append mutation details to report output.
     *
     * @param list<string>                $lines - Output buffer appended in place.
     * @param MutationAnalysisResult|null $mutation - Mutation-testing result, or null when no mutation run is in scope
     *                                              (null produces no Mutation section at all).
     *
     * @return void
     */
    private function appendMutation(array &$lines, ?MutationAnalysisResult $mutation): void
    {
        if (!$mutation instanceof MutationAnalysisResult) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Mutation';
        $lines[] = sprintf('  Source: %s', $mutation->report->reportPath);
        $lines[] = sprintf(
            '  MSI: %.2f%% | Covered MSI: %.2f%% | Mutation coverage: %.2f%%',
            $mutation->report->msi(),
            $mutation->report->coveredMsi(),
            $mutation->report->coverageRate(),
        );
        $lines[] = sprintf(
            '  Mutants: %d total, %d survived',
            $mutation->report->totalMutants(),
            $mutation->survivedCount(),
        );
        $lines[] = sprintf('  Statuses: %s', $this->mutationStatusSummary($mutation->report->statusCounts()));

        if (($mutation->report->statusCounts()['escaped'] ?? 0) > 0 || ($mutation->report->statusCounts()['timed out'] ?? 0) > 0) {
            $lines[] = '  Survived status note: escaped mutants are test gaps; timed-out mutants exceeded Infection timeout and are tracked separately.';
        }

        $contextStatuses = $this->mutationContextSummary($mutation->report->statusCounts());
        if ($contextStatuses !== null) {
            $lines[] = sprintf(
                '  Context-only statuses: %s. These do not create mutation.survived-mutant findings.',
                $contextStatuses,
            );
        }

        $baselineDelta = $mutation->msiDelta();
        if ($mutation->baselineReport !== null && $baselineDelta !== null) {
            $lines[] = sprintf(
                '  Baseline: %.2f%% (%+.2f points)',
                $mutation->baselineReport->msi(),
                $baselineDelta,
            );
        }

        if ($mutation->mutationBudget !== null) {
            $status  = $mutation->isBudgetExceeded() ? 'exceeded' : 'within budget';
            $lines[] = sprintf('  Budget: %d survived mutants allowed (%s)', $mutation->mutationBudget, $status);
        }

        $fileSummaries = $mutation->report->fileSummaries();
        if ($fileSummaries === []) {
            return;
        }

        $lines[] = '  Files:';
        foreach ($fileSummaries as $summary) {
            $lines[] = sprintf(
                '    %s: MSI %.2f%%, Covered MSI %.2f%%, survived %d/%d, not covered %d',
                $summary->filePath,
                $summary->msi,
                $summary->coveredMsi,
                $summary->survivedMutants,
                $summary->totalMutants,
                $summary->notCoveredMutants,
            );
        }
    }

    /**
     * @param array<string, int> $counts - Mutation status counts keyed by status label; empty means no mutants ran.
     *
     * @return string - Human-readable mutation status summary.
     */
    private function mutationStatusSummary(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        $parts = [];
        foreach ($counts as $status => $count) {
            $parts[] = sprintf('%s=%d', $status, $count);
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, int> $counts - Mutation status counts keyed by status label; only context-only statuses are emitted.
     *
     * @return string|null - Context-only status summary, or null when absent.
     */
    private function mutationContextSummary(array $counts): ?string
    {
        $parts = [];

        foreach (['not covered', 'error', 'syntax error', 'ignored', 'skipped'] as $status) {
            $count = $counts[$status] ?? 0;
            if ($count > 0) {
                $parts[] = sprintf('%s=%d', $status, $count);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Append path section details to report output.
     *
     * @param list<string> $lines - Output buffer appended in place; left untouched when $paths is empty.
     * @param string       $title - Section heading printed once above the paths (for example "Ignored paths").
     * @param list<string> $paths - Paths to list under the heading; an empty list suppresses the whole section.
     *
     * @return void
     */
    private function appendPathSection(array &$lines, string $title, array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $lines[] = '';
        $lines[] = $title;

        foreach ($paths as $path) {
            $lines[] = sprintf('  %s', $path);
        }
    }

    /**
     * Append diagnostics details to report output.
     *
     * @param list<string>        $lines - Output buffer appended in place after the Diagnostics heading.
     * @param list<RunDiagnostic> $diagnostics - Run diagnostics to render; empty suppresses the whole section.
     *
     * @return void
     */
    private function appendDiagnostics(array &$lines, array $diagnostics): void
    {
        if ($diagnostics === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Diagnostics';

        foreach ($diagnostics as $diagnostic) {
            $location = $diagnostic->filePath;

            if ($location !== null && $diagnostic->line !== null) {
                $location .= sprintf(':%d', $diagnostic->line);
            }

            if ($location === null) {
                $location = $diagnostic->path;
            }

            $prefix  = strtoupper(str_replace('-', '-', $diagnostic->type));
            $lines[] = $location === null
                ? sprintf('  [%s] %s', $prefix, $diagnostic->message)
                : sprintf('  [%s] %s %s', $prefix, $location, $diagnostic->message);
        }
    }

    /**
     * Append findings details to report output.
     *
     * @param list<string>  $lines - Output buffer appended in place after the Findings heading.
     * @param list<Finding> $findings - Findings to render; empty emits the explicit "none" line.
     *
     * @return void
     */
    private function appendFindings(array &$lines, array $findings): void
    {
        $lines[] = '';
        $lines[] = 'Findings';

        if ($findings === []) {
            $lines[] = '  None';
            return;
        }

        foreach ($findings as $finding) {
            $location = $finding->filePath;

            if ($finding->line !== null) {
                $location .= sprintf(':%d', $finding->line);
            }

            $lines[] = sprintf('  [%s] %s', $finding->severity->value, $finding->ruleId);
            $lines[] = sprintf('    %s', $location);
            $lines[] = sprintf('    %s', $finding->message);
        }
    }
}
