<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Parser;

/**
 * Represents one parser diagnostic for a source file.
 */
final readonly class ParseDiagnostic
{
    /**
     * Capture a parser diagnostic message and its best-known source line.
     *
      * User flow: Prepares source files so findings point at the right code.
      *
     * @param string $message - Parser diagnostic message.
     * @param int    $line - Best-known source line for the diagnostic.
     */
    public function __construct(
        public string $message,
        public int $line,
    ) {
    }
}
