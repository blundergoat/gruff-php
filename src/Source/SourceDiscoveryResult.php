<?php

declare(strict_types=1);

namespace GruffPhp\Source;

/**
 * Carries discovered files plus missing and ignored input paths.
 */
final readonly class SourceDiscoveryResult
{
    /**
     * @param list<SourceFile> $files
     * @param list<string>     $missingPaths
     * @param list<string>     $ignoredPaths
     */
    public function __construct(
        public array $files,
        public array $missingPaths,
        public array $ignoredPaths,
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
