<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

/**
 * Carries a run diagnostic separately from code-quality findings.
 * Its type and message can include optional file, line or input-path context.
 * Diagnostics are fatal by default; non-fatal notes carry an explicit serialized marker.
 */
final readonly class RunDiagnostic
{
    /**
     * The one type every surface publishes when it cannot read the changed-region scope it was asked to analyse,
     * whatever flag asked for it (FAMILY-CONTRACT.md section 6). Analyse and the hook share it so one failure
     * cannot carry two names.
     */
    public const string CHANGED_REGION_TYPE = 'changed-region';

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
     * Flattens the diagnostic into the JSON shape emitted by reports.
     *
     * @return array{type: string, message: string, file: string|null, line: int|null, path: string|null, invalidatesRun?: false} - Report fields;
     *     invalidatesRun is false for non-fatal diagnostics and omitted for fatal ones.
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

        // Fatal diagnostics omit the marker for wire compatibility; non-fatal diagnostics set it to false.
        if (!$this->isFatal) {
            $diagnostic['invalidatesRun'] = false;
        }

        return $diagnostic;
    }
}
