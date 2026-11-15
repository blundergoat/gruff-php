<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

use GruffPhp\Results\Finding\Finding;

/**
 * Holds retained findings and the number excluded by changed-region filtering.
 * `DiffFindingFilter` builds it for scans scoped by `--diff`, `--since`, or `--changed-ranges`.
 * The selected scope may keep findings on untouched lines within an enclosing declaration.
 */
final readonly class DiffFilterResult
{
    /**
     * Pairs retained findings with the count excluded by changed-region filtering.
     *
     * @param list<Finding> $findings - Findings retained by the selected scope; empty when none were kept.
     * @param int $suppressedCount - Findings dropped as outside the selected scope; zero when none were filtered.
     */
    public function __construct(
        public array $findings,
        public int $suppressedCount,
    ) {
    }
}
