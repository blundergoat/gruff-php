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
     * @param Finding $finding Finding to identify for branch review comparison.
     * @return string Null-delimited finding identity key.
     */
    public function key(Finding $finding): string
    {
        $location = $finding->symbol !== null && $finding->symbol !== ''
            ? $finding->symbol
            : implode(':', [
                (string) ($finding->line ?? 0),
                (string) ($finding->endLine ?? 0),
                (string) ($finding->column ?? 0),
            ]);

        return implode("\0", [
            $finding->filePath,
            $finding->ruleId,
            $location,
            $finding->message,
        ]);
    }
}
