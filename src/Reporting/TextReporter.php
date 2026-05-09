<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Finding\Finding;

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
