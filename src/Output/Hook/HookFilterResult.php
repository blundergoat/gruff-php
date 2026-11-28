<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;

/**
 * Carries hook-filtered findings, the suppressed count, and identities for the original input findings.
 * The hook command uses the filtering result to assemble feedback after new-only and changed-region policies run.
 *
 * An empty kept list can mean every finding was suppressed, so it does not by itself establish a clean scan.
 */
final readonly class HookFilterResult
{
    /**
     * Bundles the retained findings, suppression count, and optional input identities from a filtering pass.
     *
     * @param list<Finding> $findings - Findings retained by filtering; empty may mean all input findings were suppressed.
     * @param int $suppressedCount - Number of input findings excluded by new-only or changed-region filtering.
     * @param array<int, string> $identities - Disambiguated identities for all input findings, keyed by spl_object_id(); defaults to empty.
     */
    public function __construct(
        public array $findings,
        public int $suppressedCount,
        public array $identities = [],
    ) {
    }
}
