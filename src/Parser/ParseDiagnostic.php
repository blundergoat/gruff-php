<?php

declare(strict_types=1);

namespace GruffPhp\Parser;

final readonly class ParseDiagnostic
{
    public function __construct(
        public string $message,
        public int $line,
    ) {
    }
}
