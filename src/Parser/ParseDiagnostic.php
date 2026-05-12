<?php

declare(strict_types=1);

namespace GruffPhp\Parser;

/**
 * Represents one parser diagnostic for a source file.
 */
final readonly class ParseDiagnostic
{
    /**
     * Capture a parser diagnostic message and its best-known source line.
     */
    public function __construct(
        public string $message,
        public int $line,
    ) {
    }
}
