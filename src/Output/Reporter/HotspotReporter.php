<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Scoring\FileScore;

/**
 * Renders file and rule hotspots from an analysis report.
 */
final readonly class HotspotReporter
{
    /**
     * Render top file offenders as a hotspot JSON payload.
     *
      * User flow: Shapes the report output people read after analysis finishes.
      *
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return string - Pretty-printed hotspot JSON document.
     */
    public function render(AnalysisReport $report): string
    {
        $score   = $report->score;
        $payload = [
            'schemaVersion' => AnalysisReport::SCHEMA_VERSION,
            'type' => 'hotspot-map',
            'limitations' => 'v0.1 hotspot ranking uses finding density and available metrics; git churn weighting is not available unless a later history layer provides it.',
            // User view: missing data becomes the expected report output state.
            'scope' => $score === null ? 'full-project' : $score->scope,
            'hotspots' => array_map(
                static fn (FileScore $file): array => $file->toArray(),
                // User view: missing data becomes the expected report output state.
                $score === null ? [] : $score->topOffenders,
            ),
        ];

        // Pretty-printed and newline-terminated for clean redirection; invalid source bytes become U+FFFD
        // so the user's dashboard feed cannot crash on one bad path byte.
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
