<?php

declare(strict_types=1);

namespace GruffPhp\Source;

/**
 * Outcome of asking the ignore engine whether a single path is excluded.
 */
final readonly class IgnoreDecision
{
    /**
     * @param bool        $ignored Whether the path is excluded from analysis.
     * @param string|null $source  Reason category when ignored: config, default, generated, or gitignore.
     * @param string|null $pattern Matching glob, directory token, filename, or git rule when ignored.
     */
    public function __construct(
        public bool $ignored,
        public ?string $source = null,
        public ?string $pattern = null,
    ) {
    }

    /**
     * Build an "ignored" decision carrying the matched source and pattern.
     *
     * @param string      $source  Reason category for the exclusion.
     * @param string|null $pattern Matching glob, directory token, filename, or git rule.
     * @return self Ignored decision.
     */
    public static function ignored(string $source, ?string $pattern): self
    {
        // Always flags ignored=true; the source and pattern record why so reports can attribute the exclusion.
        return new self(true, $source, $pattern);
    }

    /**
     * Build a "not ignored" decision.
     *
     * @return self Decision indicating the path is in scope.
     */
    public static function notIgnored(): self
    {
        // In-scope path: source and pattern stay null because there is no exclusion to attribute.
        return new self(false);
    }
}
