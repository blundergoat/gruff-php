<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Finding\Finding;

/**
 * Builds the key a branch review matches findings on, so a finding that merely moved lines stays recognised as the same one.
 *
 * When a user runs `gruff-php analyse --diff-vs origin/main`, gruff needs to know which findings are new, which went away, and which
 * are the same issue as before. Matching on line number would make every edit above a finding look like churn, so this key never reads one.
 * It is a review-time key only; the committed baseline stores the separate line-free identity ratified for the family.
 */
final readonly class FindingReviewIdentity
{
    /**
     * Builds the comparison key for one finding: (file, ruleId, symbol, message) with a symbol, or (file, ruleId, message) without one.
     *
     * @param Finding $finding - Finding to identify for branch-review comparison.
     *
     * @return string - NUL-delimited key that survives unrelated edits shifting line numbers.
     */
    public function key(Finding $finding): string
    {
        // No symbol to anchor on: file, rule, and message are all a symbol-less finding has, so they are the whole key.
        if ($finding->symbol === null || $finding->symbol === '') {
            return self::groupKeyForFinding($finding);
        }

        // NUL joins fields so a path or message containing a colon cannot collide with another finding's key.
        return implode("\0", [
            $finding->filePath,
            $finding->ruleId,
            $finding->symbol,
            $finding->message,
        ]);
    }

    /**
     * Builds the symbol-less review key, (file, ruleId, message), shared by every finding of one rule on one path with one message.
     *
     * @param Finding $finding - Finding to reduce to its symbol-less key.
     *
     * @return string - NUL-delimited (file, ruleId, message) key.
     */
    public static function groupKeyForFinding(Finding $finding): string
    {
        return implode("\0", [$finding->filePath, $finding->ruleId, $finding->message]);
    }
}
