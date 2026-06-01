<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

/**
 * Carries changed-line ranges grouped by display path.
 */
final readonly class DiffResult
{
    /**
     * @param bool                                  $active - Whether diff filtering is active.
     * @param string                                $mode - Diff mode used to produce the result.
     * @param string|null                           $base - Base ref or description for the diff, when active.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed line ranges keyed by display path.
     * @param list<string>                          $changedFiles - Display paths marked as changed.
     * @param string                                $message - Human-readable diff status message.
     */
    public function __construct(
        public bool    $active,
        public string  $mode,
        public ?string $base,
        public array   $changedLines,
        public array   $changedFiles,
        public string  $message,
    ) {
    }

    /**
     * Create a result object representing a full-project run without diff mode.
     *
     * @return self - inactive result (active=false) with empty changed-file/line sets; downstream filters read
     *   this as "keep every finding", not "nothing changed"
     */
    public static function inactive(): self
    {
        // Sentinel for "no diff requested": active=false is what downstream filters check to keep
        // every finding, so the empty changed-file/line sets here must never be read as "nothing changed".
        return new self(false, 'full-project', null, [], [], 'Diff mode is disabled.');
    }

    /**
     * Return changed line ranges for a display path.
     *
     * @param string $filePath - Display path to look up.
     *
     * @return list<ChangedLineRange> - changed ranges for the path; empty list when the path has no entry
     *   (an expected miss, so callers treat the whole file as in-scope)
     */
    public function rangesFor(string $filePath): array
    {
        // An unmapped path is an expected miss, not an error: a file with no entry simply has no
        // changed lines, so empty here lets callers treat the whole file as in-scope rather than skipped.
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
     * } - JSON-serialisable summary of the diff: changedFiles is a count (not the paths), with per-file paths and ranges nested under files
     */
    public function toArray(): array
    {
        $files = [];

        foreach ($this->changedFiles as $filePath) {
            $files[] = [
                'file'   => $filePath,
                'ranges' => array_map(
                    static fn(ChangedLineRange $changedLineRange): array => $changedLineRange->toArray(),
                    $this->rangesFor($filePath),
                ),
            ];
        }

        // The wire shape intentionally diverges from the in-memory one: `changedFiles` is emitted as a
        // count while the per-file paths and ranges move under `files`, so consumers read a summary plus detail.
        return [
            'active'       => $this->active,
            'mode'         => $this->mode,
            'base'         => $this->base,
            'changedFiles' => count($this->changedFiles),
            'message'      => $this->message,
            'files'        => $files,
        ];
    }
}
