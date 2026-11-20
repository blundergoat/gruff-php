<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

/**
 * Parses unified diff text into changed paths and new-side line ranges for diff-scoped analysis.
 * File and rename headers register paths; additions and delete-only hunk anchors contribute ranges.
 * Whole-file deletions keep the old path with an empty range list.
 *
 * The resulting scope describes patch locations rather than when findings were introduced.
 */
final readonly class UnifiedDiffParser
{
    /**
     * Collects changed paths and new-side ranges from unified diff text.
     * Additions and delete-only hunk anchors contribute ranges; whole-file deletions keep an empty range list.
     *
     * @param string $diff - Unified diff text; file and rename headers can register paths without hunks.
     *
     * @return array{files: list<string>, lines: array<string, list<ChangedLineRange>>} - Sorted changed paths and their new-side ranges.
     */
    public function parse(string $diff): array
    {
        $changedFiles = [];
        $changedLines = [];
        $currentFile  = null;
        $oldFile      = null;
        $currentNewLine = null;
        $isCurrentFileDeleted = false;

        // Walk the patch line by line, splitting on any newline convention; an unsplittable diff walks
        // nothing and an empty one walks a single empty line - either way the changed set comes out empty, as a clean run should show.
        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            // A `--- ` line opens a file's diff; note its old path in case the new side turns out to be a deletion.
            if ($this->hasConsumedOldFileHeader($line, $oldFile, $currentNewLine, $isCurrentFileDeleted)) {
                continue;
            }

            // A `+++ ` line names the file this hunk writes to - the path findings will later hang off.
            if ($this->hasConsumedNewFileHeader(
                line:                 $line,
                oldFile:              $oldFile,
                currentFile:          $currentFile,
                currentNewLine:       $currentNewLine,
                isCurrentFileDeleted: $isCurrentFileDeleted,
                changedFiles:         $changedFiles,
                changedLines:         $changedLines,
            )) {
                continue;
            }

            // A pure rename carries no hunks, so catch its `rename from`/`rename to` lines to still mark the file touched.
            if ($this->hasConsumedRenameHeader($line, $changedFiles, $changedLines)) {
                continue;
            }

            // An `@@` line opens a hunk and tells us which new-file line the additions below begin at.
            if ($this->hasConsumedHunkHeader(
                line:                 $line,
                currentFile:          $currentFile,
                isCurrentFileDeleted: $isCurrentFileDeleted,
                currentNewLine:       $currentNewLine,
                changedLines:         $changedLines,
            )) {
                continue;
            }

            // No header matched, so this is a hunk body line: an addition, deletion, or context line to account for.
            $this->consumeHunkBodyLine($line, $currentFile, $currentNewLine, $changedLines);
        }

        // Sort the files and their range buckets so the changed set is stable run to run and diff-friendly in reports.
        sort($changedFiles, SORT_STRING);
        ksort($changedLines, SORT_STRING);

        return [
            'files' => $changedFiles,
            'lines' => $changedLines,
        ];
    }

    /**
     * Handles the `--- ` line that opens a file's diff, stashing the old path so a following
     * `+++ /dev/null` deletion still knows which file the user removed.
     *
     * @param string      $line - Diff line under inspection this iteration.
     * @param string|null $oldFile - Set to the parsed source path, or null when the old side is `/dev/null` (the user is adding a new file).
     * @param int|null    $currentNewLine - Reset to null; null means no hunk is currently being counted.
     * @param bool        $isCurrentFileDeleted - Reset to false; a fresh file is assumed present until its `+++` header proves otherwise.
     *
     * @return bool - true when the line was a `--- ` header and the caller should skip to the next line; false hands it to the next matcher.
     */
    private function hasConsumedOldFileHeader(string $line, ?string &$oldFile, ?int &$currentNewLine, bool &$isCurrentFileDeleted): bool
    {
        // Only `--- ` lines are old-file headers; anything else belongs to a later matcher, so bail out.
        if (!str_starts_with($line, '--- ')) {
            return false;
        }

        $oldFile              = $this->parseOldFilePath($line);
        $currentNewLine       = null;
        $isCurrentFileDeleted = false;

        return true;
    }

    /**
     * Handles the `+++ ` line that names the file a hunk writes to, then registers that file in the
     * changed set so later findings know which path to attach to - even for a pure deletion.
     *
     * @param string                                  $line - Diff line under inspection this iteration.
     * @param string|null                             $oldFile - Source path from the preceding `--- ` header (null if that side was `/dev/null`); cleared here once used.
     * @param string|null                             $currentFile - Set to the file this hunk targets; null only if neither side named a real path.
     * @param int|null                                $currentNewLine - Reset to null until a hunk header starts counting new-file lines.
     * @param bool                                    $isCurrentFileDeleted - Set true when the destination is `/dev/null`, marking a file the user deleted.
     * @param list<string>                            $changedFiles - Changed files collected so far.
     * @param array<string, list<ChangedLineRange>>   $changedLines - Changed ranges keyed by file.
     *
     * @return bool - true when the line was a `+++ ` header and the caller should skip to the next line; false hands it to the next matcher.
     */
    private function hasConsumedNewFileHeader(
        string $line,
        ?string &$oldFile,
        ?string &$currentFile,
        ?int &$currentNewLine,
        bool &$isCurrentFileDeleted,
        array &$changedFiles,
        array &$changedLines,
    ): bool {
        // Only `+++ ` lines are new-file headers; skip out fast so the other matchers get their turn.
        if (!str_starts_with($line, '+++ ')) {
            return false;
        }

        // Prefer the new path, but fall back to the old one when the destination is `/dev/null` - git's
        // way of spelling "the user deleted this file", which we still track, just with no added lines.
        $newFile              = $this->parseNewFilePath($line);
        $currentFile          = $newFile ?? $oldFile;
        $isCurrentFileDeleted = $newFile === null && $oldFile !== null;
        $oldFile              = null;
        $currentNewLine       = null;

        $this->appendChangedFile($currentFile, $changedFiles, $changedLines);

        return true;
    }

    /**
     * Handles `rename from` / `rename to` lines so a file the user only moved - no content change and
     * therefore no hunks - still appears in the changed set under both its old and new path.
     *
     * @param string                                $line - Diff line under inspection this iteration.
     * @param list<string>                          $changedFiles - Changed files collected so far.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file.
     *
     * @return bool - True for a consumed rename header; false leaves the line for another handler.
     */
    private function hasConsumedRenameHeader(string $line, array &$changedFiles, array &$changedLines): bool
    {
        // `rename from <path>` records the file's old name as touched, so a move is never silently dropped.
        if (str_starts_with($line, 'rename from ')) {
            $this->appendChangedFile($this->normaliseHeaderPath(substr($line, 12)), $changedFiles, $changedLines);

            return true;
        }

        // `rename to <path>` records the new name too, so the moved file is reviewable at its destination.
        if (str_starts_with($line, 'rename to ')) {
            $this->appendChangedFile($this->normaliseHeaderPath(substr($line, 10)), $changedFiles, $changedLines);

            return true;
        }

        return false;
    }

    /**
     * Starts new-side line tracking for a hunk or records its delete-only anchor.
     * Whole-file deletions leave line tracking disabled because they have no new-side lines.
     *
     * @param string $line - Candidate hunk header.
     * @param string|null $currentFile - Active changed-file path; null prevents the header from being consumed.
     * @param bool $isCurrentFileDeleted - Whether the active file was deleted, disabling new-side line tracking.
     * @param int|null $currentNewLine - Receives the new-side start, or null for a deleted file or zero-length new side.
     * @param array<string, list<ChangedLineRange>> $changedLines - Receives an anchor for a non-deleted file's zero-length new side.
     *
     * @return bool - True for a recognized hunk header with a current path; false otherwise.
     */
    private function hasConsumedHunkHeader(
        string $line,
        ?string $currentFile,
        bool $isCurrentFileDeleted,
        ?int &$currentNewLine,
        array &$changedLines,
    ): bool {
        // A hunk with no file header before it is malformed; ignore it rather than attach lines to a null path.
        if ($currentFile === null) {
            return false;
        }

        // The `@@ -a,b +c,d @@` header carries the new-file start line (group 1) and optional length
        // (group 2); a line that doesn't match this shape isn't a hunk header, so leave it for the body consumer.
        if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches) !== 1) {
            return false;
        }

        // Start counting new-file lines at the hunk's `+` offset; a deleted file has no new side, so
        // there is nothing to count and line tracking stays null.
        $currentNewLine = $isCurrentFileDeleted ? null : (int)$matches[1];
        $length         = isset($matches[2]) ? (int)$matches[2] : 1;

        // A zero-length new side has no added lines; use its reported offset, clamped to line 1, as the change anchor.

        if (!$isCurrentFileDeleted && $length === 0) {
            $changedLines[$currentFile] = $this->appendChangedLine($changedLines[$currentFile], max(1, (int)$matches[1]));
            $currentNewLine = null;
        }

        return true;
    }

    /**
     * Handles the context, `+`, and `-` lines inside a hunk, advancing the new-file line counter and
     * recording every added line as part of the user's changed region.
     *
     * @param string $line - Diff hunk body line.
     * @param string|null $currentFile - Active destination path; null leaves the line unprocessed.
     * @param int|null $currentNewLine - Current new-side line; null disables body-line processing.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file.
     *
     * @return void - Updates the caller's range map and new-side line counter in place.
     */
    private function consumeHunkBodyLine(string $line, ?string $currentFile, ?int &$currentNewLine, array &$changedLines): void
    {
        // Skip any line we can't place: no active file or hunk, or git's `\ No newline at end of file`
        // marker, which describes the patch itself rather than being a line the user changed.
        if ($currentFile === null || $currentNewLine === null || str_starts_with($line, '\\ No newline')) {
            return;
        }


        if (str_starts_with($line, '+')) {
            $changedLines[$currentFile] = $this->appendChangedLine($changedLines[$currentFile], $currentNewLine);
            $currentNewLine++;

            return;
        }

        // A context line (leading space) also exists on the new side, so advance past it; a `-` deletion
        // has no new-side line, so it alone leaves the counter untouched.
        if (!str_starts_with($line, '-')) {
            $currentNewLine++;
        }
    }

    /**
     * Registers a changed path exactly once and opens an empty range bucket for it, so every file the
     * user touched appears a single time in the changed set no matter how many headers mention it.
     *
     * @param string|null $filePath - Parsed changed path; null leaves both collections unchanged.
     * @param list<string> $changedFiles - Changed files collected so far.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file.
     *
     * @return void - Adds a new path and empty range bucket in place; existing paths retain their ranges.
     */
    private function appendChangedFile(?string $filePath, array &$changedFiles, array &$changedLines): void
    {
        // Ignore `/dev/null` (null) paths and any file already recorded, so a rename or a repeated header
        // can't list the same file twice or wipe the line ranges already collected for it.
        if ($filePath === null || in_array($filePath, $changedFiles, true)) {
            return;
        }

        $changedFiles[]          = $filePath;
        $changedLines[$filePath] = [];
    }

    /**
     * Folds a newly added line into the file's range list, extending or merging with the previous range
     * when they touch, so the user sees tidy spans like 10-14 instead of five separate one-line rows.
     *
     * @param list<ChangedLineRange> $ranges - Ranges accumulated for one changed file; empty when this is the file's first added line.
     * @param int                    $line - New-file line number that changed.
     *
     * @return list<ChangedLineRange> - Ranges with the changed line appended or merged into the last span.
     */
    private function appendChangedLine(array $ranges, int $line): array
    {
        $lastIndex = count($ranges) - 1;
        // Additions arrive in ascending order, so only the most recent range can absorb this line; an
        // empty list (lastIndex below zero) has no range to merge with and falls through to open a new one.
        if ($lastIndex >= 0) {
            $lastRange = $ranges[$lastIndex];

            if ($line >= $lastRange->startLine && $line <= $lastRange->endLine) {
                return $ranges;
            }


            if ($line === $lastRange->endLine + 1) {
                $ranges[$lastIndex] = new ChangedLineRange($lastRange->startLine, $line);
                return array_values($ranges);
            }
        }


        $ranges[] = new ChangedLineRange($line, $line);

        return $ranges;
    }

    /**
     * Extracts the destination path from a `+++ ` header, removing a leading `b/`.
     * `/dev/null` denotes a deleted file and yields null.
     *
     * @param string $line - A `+++ ` header line to decode.
     *
     * @return string|null - Decoded destination path without a leading `b/`, or null for `/dev/null`.
     */
    private function parseNewFilePath(string $line): ?string
    {
        $rawPath = $this->normaliseHeaderPath(substr($line, 4));

        // A `/dev/null` destination means the file was deleted, so there is no current path to attach lines to.
        if ($rawPath === '/dev/null') {
            return null;
        }


        if (str_starts_with($rawPath, 'b/')) {
            return substr($rawPath, 2);
        }

        return $rawPath;
    }

    /**
     * Extracts the source path from a `--- ` header, removing a leading `a/`.
     * `/dev/null` denotes a newly added file and yields null.
     *
     * @param string $line - A `--- ` header line to decode.
     *
     * @return string|null - Decoded source path without a leading `a/`, or null for `/dev/null`.
     */
    private function parseOldFilePath(string $line): ?string
    {
        $rawPath = $this->normaliseHeaderPath(substr($line, 4));

        // A `/dev/null` source means the file is brand new, so there is no previous path to remember.
        if ($rawPath === '/dev/null') {
            return null;
        }


        if (str_starts_with($rawPath, 'a/')) {
            return substr($rawPath, 2);
        }

        return $rawPath;
    }

    /**
     * Cleans up the raw path slice from a diff header so the rest of the parser gets a plain path: it
     * decodes git's C-quoted form (used for non-ASCII names under `core.quotePath`) and strips any
     * trailing tab-separated metadata some patch tools append. Each file-path header parser here uses it.
     *
     * @param string $rawPath - Path slice from a diff header, still possibly C-quoted or tab-suffixed.
     *
     * @return string - Header path with tab metadata removed and surrounding C-quotes decoded; prefixes and absolute paths remain.
     */
    private function normaliseHeaderPath(string $rawPath): string
    {
        $tabIndex = strpos($rawPath, "\t");

        // Some patch formats append `\t<metadata>` after the path; cut at the tab so it never leaks into the filename.
        if ($tabIndex !== false) {
            $rawPath = substr($rawPath, 0, $tabIndex);
        }

        // A double-quoted path is git's escaped form for awkward characters; unwrap it and decode the `\`
        // escapes so the user sees the real filename rather than `\303\251`-style byte sequences.
        if (strlen($rawPath) >= 2 && $rawPath[0] === '"' && $rawPath[strlen($rawPath) - 1] === '"') {
            return stripcslashes(substr($rawPath, 1, -1));
        }

        return $rawPath;
    }
}
