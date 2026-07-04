<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Baseline\BaselineEntry;
use GruffPhp\Results\Finding\Finding;

/**
 * Builds the stable identity key that lets a branch review tell one finding from another across two
 * runs, without being fooled by unrelated line shifts.
 *
 * When a user compares their branch against a base (`gruff-php analyse --diff-vs origin/main`), gruff
 * needs to know which findings are genuinely new, which went away, and which are the same issue as
 * before. Matching on line number would be brittle - editing code above a finding would make it look
 * new. This class produces a line-insensitive key so a finding that merely moved stays recognised as
 * the same one, keeping the review's "introduced / removed / unchanged" verdict honest.
 */
final readonly class FindingReviewIdentity
{
    /**
     * Builds the comparison key a branch review matches findings on, so a finding is judged by what
     * and where it is - never by the line it happens to sit on this time.
     *
     * When the user runs `gruff-php analyse --diff-vs origin/main`, this key decides whether a finding
     * reads as introduced, removed, or unchanged. Lines never participate, so a finding that merely
     * moved does not show up as churn: findings with a symbol key on (file, ruleId, symbol, message);
     * symbol-less ones reuse the baseline group key so review and baselines share one identity.
     *
     * @param Finding $finding - Finding to identify for branch-review comparison.
     *
     * @return string - NUL-delimited identity key that survives unrelated edits shifting line numbers.
     */
    public function key(Finding $finding): string
    {
        // No symbol to anchor on: fall back to the shared (file, ruleId, message) baseline key so a
        // symbol-less finding still lines up with its counterpart across runs.
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
