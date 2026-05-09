<?php

declare(strict_types=1);

namespace GruffPhp\Source;

final readonly class SourceDiscoveryResult
{
    /**
     * @param list<SourceFile> $files
     * @param list<string> $missingPaths
     * @param list<string> $ignoredPaths
     */
    public function __construct(
        public array $files,
        public array $missingPaths,
        public array $ignoredPaths,
    ) {
    }

    public function hasInputErrors(): bool
    {
        return $this->missingPaths !== [];
    }
}
