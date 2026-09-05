<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * A baseline in memory: where it came from, which port wrote it, its reviewed rows, and the sensitive counts it kept instead of rows.
 *
 * The store returns one after the user loads or generates a baseline, and the filter reads it on every `analyse --baseline` run.
 */
final readonly class BaselineData
{
    /**
     * Bundles the loaded or written baseline with the path reports echo back.
     *
     * @param string              $path - Path the baseline was read from or written to, shown so the user sees which file a run used.
     * @param string              $toolLanguage - Port that wrote the file; a run by another port refuses it rather than resolving every row.
     * @param list<BaselineEntry> $entries - Reviewed rows in file order; empty when nothing was reviewed yet, so every live finding stays new.
     * @param array<string, int>  $sensitiveByRule - Sensitive findings counted per rule at write time; empty when the run had none.
     */
    public function __construct(
        public string $path,
        public string $toolLanguage,
        public array  $entries,
        public array  $sensitiveByRule = [],
    ) {
    }

    /**
     * Indexes the reviewed rows by identity, which is how `analyse --baseline` looks up each live finding.
     *
     * @return array<string, BaselineEntry> - Identity to row; empty when the baseline has no reviewed rows, so nothing is suppressed.
     */
    public function byIdentity(): array
    {
        $indexed = [];

        // A valid file lists each identity once, so the index is a plain rekey with no merging to reason about.
        foreach ($this->entries as $entry) {
            $indexed[$entry->identity] = $entry;
        }

        return $indexed;
    }

    /**
     * Sums the sensitive findings the baseline counted rather than stored.
     *
     * @return int - Total sensitive findings at write time; 0 when the run had none.
     */
    public function sensitiveTotal(): int
    {
        return array_sum($this->sensitiveByRule);
    }
}
