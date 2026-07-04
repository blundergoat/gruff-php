<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * The in-memory form of a project's `gruff-baseline.json`: the path it was read from or
 * written to, plus one `BaselineEntry` per accepted-debt group. `BaselineStore` produces it when a
 * run reads an existing baseline or writes a fresh one on `gruff-php analyse --generate-baseline`,
 * and `BaselineFilter` consults it during every `analyse --baseline` run. Its one behaviour,
 * `byGroup()`, turns the flat entry list into the lookup index live findings are matched against,
 * so debt the team already signed off on stays green while anything new still fails the run.
 */
final readonly class BaselineData
{
    /**
     * Carries a loaded or freshly written baseline out of the store - on to the filter when read, or to
     * the reporter when written. Callers rarely build one directly.
     *
     * @param string              $path - Path the baseline was read from or written to, echoed back in reports so the user can see which `gruff-baseline.json` a run actually used.
     * @param list<BaselineEntry> $entries - One row per accepted-debt group the team signed off on; empty when the baseline holds no accepted findings yet, so nothing is suppressed and every live finding stays new.
     */
    public function __construct(
        public string $path,
        public array  $entries,
    ) {
    }

    /**
     * Builds the lookup index a baseline run matches live findings against: every accepted-debt
     * group keyed by its line-insensitive (file, ruleId, message) identity. `BaselineFilter` calls
     * this once per `analyse --baseline` run to tell findings the user already accepted from new ones.
     *
     * @return array<string, BaselineEntry> - Group key to entry map; empty when the baseline has no accepted groups yet, so nothing gets suppressed. Duplicate keys (possible in a hand-edited `gruff-baseline.json`) merge by summing their counts, so no accepted debt is silently dropped.
     */
    public function byGroup(): array
    {
        $indexed = [];

        // Walk every accepted-debt group the user committed and fold it into the key-to-entry index the filter reads.
        foreach ($this->entries as $entry) {
            $groupKey = $entry->groupKey();
            // Check whether this group key was already indexed: null means the first sighting, while a hit means the baseline file listed the same accepted-debt group more than once.
            $existing = $indexed[$groupKey] ?? null;

            // On a repeat group, add the two counts together so no accepted instance is lost, while a first sighting just stores the row as it came in.
            $indexed[$groupKey] = $existing instanceof BaselineEntry
                ? new BaselineEntry($existing->filePath, $existing->ruleId, $existing->message, $existing->count + $entry->count)
                : $entry;
        }

        return $indexed;
    }
}
