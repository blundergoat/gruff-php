<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

/**
 * Stores baseline entries and lookup indexes loaded from disk.
 */
final readonly class BaselineData
{
    /**
     * @param string              $path    Baseline file path the data came from.
     * @param list<BaselineEntry> $entries Baseline entries loaded from disk.
     */
    public function __construct(
        public string $path,
        public array  $entries,
    ) {
    }

    /**
     * Index baseline entries by stable finding fingerprint.
     *
     * @return array<string, BaselineEntry> - map keyed by finding fingerprint; empty when no entries, and a duplicate fingerprint keeps the last
     *                       entry seen
     */
    public function byFingerprint(): array
    {
        $indexed = [];

        foreach ($this->entries as $entry) {
            $indexed[$entry->fingerprint] = $entry;
        }

        // Fingerprint-keyed map for O(1) lookup; a duplicate fingerprint keeps the last entry seen.
        return $indexed;
    }
}
