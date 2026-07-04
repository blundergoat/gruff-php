<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Scoring\FileScore;

/**
 * Emits the `hotspot-map` JSON feed behind `gruff-php analyse --format hotspot`.
 *
 * Reach for this when a user wants the riskiest files surfaced for a dashboard or CI job instead of a
 * human-readable report: it ranks the worst-scoring files as "hotspots" so reviewers can open the most
 * troubled code first. Because the payload is machine-consumed, it always emits valid JSON - even for an
 * empty or unscored run - rather than anything meant to be read at the terminal. Ranking today leans on
 * finding density and available metrics; git-churn weighting only shows up once a history layer supplies it.
 */
final readonly class HotspotReporter
{
    /**
     * Builds the hotspot-map document a user gets from `gruff-php analyse src/ --format hotspot`: the
     * worst-scoring files as a ranked JSON list, ready to pipe into a dashboard or CI job rather than read by eye.
     *
     * @param AnalysisReport $report - Completed analysis run; its score supplies the ranked files, and a report carrying no score still renders a valid hotspot-map whose hotspots list is empty.
     *
     * @return string - Pretty-printed, newline-terminated hotspot JSON; the hotspots list is empty when the run had no score to rank.
     */
    public function render(AnalysisReport $report): string
    {
        $score   = $report->score;
        $payload = [
            'schemaVersion' => AnalysisReport::SCHEMA_VERSION,
            'type' => 'hotspot-map',
            'limitations' => 'v0.1 hotspot ranking uses finding density and available metrics; git churn weighting is not available unless a later history layer provides it.',
            // A scored run names its own scope; a report with no `$score` has nothing to read, so the feed falls back to the generic `full-project` label.
            'scope' => $score === null ? 'full-project' : $score->scope,
            'hotspots' => array_map(
                static fn (FileScore $file): array => $file->toArray(),
                // With a score in hand, serialise its already-ranked top offenders as hotspots; an unscored run has none, so the list comes back empty.
                $score === null ? [] : $score->topOffenders,
            ),
        ];

        // Pretty-printed and newline-terminated for clean redirection; invalid source bytes become U+FFFD
        // so the user's dashboard feed cannot crash on one bad path byte.
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
