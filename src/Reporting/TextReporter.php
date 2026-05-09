<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Finding\Finding;
use GruffPhp\Mutation\MutationAnalysisResult;

final readonly class TextReporter
{
    public function render(AnalysisReport $report): string
    {
        $counts = $report->findingCounts();
        $lines = [
            sprintf('gruff %s', $report->toolVersion),
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
        $this->appendMutation($lines, $report->mutation);
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
     * @param list<string> $lines
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

        $baselineDelta = $mutation->msiDelta();
        if ($mutation->baselineReport !== null && $baselineDelta !== null) {
            $lines[] = sprintf(
                '  Baseline: %.2f%% (%+.2f points)',
                $mutation->baselineReport->msi(),
                $baselineDelta,
            );
        }

        if ($mutation->mutationBudget !== null) {
            $status = $mutation->budgetExceeded() ? 'exceeded' : 'within budget';
            $lines[] = sprintf('  Budget: %d survived mutants allowed (%s)', $mutation->mutationBudget, $status);
        }

        $fileSummaries = $mutation->report->fileSummaries();
        if ($fileSummaries === []) {
            return;
        }

        $lines[] = '  Files:';
        foreach ($fileSummaries as $summary) {
            $lines[] = sprintf(
                '    %s: MSI %.2f%%, Covered MSI %.2f%%, survived %d/%d',
                $summary->filePath,
                $summary->msi,
                $summary->coveredMsi,
                $summary->survivedMutants,
                $summary->totalMutants,
            );
        }
    }

    /**
     * @param list<string> $lines
     * @param list<string> $paths
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
     * @param list<string> $lines
     * @param list<RunDiagnostic> $diagnostics
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

            $prefix = strtoupper(str_replace('-', '-', $diagnostic->type));
            $lines[] = $location === null
                ? sprintf('  [%s] %s', $prefix, $diagnostic->message)
                : sprintf('  [%s] %s %s', $prefix, $location, $diagnostic->message);
        }
    }

    /**
     * @param list<string> $lines
     * @param list<Finding> $findings
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
