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
     * Render an analysis report as the default human-readable text output.
     *
     * @param AnalysisReport $report Analysis report to render.
     * @return string Text report with summary, diagnostics, and findings.
     */
    public function render(AnalysisReport $report): string
    {
        $counts = $report->findingCounts();
        $lines  = [
            sprintf('%s %s', AnalysisReport::TOOL_NAME, $report->toolVersion),
            sprintf('Format: %s', $report->format),
            sprintf('Fail threshold: %s', $report->failOn),
            '',
            'Files',
            sprintf('  Discovered: %d', $report->filesDiscovered),
            sprintf('  Parsed: %d', $report->filesParsed),
            sprintf('  Ignored: %d', count($report->ignoredPaths)),
            sprintf('  Missing: %d', count($report->missingPaths)),
            sprintf('  Parse errors: %d', $report->parseErrorCount()),
        ];

        $this->appendPathSection($lines, 'Ignored paths', $report->ignoredPaths);
        $this->appendPathSection($lines, 'Missing paths', $report->missingPaths);
        $this->appendDiagnostics($lines, $report->diagnostics);
        $this->appendScore($lines, $report);
        $this->appendBaseline($lines, $report);
        $this->appendMutation($lines, $report->mutation);
        $this->appendReview($lines, $report);
        $this->appendFindings($lines, $report->findings);

        $lines[] = '';
        $lines[] = 'Summary';
        $lines[] = sprintf(
            '  Findings: %d (advisory: %d, warning: %d, error: %d)',
            $counts['total'],
            $counts['advisory'],
            $counts['warning'],
            $counts['error'],
        );
        $lines[] = sprintf('  Exit code: %d', $report->exitCode);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Append review details to report output.
     *
     * @param list<string> $lines
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
     * @param list<string> $lines
     * @return void
     */
    private function appendScore(array &$lines, AnalysisReport $report): void
    {
        if ($report->score === null) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Score';
        $lines[] = sprintf(
            '  Composite: %s (%.2f/100)',
            $report->score->composite->letter,
            $report->score->composite->score,
        );
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
     * @param list<string> $lines
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
    }

    /**
     * Append mutation details to report output.
     *
     * @param list<string> $lines
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
     * @param array<string, int> $counts
     * @return string Human-readable mutation status summary.
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
     * @param array<string, int> $counts
     * @return string|null Context-only status summary, or null when absent.
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
     * @param list<string> $lines
     * @param list<string> $paths
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
     * @param list<string>        $lines
     * @param list<RunDiagnostic> $diagnostics
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
     * @param list<string>  $lines
     * @param list<Finding> $findings
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
