<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;

/**
 * Serialises an AnalysisReport into a pretty-printed JSON document for CLI consumers.
 * Findings, scoring context, and run metadata come from the report's machine-readable representation.
 * The returned document ends with a newline for output to stdout or a file.
 */
final readonly class JsonReporter
{
    /**
     * Turns the finished report into the single JSON string analyse --format json prints, so a
     * downstream tool receives the whole run - findings, any score, and run metadata - in one
     * parseable document instead of scraping the human-readable terminal text.
     *
     * @param AnalysisReport $report - Completed report to serialise; its machine representation always includes a score object.
     *
     * @return string - One pretty-printed, newline-terminated JSON document for the whole report, ready to write straight to stdout or a file.
     */
    public function render(AnalysisReport $report): string
    {
        // Pretty-printed and newline-terminated so analyse --format json > report.json stays diffable and
        // POSIX-clean. Invalid source bytes become U+FFFD instead of crashing the user's CI run; structural
        // encode failures still throw.
        return json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }
}
