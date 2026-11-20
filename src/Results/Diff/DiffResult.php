<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

/**
 * Carries changed paths, line ranges, and status metadata for diff-scoped analysis.
 * Paths use the display spelling consumed by finding filters and report serialization.
 * An inactive result tells the filter to keep every finding.
 *
 * A missing or empty range list leaves a changed file unrestricted by line.
 * The optional suppressed count is attached after filtering and omitted from output while null.
 */
final readonly class DiffResult
{
    /**
     * Records the changed set and filtering metadata shared by later stages.
     * Active results identify the user's edits in scope; inactive results preserve every finding.
     *
     * @param bool $active - Whether filtering is enabled; false preserves all findings.
     * @param string $mode - Source of the changed set, such as `stdin`, `explicit-ranges`, or `base-ref`.
     * @param string|null $base - Base ref for `base-ref` mode; null for local, stdin, explicit-range, or inactive results.
     * @param array<string, list<ChangedLineRange>> $changedLines - Ranges by display path; empty ranges leave listed files unrestricted.
     * @param list<string> $changedFiles - Display paths in scope; an empty list in an active result excludes every finding.
     * @param string $message - Status text explaining the selected diff mode.
     * @param int|null $suppressedCount - Number filtered out; null omits the tally from serialization.
     */
    public function __construct(
        public bool    $active,
        public string  $mode,
        public ?string $base,
        public array   $changedLines,
        public array   $changedFiles,
        public string  $message,
        public ?int    $suppressedCount = null,
    ) {
    }

    /**
     * Represents an ordinary whole-project scan with diff filtering disabled.
     * Use it wherever a DiffResult is required but no diff was requested.
     *
     * @return self - Inactive result with empty changed sets; filters still preserve every finding.
     */
    public static function inactive(): self
    {
        // Sentinel for "no diff requested": active=false is the flag downstream filters check to keep
        // every finding, so the empty changed-file/line sets here must never be read as "nothing changed".
        return new self(false, 'full-project', null, [], [], 'Diff mode is disabled.');
    }

    /**
     * Returns a copy of this result with the "how many findings were hidden" tally attached - called
     * once changed-region filtering knows that number, so the report can tell the user what it dropped.
     *
     * @param int $suppressedCount - Count of findings excluded by the selected diff scope.
     *
     * @return self - The same diff metadata plus the suppressed count, ready for report serialisation.
     */
    public function withSuppressedCount(int $suppressedCount): self
    {
        return new self(
            active:          $this->active,
            mode:            $this->mode,
            base:            $this->base,
            changedLines:    $this->changedLines,
            changedFiles:    $this->changedFiles,
            message:         $this->message,
            suppressedCount: $suppressedCount,
        );
    }

    /**
     * Looks up changed-line ranges for one display path without testing changed-file membership.
     *
     * @param string $filePath - Display path to look up, exactly as it appears in reports.
     *
     * @return list<ChangedLineRange> - Stored ranges, or an empty list for an absent path; this does not imply that the file changed.
     */
    public function rangesFor(string $filePath): array
    {


        return $this->changedLines[$filePath] ?? [];
    }

    /**
     * Flattens this result into the plain array the JSON report serialises, so `--format json`
     * consumers (CI gates, editors) receive the diff verdict in one stable, documented shape.
     *
     * @return array{
     *     active: bool,
     *     mode: string,
     *     base: string|null,
     *     changedFiles: int,
     *     message: string,
     *     suppressedCount?: int,
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
        $payload = [
            'active'       => $this->active,
            'mode'         => $this->mode,
            'base'         => $this->base,
            'changedFiles' => count($this->changedFiles),
            'message'      => $this->message,
            'files'        => $files,
        ];

        // Only expose the suppressed tally once filtering has computed it; leaving the key out entirely,
        // rather than sending 0, stops the report from implying nothing was hidden when it simply isn't known.
        if ($this->suppressedCount !== null) {
            $payload['suppressedCount'] = $this->suppressedCount;
        }

        return $payload;
    }
}
