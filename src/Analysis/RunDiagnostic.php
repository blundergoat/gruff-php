<?php

declare(strict_types=1);

namespace GruffPhp\Analysis;

/**
 * Represents a diagnostic emitted while preparing or parsing an analysis run.
 */
final readonly class RunDiagnostic
{
    /**
     * Capture a non-finding diagnostic emitted during a gruff run.
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
