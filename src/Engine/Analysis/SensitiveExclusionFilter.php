<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

use GruffPhp\Engine\Config\SensitiveExclusion;
use GruffPhp\Results\Finding\Finding;

/**
 * Applies the configured `sensitiveExclusions` entries to a run's findings and counts what each one
 * hid.
 *
 * This runs once the rules have produced their findings and before scoring, the exit-code gate, and
 * any reporter sees them, so an accepted synthetic fixture stops grading against the project while
 * still appearing in the report's audit rows. Matching reads only the finding's rule id, its
 * project-relative display path, and its symbol; the message and the matched value take no part, so
 * no suppression can ever be written against the secret itself.
 */
final readonly class SensitiveExclusionFilter
{
    /**
     * Partitions findings into those no entry claimed and one audit row per configured entry.
     *
     * @param list<Finding>            $findings - Findings produced by the run, in report order.
     * @param list<SensitiveExclusion> $exclusions - Validated exclusions in configuration order, so a position is its audit index.
     *
     * @return SensitiveExclusionResult - the surviving findings plus one audit row per configured entry; with nothing configured the findings pass
     *                                  through untouched and no rows are published.
     */
    public function apply(array $findings, array $exclusions): SensitiveExclusionResult
    {
        // Nothing configured means nothing to hide and nothing to audit, so hand the findings straight back.
        if ($exclusions === []) {
            return new SensitiveExclusionResult($findings, []);
        }

        $counts    = array_fill(0, count($exclusions), 0);
        $survivors = [];

        // Offer each finding to the entries in configuration order; the first matching entry owns it.
        foreach ($findings as $finding) {
            $matchedIndex = $this->matchingEntryIndex($finding, $exclusions);

            // No entry claimed this finding, so it keeps reporting exactly as it would with no config at all.
            if ($matchedIndex === null) {
                $survivors[] = $finding;
                continue;
            }

            $counts[$matchedIndex]++;
        }

        return new SensitiveExclusionResult($survivors, $this->summaries($exclusions, $counts));
    }

    /**
     * Finds the first configured entry whose declared scope covers a finding.
     *
     * @param Finding                  $finding - Finding to place.
     * @param list<SensitiveExclusion> $exclusions - Validated exclusions in configuration order.
     *
     * @return int|null - index of the owning entry, or null when the finding falls outside every declared scope and must keep reporting.
     */
    private function matchingEntryIndex(Finding $finding, array $exclusions): ?int
    {
        // Configuration order decides ownership, so a finding is counted once even if two entries could cover it.
        foreach ($exclusions as $index => $exclusion) {
            if ($exclusion->matches($finding)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Builds the audit rows, one per configured entry, whether or not the entry matched anything.
     *
     * @param list<SensitiveExclusion> $exclusions - Validated exclusions in configuration order.
     * @param array<int, int>          $counts - Per-entry suppressed counts, keyed by the entry's position.
     *
     * @return list<SensitiveExclusionSummary> - one row per configured entry, so an entry that matched nothing still reports `suppressed: 0`.
     */
    private function summaries(array $exclusions, array $counts): array
    {
        $summaries = [];

        // Publish every entry, including the ones that matched nothing, so no configured suppression is invisible.
        foreach ($exclusions as $index => $exclusion) {
            $summaries[] = new SensitiveExclusionSummary(
                index:      $index,
                rule:       $exclusion->ruleId,
                path:       $exclusion->path,
                symbol:     $exclusion->symbol,
                reason:     $exclusion->reason,
                suppressed: $counts[$index],
            );
        }

        return $summaries;
    }
}
