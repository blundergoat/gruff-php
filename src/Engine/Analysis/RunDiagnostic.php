<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

/**
 * A non-finding note from a run - something that went wrong or is worth telling the user, but is not a
 * code-quality finding.
 *
 * Not everything gruff needs to report is a rule violation: a file that would not parse, a missing
 * Infection binary, a misused flag. These are captured as run diagnostics so the reporters can show
 * them alongside the findings, each tagged with a type and pointed at the relevant file, line, or input
 * path when there is one.
 */
final readonly class RunDiagnostic
{
    /**
     * Captures one non-finding diagnostic - its category, message, and whatever location context exists.
     *
     * @param string      $type - Diagnostic category the report serializers switch on (for example 'parse-error' or 'usage-error').
     * @param string      $message - Human-readable detail shown to the user.
     * @param string|null $filePath - Source file the diagnostic relates to; null when it is not tied to a parsed file.
     * @param int|null    $line - Source line the diagnostic relates to; null when no specific line applies.
     * @param string|null $path - Input path the diagnostic relates to when no parsed file exists; null when not applicable.
     * @param bool        $isFatal - Whether this diagnostic makes the analysis result untrustworthy.
     */
    public function __construct(
        public string  $type,
        public string  $message,
        public ?string $filePath = null,
        public ?int    $line = null,
        public ?string $path = null,
        public bool    $isFatal = true,
    ) {
    }

    /**
     * Flattens the diagnostic into the JSON shape reports emit, so an editor or CI sees the same note a
     * person reads in the terminal.
     *
     * @return array{type: string, message: string, file: string|null, line: int|null, path: string|null, invalidatesRun?: false} - report-ready
     *                     snapshot; invalidatesRun is emitted only for a non-fatal diagnostic because older consumers treat all existing types as fatal.
     */
    public function toArray(): array
    {
        $diagnostic = [
            'type'    => $this->type,
            'message' => $this->message,
            'file'    => $this->filePath,
            'line'    => $this->line,
            'path'    => $this->path,
        ];

        // Existing diagnostic types remain wire-compatible; only the new non-fatal case needs an explicit marker.
        if (!$this->isFatal) {
            $diagnostic['invalidatesRun'] = false;
        }

        return $diagnostic;
    }
}
