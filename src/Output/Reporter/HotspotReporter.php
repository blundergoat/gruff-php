<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Scoring\FileScore;

/**
 * Renders `--format hotspot` for callers tracking the files with the most findings.
 *
 * Uses the score's existing offender order and includes run diagnostics and suppression audit rows.
 * When there is no score, the hotspot list is empty and the scope reads full-project.
 */
final readonly class HotspotReporter
{
    /** Schema identifier for the compact hotspot document. */
    public const SCHEMA_VERSION = 'gruff-php.hotspot.v1';

    /**
     * Serializes the report's existing hotspot ranking and diagnostics as newline-terminated JSON.
     * Invalid UTF-8 is substituted; other JSON encoding errors propagate.
     *
     * @param AnalysisReport $report - Analysis to serialize; a null score yields no hotspots and uses full-project scope.
     *
     * @return string - Pretty-printed JSON; hotspots is empty when no score or no top offenders are present.
     */
    public function render(AnalysisReport $report): string
    {
        $score   = $report->score;
        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'type' => 'hotspot-map',
            'limitations' => 'v0.1 hotspot ranking uses finding density and available metrics; git churn weighting is not available unless a later history layer provides it.',

            'scope' => $score === null ? 'full-project' : $score->scope,
            'suppressions' => $report->suppressionRows(),
            'diagnostics' => array_map(
                static fn (RunDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $report->diagnostics,
            ),
            'hotspots' => array_map(
                static fn (FileScore $file): array => $file->toArray(),

                $score === null ? [] : $score->topOffenders,
            ),
        ];

        // Invalid UTF-8 is replaced with U+FFFD so malformed paths or messages remain serializable.

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
