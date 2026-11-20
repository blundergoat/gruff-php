<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Parser;

/**
 * One source diagnostic, carrying a message, line, category, and fatality flag.
 * Fatal diagnostics mark a file as unanalysed; nonfatal diagnostics can report bounded structural analysis.
 * Reporters use the category and fatality flag to distinguish failures from analysis-limit notices.
 */
final readonly class ParseDiagnostic
{
    /**
     * Captures one source diagnostic and its best-known line.
     *
     * @param string $message - Description of the failure or analysis limitation.
     * @param int    $line    - Best-known source line for the diagnostic, so the user can jump to the problem.
     * @param string $type    - Stable diagnostic category exposed by reporters.
     * @param bool   $isFatal - Whether the diagnostic marks the file as unanalysed.
     */
    public function __construct(
        public string $message,
        public int $line,
        public string $type = 'parse-error',
        public bool $isFatal = true,
    ) {
    }
}
