<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Parser;

/**
 * One problem the PHP parser hit in a source file - a message plus the line it happened on.
 *
 * When gruff cannot fully parse a file, it records these so the run can tell the user which file and
 * line tripped it up rather than silently skipping the code. They surface as the "parse error" notes in
 * reports, warning that a file was left unanalysed rather than scanned and found clean.
 */
final readonly class ParseDiagnostic
{
    /**
     * Captures one parser message and the closest line it could be pinned to.
     *
     * @param string $message - Parser diagnostic message describing what went wrong.
     * @param int    $line    - Best-known source line for the diagnostic, so the user can jump to the problem.
     * @param string $type    - Stable diagnostic category exposed by reporters.
     * @param bool   $isFatal - Whether the unit failed to parse and invalidates the run.
     */
    public function __construct(
        public string $message,
        public int $line,
        public string $type = 'parse-error',
        public bool $isFatal = true,
    ) {
    }
}
