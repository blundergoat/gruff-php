<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

/**
 * The verdict on what a diff-scoped run should judge: the files and line ranges the user actually
 * changed, keyed by the path shown in reports. The analyser builds one of these whenever a run is
 * narrowed to a diff (say `gruff-php analyse --diff main`), and downstream filters read it to drop
 * findings that fall outside those edits so the user only sees issues in code they touched. When
 * the user runs a plain whole-project scan instead, `inactive()` stands in and tells those same
 * filters to keep every finding. Alongside the changed set it carries the plain-English status
 * line shown to the user and, once filtering has run, how many findings the narrowing hid.
 */
final readonly class DiffResult
{
    /**
     * Records exactly what a diff-scoped run should judge, so every later stage reads one shared
     * source of truth for which of the user's edits are in play.
     *
     * @param bool                                  $active - Whether diff filtering is on; false means score the whole project and keep every finding.
     * @param string                                $mode - How the changed set was derived (for example `stdin`, `explicit-ranges`, or `base-ref`), echoed back to the user in reports.
     * @param string|null                           $base - Base ref the diff was taken against; set only for `base-ref` mode. Null for the local staged/unstaged/working-tree, stdin, and explicit-ranges modes (none name a ref) and for an inactive whole-project run.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed line ranges keyed by display path; empty when nothing was narrowed, which leaves whole files in scope.
     * @param list<string>                          $changedFiles - Display paths the user touched; empty means no file was marked changed - but on an active diff (a clean working tree, say) filtering still runs. A full-project run is `$active` being false, not this list being empty.
     * @param string                                $message - Plain-English status line shown to the user, explaining why diff mode is on or off.
     * @param int|null                              $suppressedCount - How many findings the changed-region filter hid; null until filtering has run, so the report omits the suppressed tally.
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
     * Stands in for "the user asked for an ordinary whole-project scan, not a diff", so scoring runs
     * over everything. Reach for it wherever a `DiffResult` is required but no diff was requested.
     *
     * @return self - Inactive result (active=false) with empty changed-file/line sets; downstream filters read
     *   this as "keep every finding", not "nothing changed".
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
     * Needed because the object is readonly, so the count can only be added by making a new one.
     *
     * @param int $suppressedCount - Count of findings the diff filter excluded because they sat outside the user's changed lines.
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
     * Answers "which lines did the user change in this file?" - called per file while filtering, so a
     * finding can be kept when it lands on a changed line and dropped when it falls outside the edit.
     *
     * @param string $filePath - Display path to look up, exactly as it appears in reports.
     *
     * @return list<ChangedLineRange> - Changed ranges for that path; an empty list when the path has no entry,
     *   which callers read as "whole file in scope" rather than an error.
     */
    public function rangesFor(string $filePath): array
    {
        // A path with no entry is an expected miss, not an error: a file the diff never recorded has no
        // restricted ranges, so returning empty lets callers treat the whole file as in-scope, not skipped.
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

        // Turn every changed file into a path-plus-ranges record - the per-file detail a reader or
        // editor expands to see exactly which lines the user's diff touched.
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
