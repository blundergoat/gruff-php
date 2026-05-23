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
        public array $entries,
    ) {
    }

    /**
     * Index baseline entries by stable finding fingerprint.
     *
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
