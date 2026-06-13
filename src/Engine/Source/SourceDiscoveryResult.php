<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

/**
 * Carries discovered files plus missing and ignored input paths.
 */
final readonly class SourceDiscoveryResult
{
    /**
     * Store discovered files plus missing and ignored path diagnostics.
     *
     * @param list<SourceFile>  $files - Discovered source files in deterministic analysis order.
     * @param list<string>      $missingPaths - Requested paths that could not be resolved.
     * @param list<string>      $ignoredPaths - Project-relative ignored paths (compatibility surface).
     * @param list<IgnoredPath> $ignoredPathDetails - Ignored paths enriched with source and matching pattern.
     */
    public function __construct(
        public array $files,
        public array $missingPaths,
        public array $ignoredPaths,
        public array $ignoredPathDetails = [],
    ) {
    }

    /**
     * Report whether any requested source path could not be resolved.
     *
     * @return bool - True when discovery recorded missing input paths.
     */
    public function hasInputErrors(): bool
    {
        // Any unresolved requested path is a caller-facing input error, distinct from a clean empty result.
        return $this->missingPaths !== [];
    }
}
