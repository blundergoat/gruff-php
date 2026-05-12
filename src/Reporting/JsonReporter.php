<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;

final readonly class JsonReporter
{
    /**
     * Render the full analysis report as JSON.
     *
     * @return string Pretty-printed analysis JSON document.
     */
    public function render(AnalysisReport $report): string
    {
        return json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }
}
