<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * Records the result of migrating reviewed 0.5 findings into a v3 baseline.
 * The written baseline holds identity rows and sensitive counts; accepted includes both kinds of findings.
 * Callers can report the destination and migrated totals without rereading the written file.
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
