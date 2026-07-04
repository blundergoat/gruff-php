<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

/**
 * The outcome of scanning for files to analyse - what gruff found, what it could not resolve, and what
 * it deliberately skipped.
 *
 * Before any rule runs, gruff works out which files are in scope. This carries that result: the source
 * files to analyse (in a stable order), the requested paths that did not resolve to anything, and the
 * paths ignored by config or Git. The summary command reads these counts straight off it, so the user
 * sees "N discovered, N ignored, N missing".
 */
final readonly class SourceDiscoveryResult
{
    /**
     * Bundles the discovered files with the missing and ignored paths from one discovery pass.
     *
     * @param list<SourceFile>  $files - Discovered source files, in deterministic analysis order.
     * @param list<string>      $missingPaths - Requested paths that could not be resolved, so the user knows what was skipped.
     * @param list<string>      $ignoredPaths - Project-relative ignored paths (kept as a plain-string compatibility surface).
     * @param list<IgnoredPath> $ignoredPathDetails - Ignored paths enriched with why they were ignored and which pattern matched.
     */
    public function __construct(
        public array $files,
        public array $missingPaths,
        public array $ignoredPaths,
        public array $ignoredPathDetails = [],
    ) {
    }

    /**
     * Reports whether any requested path went unresolved, so the caller can warn the user rather than
     * treat a mistyped path as "nothing to analyse".
     *
     * @return bool - True when discovery recorded any missing input path.
     */
    public function hasInputErrors(): bool
    {
        // Any unresolved requested path is a caller-facing input error, distinct from a clean empty result.
        return $this->missingPaths !== [];
    }
}
