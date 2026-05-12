<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

/**
 * Stores baseline entries and lookup indexes loaded from disk.
 */
final readonly class BaselineData
{
    /**
     * @param list<BaselineEntry> $entries
     */
    public function __construct(
        public string $path,
        public array $entries,
    ) {
    }

    /**
     * @return array<string, BaselineEntry>
     */
    public function byFingerprint(): array
    {
        $indexed = [];

        foreach ($this->entries as $entry) {
            $indexed[$entry->fingerprint] = $entry;
        }

        return $indexed;
    }
}
