<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Finding\Finding;

/**
 * Turns a finished analysis into GitHub Actions annotation commands - the `::error`, `::warning`,
 * and `::notice` workflow lines a runner reads.
 *
 * Selected when a user runs `gruff-php analyse --format github` inside a GitHub Actions job. Each
 * finding is emitted as one workflow command pinned to its file and line, so the runner raises it as
 * an inline annotation on the pull request's changed-files view instead of leaving the user to hunt
 * through the raw job log. The output is machine text meant for the runner, never read straight by a
 * person, so a report with no findings emits nothing at all.
 */
final readonly class GithubAnnotationsReporter
{
    /**
     * Top of the render: walks every finding in the report and emits one GitHub workflow command per
     * finding, joined into the block a runner scans after `analyse --format github` finishes.
     *
     * @param AnalysisReport $report - Finished analysis whose findings become annotation lines; an empty report yields an empty string.
     *
     * @return string - GitHub annotation output; empty when the report has no findings, so nothing is annotated.
     */
    public function render(AnalysisReport $report): string
    {
        $lines = [];

        // One workflow command per finding - each line becomes a marker pinned to the user's changed code.
        foreach ($report->findings as $finding) {
            $lines[] = $this->annotation($finding);
        }

        // Append the trailing newline only when there is output, so a clean report emits nothing, not a blank line.
        return implode(PHP_EOL, $lines) . ($lines === [] ? '' : PHP_EOL);
    }

    /**
     * Encodes one finding as a single GitHub workflow command: a `::level` line carrying the file,
     * line, and message that GitHub renders as one clickable annotation on a pull request.
     *
     * @param Finding $finding - Finding to encode; its severity picks the annotation level and a null line means a whole-file marker with no row.
     *
     * @return string - GitHub annotation line.
     */
    private function annotation(Finding $finding): string
    {
        // Map the finding's severity to GitHub's three levels; anything that isn't error or warning shows as a neutral notice.
        $level = match ($finding->severity->value) {
            'error' => 'error',
            'warning' => 'warning',
            default => 'notice',
        };
        $properties = [
            'file=' . $this->escapeProperty($finding->filePath),
            'title=' . $this->escapeProperty($finding->ruleId),
        ];

        // Pin the annotation to a line only when the rule pinpointed one, so it lands on the exact offending row.
        if ($finding->line !== null) {
            $properties[] = 'line=' . $finding->line;
        }

        // Stretch the marker across a range when the finding spans several lines, highlighting the whole block.
        if ($finding->endLine !== null) {
            $properties[] = 'endLine=' . $finding->endLine;
        }

        // Properties join the message via the `::level props::data` shape GitHub's workflow-command parser requires.
        return sprintf('::%s %s::%s', $level, implode(',', $properties), $this->escapeData($finding->message));
    }

    /**
     * Percent-escapes a property value - the file path or rule id - so a stray `:` or `,` inside it
     * can't split the command apart and misplace the user's annotation.
     *
     * @param string $text - Raw property value such as a file path or rule id; an empty value passes through as an empty string.
     *
     * @return string - Escaped property value.
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
     * Percent-escapes the finding message that trails the command, keeping a `%` or newline in the
     * text from corrupting the annotation the user reads on the pull request.
     *
     * @param string $text - Raw finding message shown to the user; an empty message passes through as an empty string.
     *
     * @return string - Escaped data value.
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
