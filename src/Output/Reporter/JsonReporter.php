<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;

/**
 * Serialises a finished analysis run into the machine-readable JSON document a user gets from
 * `gruff-php analyse --format json`.
 *
 * Reach for this format when the run's consumer is a script, CI gate, or editor rather than a
 * person reading the terminal: it returns the whole report as one pretty-printed, newline-terminated
 * document a job can diff, gate on, or parse a verdict out of. It sits alongside the other
 * `OutputFormat` renderers - the human-facing `TextReporter`, plus the HTML, Markdown, hotspot,
 * SARIF, and GitHub variants - as the plain, tool-friendly JSON option.
 */
final readonly class JsonReporter
{
    /**
     * Turns the finished report into the single JSON string `analyse --format json` prints, so a
     * downstream tool receives the whole run - findings, any score, and run metadata - in one
     * parseable document instead of scraping the human-readable terminal text.
     *
     * @param AnalysisReport $report - The completed analysis run to serialise; its findings, any score, and metadata become the JSON body (the `score` key is present only when the run produced one).
     *
     * @return string - One pretty-printed, newline-terminated JSON document for the whole report, ready to write straight to stdout or a file.
     */
    public function render(AnalysisReport $report): string
    {
        // Pretty-printed and newline-terminated so `analyse --format json > report.json` stays diffable and
        // POSIX-clean. Invalid source bytes become U+FFFD instead of crashing the user's CI run; structural
        // encode failures still throw.
        return json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }
}
