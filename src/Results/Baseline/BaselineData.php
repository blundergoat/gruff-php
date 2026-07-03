<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * Stores baseline groups and lookup indexes loaded from disk.
 */
final readonly class BaselineData
{
    /**
     * @param string              $path - Baseline file path the data came from.
     * @param list<BaselineEntry> $entries - Baseline groups loaded from disk.
     */
    public function __construct(
        public string $path,
        public array  $entries,
    ) {
    }

    /**
     * Index baseline groups by their line-insensitive (file, ruleId, message) key.
     *
     * This index is what each `analyse --baseline` run looks findings up against.
     *
     * @return array<string, BaselineEntry> - map keyed by group key; empty when no entries, and duplicate keys (possible in
     *                       hand-edited files) merge by summing their counts so no accepted debt is silently dropped
     */
    public function byGroup(): array
    {
        $indexed = [];

        // Fold every stored row into the lookup map, merging duplicates as we go.
        foreach ($this->entries as $entry) {
            $groupKey = $entry->groupKey();
            $existing = $indexed[$groupKey] ?? null;

            $indexed[$groupKey] = $existing instanceof BaselineEntry
                ? new BaselineEntry($existing->filePath, $existing->ruleId, $existing->message, $existing->count + $entry->count)
                : $entry;
        }

        return $indexed;
    }
}
