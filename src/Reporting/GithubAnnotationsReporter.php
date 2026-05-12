<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Finding;

/**
 * Renders findings as GitHub Actions annotation commands.
 */
final readonly class GithubAnnotationsReporter
{
    /**
     * Render findings as GitHub Actions workflow commands.
     *
     * @return string GitHub annotation output.
     */
    public function render(AnalysisReport $report): string
    {
        $lines = [];

        foreach ($report->findings as $finding) {
            $lines[] = $this->annotation($finding);
        }

        return implode(PHP_EOL, $lines) . ($lines === [] ? '' : PHP_EOL);
    }

    /**
     * Render one finding as a GitHub annotation command.
     *
     * @return string GitHub annotation line.
     */
    private function annotation(Finding $finding): string
    {
        $level = match ($finding->severity->value) {
            'error' => 'error',
            'warning' => 'warning',
            default => 'notice',
        };
        $properties = [
            'file=' . $this->escapeProperty($finding->filePath),
            'title=' . $this->escapeProperty($finding->ruleId),
        ];

        if ($finding->line !== null) {
            $properties[] = 'line=' . $finding->line;
        }

        if ($finding->endLine !== null) {
            $properties[] = 'endLine=' . $finding->endLine;
        }

        return sprintf('::%s %s::%s', $level, implode(',', $properties), $this->escapeData($finding->message));
    }

    /**
     * Escape annotation property text according to GitHub command rules.
     *
     * @return string Escaped property value.
     */
    private function escapeProperty(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $value,
        );
    }

    /**
     * Escape annotation message text according to GitHub command rules.
     *
     * @return string Escaped data value.
     */
    private function escapeData(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $value,
        );
    }
}
