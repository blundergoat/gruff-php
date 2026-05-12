<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

final readonly class DiffResult
{
    /**
     * @param array<string, list<ChangedLineRange>> $changedLines
     * @param list<string> $changedFiles
     */
    public function __construct(
        public bool $active,
        public string $mode,
        public ?string $base,
        public array $changedLines,
        public array $changedFiles,
        public string $message,
    ) {
    }

    /**
     * Create a result object representing a full-project run without diff mode.
     *
     * @return self Inactive diff result with empty changed-file data.
     */
    public static function inactive(): self
    {
        return new self(false, 'full-project', null, [], [], 'Diff mode is disabled.');
    }

    /**
     * Check whether a display path is part of the changed-file set.
     *
     * @return bool True when the file was marked as changed.
     */
    public function hasFile(string $filePath): bool
    {
        return in_array($filePath, $this->changedFiles, true);
    }

    /**
     * @return list<ChangedLineRange>
     */
    public function rangesFor(string $filePath): array
    {
        return $this->changedLines[$filePath] ?? [];
    }

    /**
     * @return array{
     *     active: bool,
     *     mode: string,
     *     base: string|null,
     *     changedFiles: int,
     *     message: string,
     *     files: list<array{file: string, ranges: list<array{start: int, end: int}>}>
     * }
     */
    public function toArray(): array
    {
        $files = [];

        foreach ($this->changedFiles as $filePath) {
            $files[] = [
                'file' => $filePath,
                'ranges' => array_map(
                    static fn (ChangedLineRange $range): array => $range->toArray(),
                    $this->rangesFor($filePath),
                ),
            ];
        }

        return [
            'active' => $this->active,
            'mode' => $this->mode,
            'base' => $this->base,
            'changedFiles' => count($this->changedFiles),
            'message' => $this->message,
            'files' => $files,
        ];
    }
}
