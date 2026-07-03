<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;

/**
 * Renders analysis reports as JSON.
 */
final readonly class JsonReporter
{
    /**
     * Render the full analysis report as JSON.
     *
      * User flow: Shapes the report output people read after analysis finishes.
      *
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return string - Pretty-printed analysis JSON document.
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
