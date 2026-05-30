<?php

declare(strict_types=1);

namespace GruffPhp\Source;

/**
 * Carries discovered files plus missing and ignored input paths.
 */
final readonly class SourceDiscoveryResult
{
    /**
     * Store discovered files plus missing and ignored path diagnostics.
     *
     * @param list<SourceFile>  $files
     * @param list<string>      $missingPaths
     * @param list<string>      $ignoredPaths       Project-relative ignored paths (compatibility surface).
     * @param list<IgnoredPath> $ignoredPathDetails Ignored paths enriched with source and matching pattern.
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
     * @return bool True when discovery recorded missing input paths.
     */
    public function hasInputErrors(): bool
    {
        return $this->missingPaths !== [];
    }
}
