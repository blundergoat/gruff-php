<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

/**
 * The answer to "should gruff skip this path?" - whether it is ignored and, if so, why.
 *
 * As gruff walks a project it asks the ignore engine about each path; this carries the verdict back.
 * When a path is ignored it also records the reason (config, default, generated, gitignore) and the
 * pattern that matched, so a report can tell the user not just that a file was skipped but which rule
 * skipped it - handy when a file they expected to be scanned quietly was not.
 */
final readonly class IgnoreDecision
{
    /**
     * Captures whether a path is ignored and, when it is, the reason and matching pattern.
     *
     * @param bool        $ignored - True when the path is excluded from analysis.
     * @param string|null $source - Reason category when ignored (config, default, generated, or gitignore); null when the path is in scope.
     * @param string|null $pattern - The glob, directory token, filename, or git rule that matched; null when in scope or when no concrete pattern applied.
     */
    public function __construct(
        public bool $ignored,
        public ?string $source = null,
        public ?string $pattern = null,
    ) {
    }

    /**
     * Builds an "ignored" decision that remembers why the path was excluded, so reports can attribute it.
     *
     * @param string      $source - Reason category for the exclusion.
     * @param string|null $pattern - The glob, directory token, filename, or git rule that matched; null when there was no concrete match string.
     *
     * @return self - An ignored decision carrying the source and pattern.
     */
    public static function ignored(string $source, ?string $pattern): self
    {
        // Always flags ignored=true; the source and pattern record why so reports can attribute the exclusion.
        return new self(true, $source, $pattern);
    }

    /**
     * Builds a plain "not ignored" decision for a path that stays in scope.
     *
     * @return self - A decision marking the path as in scope, with no source or pattern.
     */
    public static function notIgnored(): self
    {
        // In-scope path: source and pattern stay null because there is no exclusion to attribute.
        return new self(false);
    }
}
