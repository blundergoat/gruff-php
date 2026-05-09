<?php

declare(strict_types=1);

namespace GruffPhp\Source;

final readonly class SourceFile
{
    public function __construct(
        public string $absolutePath,
        public string $displayPath,
    ) {
    }
}
