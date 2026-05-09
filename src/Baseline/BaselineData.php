<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

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
