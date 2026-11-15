<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

/**
 * Carries discovered source files, missing requested paths, and ignored paths from a discovery pass.
 * SourceDiscovery supplies files in canonical-path order and deduplicates ignored paths before constructing the result.
 *
 * Ignore details retain exclusion reasons alongside the plain path list; hasInputErrors() checks only missing inputs.
 */
final readonly class SourceDiscoveryResult
{
    /**
     * Bundles the discovered files with the missing and ignored paths from one discovery pass.
     *
     * @param list<SourceFile>  $files - Discovered source files, in deterministic analysis order.
     * @param list<string>      $missingPaths - Requested paths that could not be resolved, so the user knows what was skipped.
     * @param list<string>      $ignoredPaths - Ignored display paths, retained as a plain-string compatibility surface.
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
     * Reports whether any requested path went unresolved, so the caller can warn the user rather than treat a mistyped path as "nothing to analyse".
     *
     * @return bool - True when discovery recorded any missing input path.
     */
    public function hasInputErrors(): bool
    {

        return $this->missingPaths !== [];
    }
}
