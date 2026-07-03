<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Baseline\BaselineEntry;
use GruffPhp\Results\Finding\Finding;

/**
 * Builds stable comparison keys for review findings.
 */
final readonly class FindingReviewIdentity
{
    /**
     * Build the comparison key used to match findings across branch reviews.
     *
     * When the user runs `gruff-php analyse --diff-vs origin/main`, this key decides
     * whether a finding reads as introduced, removed, or unchanged. Lines never
     * participate, so a finding that merely moved does not show up as churn: findings
     * with a symbol key on (file, ruleId, symbol, message); symbol-less ones reuse the
     * baseline group key so review and baselines share one line-insensitive identity.
     *
      * User flow: Compares branch feedback for review workflows.
      *
     * @param Finding $finding - Finding to identify for branch review comparison.
     *
     * @return string - null-delimited identity key that survives unrelated edits shifting line numbers
     */
    public function key(Finding $finding): string
    {
        // No symbol to anchor on: fall back to the shared (file, ruleId, message) baseline key.
        // User view: choose the branch review feedback branch for this case.
        // User view: missing data becomes the expected branch review feedback state.
        // User view: an empty value becomes a clear branch review feedback fallback.
        if ($finding->symbol === null || $finding->symbol === '') {
            return BaselineEntry::groupKeyForFinding($finding);
        }

        // NUL joins fields so a path or message containing a colon cannot collide with another finding's key.
        return implode("\0", [
            $finding->filePath,
            $finding->ruleId,
            $finding->symbol,
            $finding->message,
        ]);
    }
}
