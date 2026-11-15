<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

/**
 * Carries an ignore decision with optional reason and matching-pattern details.
 * Source discovery and check-ignore use it to distinguish in-scope paths from exclusions.
 * Reports can use the source and pattern to explain which rule excluded a path.
 */
final readonly class IgnoreDecision
{
    /**
     * Captures whether a path is ignored and, when it is, the reason and matching pattern.
     *
     * @param bool        $ignored - True when the path is excluded from analysis.
     * @param string|null $source - Reason category when ignored (config, default, generated, or gitignore); null when the path is in scope.
     * @param string|null $pattern - Matched glob, directory token, filename, or Git rule; null when in scope or no pattern applies.
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

        return new self(true, $source, $pattern);
    }

    /**
     * Builds a plain "not ignored" decision for a path that stays in scope.
     *
     * @return self - A decision marking the path as in scope, with no source or pattern.
     */
    public static function notIgnored(): self
    {

        return new self(false);
    }
}
