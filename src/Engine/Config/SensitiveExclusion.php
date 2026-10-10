<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Support\PathHelper;

/**
 * One reviewed sensitive-data exclusion the user wrote under `sensitiveExclusions:`.
 *
 * A user who has read a sensitive-data finding and decided it is a synthetic fixture rather than a
 * live credential records that decision here: exactly one rule id, exactly one project-relative
 * path, an optional symbol, and the rationale a reviewer will read later. The scope is deliberately
 * narrow - the same rule in another file and another rule in the same file both keep reporting -
 * and nothing about the matched value takes part in the decision, so a suppression can never be
 * written against the secret itself.
 */
final readonly class SensitiveExclusion
{
    /**
     * Holds one validated exclusion entry; every field is already checked by
     * {@see SensitiveExclusionConfigParser}, so matching never re-validates.
     *
     * @param string      $ruleId - Exact sensitive-data rule id this entry suppresses.
     * @param string      $path - Project-relative display path this entry is scoped to.
     * @param string|null $symbol - Symbol that narrows the scope further; null when the entry covers the whole file.
     * @param string      $reason - Reviewer-supplied rationale published in the audit row.
     */
    public function __construct(
        public string  $ruleId,
        public string  $path,
        public ?string $symbol,
        public string  $reason,
    ) {
    }

    /**
     * Decides whether one finding falls inside this entry's declared scope, comparing only the rule
     * id, the display path, and the optional symbol - never the finding's message or matched value.
     *
     * @param Finding $finding - Finding to test against this entry's scope.
     *
     * @return bool - true when rule id, display path, and (when configured) symbol all match exactly; false otherwise, so the finding keeps
     *              reporting.
     */
    public function matches(Finding $finding): bool
    {
        // A different rule is a different decision, so it never rides along on this entry.
        if ($finding->ruleId !== $this->ruleId) {
            return false;
        }

        // Compare the finding's own project-relative display path, so the caller's working directory cannot widen the scope.
        if (PathHelper::normalizeSeparators($finding->filePath) !== $this->path) {
            return false;
        }

        // With no configured symbol the whole file is in scope; with one, only that exact symbol is.
        return $this->symbol === null || $finding->symbol === $this->symbol;
    }

    /**
     * Renders the (rule, path, symbol) triple as one comparable key, so the loader can reject a
     * second entry claiming the same scope instead of splitting its audit count arbitrarily.
     *
     * @return string - NUL-joined rule, path, and symbol triple; the empty symbol slot is a whole-file scope, never a symbol literally named "".
     */
    public function scopeKey(): string
    {
        return implode("\0", [$this->ruleId, $this->path, $this->symbol ?? '']);
    }
}
