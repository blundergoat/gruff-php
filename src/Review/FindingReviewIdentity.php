<?php

declare(strict_types=1);

namespace GruffPhp\Review;

use GruffPhp\Finding\Finding;

/**
 * Builds stable comparison keys for review findings.
 */
final readonly class FindingReviewIdentity
{
    /**
     * Build the comparison key used to match findings across branch reviews.
     *
     * @return string Null-delimited finding identity key.
     */
    public function key(Finding $finding): string
    {
        return implode("\0", [
            $finding->filePath,
            $finding->ruleId,
            $finding->symbol ?? $finding->message,
        ]);
    }
}
