<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * What `analyse --migrate-baseline <old> --generate-baseline <new>` wrote, so the command can tell the user what carried across.
 *
 * The 0.5 input is never touched; its reviewed findings are re-identified from the current scan and written to the new path.
 */
final readonly class BaselineMigration
{
    /**
     * Records one completed migration.
     *
     * @param BaselineData $writtenBaseline - The v3 baseline as written.
     * @param int          $accepted - Current findings the 0.5 rows covered, before sensitive ones were set aside.
     * @param int          $sensitiveCounted - Accepted findings that were sensitive and therefore counted rather than stored.
     */
    public function __construct(
        public BaselineData $writtenBaseline,
        public int $accepted,
        public int $sensitiveCounted,
    ) {
    }
}
