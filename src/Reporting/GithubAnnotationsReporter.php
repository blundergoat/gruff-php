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
     * @param AnalysisReport $report Analysis report to render.
     * @return string GitHub annotation output.
     */
    public function render(AnalysisReport $report): string
    {
        $lines = [];

        foreach ($report->findings as $finding) {
            $lines[] = $this->annotation($finding);
        }

        // Append the trailing newline only when there is output, so a clean report emits nothing, not a blank line.
        return implode(PHP_EOL, $lines) . ($lines === [] ? '' : PHP_EOL);
    }

    /**
     * Render one finding as a GitHub annotation command.
     *
     * @param Finding $finding Finding to encode; severity selects the command level and a null line is omitted.
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

        // Properties join the message via the `::level props::data` shape GitHub's workflow-command parser requires.
        return sprintf('::%s %s::%s', $level, implode(',', $properties), $this->escapeData($finding->message));
    }

    /**
     * Escape annotation property text according to GitHub command rules.
     *
     * @param string $text Raw property value; property context also reserves `:` and `,` as delimiters.
     * @return string Escaped property value.
     */
    private function escapeProperty(string $text): string
    {
        // Property values additionally escape `:` and `,` because those delimit the property list itself.
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $text,
        );
    }

    /**
     * Escape annotation message text according to GitHub command rules.
     *
     * @param string $text Raw message body; only `%` and newlines are reserved in the data segment.
     * @return string Escaped data value.
     */
    private function escapeData(string $text): string
    {
        // The data segment only reserves `%` and newlines, so `:` and `,` pass through unescaped here.
        return str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $text,
        );
    }
}
