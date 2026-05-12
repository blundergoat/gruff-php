<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Scoring\FileScore;

/**
 * Renders file and rule hotspots from an analysis report.
 */
final readonly class HotspotReporter
{
    /**
     * Render top file offenders as a hotspot JSON payload.
     *
     * @return string Pretty-printed hotspot JSON document.
     */
    public function render(AnalysisReport $report): string
    {
        $score = $report->score;
        $payload = [
            'schemaVersion' => AnalysisReport::SCHEMA_VERSION,
            'type' => 'hotspot-map',
            'limitations' => 'v0.1 hotspot ranking uses finding density and available metrics; git churn weighting is not available unless a later history layer provides it.',
            'scope' => $score === null ? 'full-project' : $score->scope,
            'hotspots' => array_map(
                static fn (FileScore $file): array => $file->toArray(),
                $score === null ? [] : $score->topOffenders,
            ),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
