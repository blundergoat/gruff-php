<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

/**
 * Carries changed-line ranges grouped by display path.
 */
final readonly class DiffResult
{
    /**
     * @param bool                                  $active       Whether diff filtering is active.
     * @param string                                $mode         Diff mode used to produce the result.
     * @param string|null                           $base         Base ref or description for the diff, when active.
     * @param array<string, list<ChangedLineRange>> $changedLines Changed line ranges keyed by display path.
     * @param list<string>                          $changedFiles Display paths marked as changed.
     * @param string                                $message      Human-readable diff status message.
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
     * @param string $filePath Display path to test.
     * @return bool True when the file was marked as changed.
     */
    public function hasFile(string $filePath): bool
    {
        return in_array($filePath, $this->changedFiles, true);
    }

    /**
     * Return changed line ranges for a display path.
     *
     * @param string $filePath Display path to look up.
     * @return list<ChangedLineRange> Changed line ranges for the file.
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
