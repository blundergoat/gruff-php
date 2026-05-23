<?php

declare(strict_types=1);

namespace GruffPhp\Analysis;

/**
 * Represents a diagnostic emitted while preparing or parsing an analysis run.
 */
final readonly class RunDiagnostic
{
    /**
     * Capture a non-finding diagnostic emitted during a gruff-php run.
     *
     * @param string      $type     Diagnostic category used by report serializers.
     * @param string      $message  Human-readable diagnostic detail.
     * @param string|null $filePath Source file related to the diagnostic, when available.
     * @param int|null    $line     Source line related to the diagnostic, when available.
     * @param string|null $path     Input path related to the diagnostic, when no parsed file exists.
     */
    public function __construct(
        public string $type,
        public string $message,
        public ?string $filePath = null,
        public ?int $line = null,
        public ?string $path = null,
    ) {
    }

    /**
     * Serialize this value object into the array shape used by reports.
     *
     * @return array{type: string, message: string, file: string|null, line: int|null, path: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'file' => $this->filePath,
            'line' => $this->line,
            'path' => $this->path,
        ];
    }
}
