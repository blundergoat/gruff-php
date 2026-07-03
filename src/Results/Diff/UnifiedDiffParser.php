<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

/**
 * Parses unified diff text into changed files and new-file line ranges.
 */
final readonly class UnifiedDiffParser
{
    /**
     * Parse unified diff text.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string $diff - Raw `git diff` output; only added-line hunks contribute to the returned ranges.
     *
     * @return array{files: list<string>, lines: array<string, list<ChangedLineRange>>} - changed file paths sorted ascending, plus per-file
     *                      added-line ranges keyed by path; both empty when the diff has no qualifying hunks
     */
    public function parse(string $diff): array
    {
        $changedFiles = [];
        $changedLines = [];
        $currentFile  = null;
        $oldFile      = null;
        $currentNewLine = null;
        $isCurrentFileDeleted = false;

        // User view: add each item that can appear in review diff feedback.
        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            // User view: choose the review diff feedback branch for this case.
            if ($this->hasConsumedOldFileHeader($line, $oldFile, $currentNewLine, $isCurrentFileDeleted)) {
                continue;
            }

            // User view: choose the review diff feedback branch for this case.
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

            // User view: choose the review diff feedback branch for this case.
            if ($this->hasConsumedRenameHeader($line, $changedFiles, $changedLines)) {
                continue;
            }

            // User view: choose the review diff feedback branch for this case.
            if ($this->hasConsumedHunkHeader(
                line:                 $line,
                currentFile:          $currentFile,
                isCurrentFileDeleted: $isCurrentFileDeleted,
                currentNewLine:       $currentNewLine,
                changedLines:         $changedLines,
            )) {
                continue;
            }

            $this->consumeHunkBodyLine($line, $currentFile, $currentNewLine, $changedLines);
        }

        sort($changedFiles, SORT_STRING);
        ksort($changedLines, SORT_STRING);

        return [
            'files' => $changedFiles,
            'lines' => $changedLines,
        ];
    }

    /**
     * Consume a source-file header line.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string   $line - Diff line to inspect.
     * @param string|null $oldFile - Source path carried to the next destination header.
     * @param int|null $currentNewLine - Current destination line inside the active hunk.
     * @param bool     $isCurrentFileDeleted - Whether the active destination is /dev/null.
     *
     * @return bool - true when the line was an old-file header.
     */
    private function hasConsumedOldFileHeader(string $line, ?string &$oldFile, ?int &$currentNewLine, bool &$isCurrentFileDeleted): bool
    {
        // User view: choose the review diff feedback branch for this case.
        if (!str_starts_with($line, '--- ')) {
            return false;
        }

        $oldFile              = $this->parseOldFilePath($line);
        $currentNewLine       = null;
        $isCurrentFileDeleted = false;

        return true;
    }

    /**
     * Consume a destination-file header line.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string                                  $line - Diff line to inspect.
     * @param string|null                             $oldFile - Source path parsed from the preceding old-file header.
     * @param string|null                             $currentFile - Active project-relative destination path.
     * @param int|null                                $currentNewLine - Current destination line inside the active hunk.
     * @param bool                                    $isCurrentFileDeleted - Whether the active destination is /dev/null.
     * @param list<string>                            $changedFiles - Changed files collected so far.
     * @param array<string, list<ChangedLineRange>>   $changedLines - Changed ranges keyed by file.
     *
     * @return bool - true when the line was a new-file header.
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
        // User view: choose the review diff feedback branch for this case.
        if (!str_starts_with($line, '+++ ')) {
            return false;
        }

        $newFile              = $this->parseNewFilePath($line);
        // User view: missing data becomes a safe review diff feedback default.
        $currentFile          = $newFile ?? $oldFile;
        // User view: missing data becomes the expected review diff feedback state.
        $isCurrentFileDeleted = $newFile === null && $oldFile !== null;
        $oldFile              = null;
        $currentNewLine       = null;

        $this->appendChangedFile($currentFile, $changedFiles, $changedLines);

        return true;
    }

    /**
     * Consume a rename header line.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string                                $line - Diff line to inspect.
     * @param list<string>                          $changedFiles - Changed files collected so far.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file.
     *
     * @return bool - true when the line was a rename header.
     */
    private function hasConsumedRenameHeader(string $line, array &$changedFiles, array &$changedLines): bool
    {
        // User view: choose the review diff feedback branch for this case.
        if (str_starts_with($line, 'rename from ')) {
            $this->appendChangedFile($this->normaliseHeaderPath(substr($line, 12)), $changedFiles, $changedLines);

            return true;
        }

        // User view: choose the review diff feedback branch for this case.
        if (str_starts_with($line, 'rename to ')) {
            $this->appendChangedFile($this->normaliseHeaderPath(substr($line, 10)), $changedFiles, $changedLines);

            return true;
        }

        return false;
    }

    /**
     * Consume a hunk header and initialise destination-line tracking.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string                                $line - Diff line to inspect.
     * @param string|null                           $currentFile - Active project-relative destination path.
     * @param bool                                  $isCurrentFileDeleted - Whether the active destination is /dev/null.
     * @param int|null                              $currentNewLine - Current destination line inside the active hunk.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file.
     *
     * @return bool - true when the line was a hunk header.
     */
    private function hasConsumedHunkHeader(
        string $line,
        ?string $currentFile,
        bool $isCurrentFileDeleted,
        ?int &$currentNewLine,
        array &$changedLines,
    ): bool {
        // User view: choose the review diff feedback branch for this case.
        // User view: missing data becomes the expected review diff feedback state.
        if ($currentFile === null) {
            return false;
        }

        // Match a unified-diff hunk header and capture the destination start line plus optional length.
        // User view: choose the review diff feedback branch for this case.
        if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches) !== 1) {
            return false;
        }

        $currentNewLine = $isCurrentFileDeleted ? null : (int)$matches[1];
        $length         = isset($matches[2]) ? (int)$matches[2] : 1;

        // User view: choose the review diff feedback branch for this case.
        if (!$isCurrentFileDeleted && $length === 0) {
            $changedLines[$currentFile] = $this->appendChangedLine($changedLines[$currentFile], max(1, (int)$matches[1]));
            $currentNewLine = null;
        }

        return true;
    }

    /**
     * Consume a hunk body line and update destination-line ranges.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string                                $line - Diff hunk body line.
     * @param string|null                           $currentFile - Active project-relative destination path.
     * @param int|null                              $currentNewLine - Current destination line inside the active hunk.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file.
     *
     * @return void
     */
    private function consumeHunkBodyLine(string $line, ?string $currentFile, ?int &$currentNewLine, array &$changedLines): void
    {
        // User view: choose the review diff feedback branch for this case.
        // User view: missing data becomes the expected review diff feedback state.
        if ($currentFile === null || $currentNewLine === null || str_starts_with($line, '\\ No newline')) {
            return;
        }

        // User view: choose the review diff feedback branch for this case.
        if (str_starts_with($line, '+')) {
            $changedLines[$currentFile] = $this->appendChangedLine($changedLines[$currentFile], $currentNewLine);
            $currentNewLine++;

            return;
        }

        // User view: choose the review diff feedback branch for this case.
        if (!str_starts_with($line, '-')) {
            $currentNewLine++;
        }
    }

    /**
     * Add a changed file once and prepare its range bucket.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string|null                           $filePath - Project-relative changed path.
     * @param list<string>                          $changedFiles - Changed files collected so far.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file.
     *
     * @return void
     */
    private function appendChangedFile(?string $filePath, array &$changedFiles, array &$changedLines): void
    {
        // User view: choose the review diff feedback branch for this case.
        // User view: missing data becomes the expected review diff feedback state.
        if ($filePath === null || in_array($filePath, $changedFiles, true)) {
            // Skip null paths and files already tracked so each path keeps a single range bucket.
            return;
        }

        $changedFiles[]          = $filePath;
        $changedLines[$filePath] = [];
    }

    /**
     * Append a changed new-file line, merging adjacent added lines into compact ranges.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param list<ChangedLineRange> $ranges - Ranges accumulated for one changed file.
     * @param int                    $line - New-file line number that changed.
     *
     * @return list<ChangedLineRange> - Ranges with the changed line appended or merged.
     */
    private function appendChangedLine(array $ranges, int $line): array
    {
        $lastIndex = count($ranges) - 1;
        // User view: choose the review diff feedback branch for this case.
        if ($lastIndex >= 0) {
            $lastRange = $ranges[$lastIndex];
            // User view: choose the review diff feedback branch for this case.
            if ($line >= $lastRange->startLine && $line <= $lastRange->endLine) {
                return $ranges;
            }

            // User view: choose the review diff feedback branch for this case.
            if ($line === $lastRange->endLine + 1) {
                $ranges[$lastIndex] = new ChangedLineRange($lastRange->startLine, $line);
                return array_values($ranges);
            }
        }

        $ranges[] = new ChangedLineRange($line, $line);

        return $ranges;
    }

    /**
     * Parse the destination path from a unified diff header.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string $line - A `+++ ` header line; its `b/` prefix is stripped to a project-relative path.
     *
     * @return string|null - project-relative destination path, or null when the header points at /dev/null (a deleted file)
     */
    private function parseNewFilePath(string $line): ?string
    {
        $rawPath = $this->normaliseHeaderPath(substr($line, 4));

        // User view: choose the review diff feedback branch for this case.
        if ($rawPath === '/dev/null') {
            // /dev/null on the destination side marks a deletion: there is no current file.
            return null;
        }

        // User view: choose the review diff feedback branch for this case.
        if (str_starts_with($rawPath, 'b/')) {
            // Drop git's `b/` destination prefix to recover the project-relative path.
            return substr($rawPath, 2);
        }

        return $rawPath;
    }

    /**
     * Parse the source path from a unified diff header.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string $line - A `--- ` header line; its `a/` prefix is stripped to a project-relative path.
     *
     * @return string|null - project-relative source path, or null when the header points at /dev/null (a newly added file)
     */
    private function parseOldFilePath(string $line): ?string
    {
        $rawPath = $this->normaliseHeaderPath(substr($line, 4));

        // User view: choose the review diff feedback branch for this case.
        if ($rawPath === '/dev/null') {
            // /dev/null on the source side marks an addition: there is no previous file.
            return null;
        }

        // User view: choose the review diff feedback branch for this case.
        if (str_starts_with($rawPath, 'a/')) {
            // Drop git's `a/` source prefix to recover the project-relative path.
            return substr($rawPath, 2);
        }

        return $rawPath;
    }

    /**
     * Normalise the raw path portion of a git diff header.
     *
     * Handles git's quoted form (core.quotePath / non-ASCII filenames) and strips
     * trailing tab-separated metadata that some patch formats append.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string $rawPath - Path slice from a diff header, still possibly C-quoted or tab-suffixed.
     *
     * @return string - the path with any trailing tab-metadata removed and git C-quoting decoded, ready to use as a project-relative path
     */
    private function normaliseHeaderPath(string $rawPath): string
    {
        $tabIndex = strpos($rawPath, "\t");

        // User view: choose the review diff feedback branch for this case.
        if ($tabIndex !== false) {
            $rawPath = substr($rawPath, 0, $tabIndex);
        }

        // User view: choose the review diff feedback branch for this case.
        if (strlen($rawPath) >= 2 && $rawPath[0] === '"' && $rawPath[strlen($rawPath) - 1] === '"') {
            // Quoted header: strip the surrounding quotes and decode git's C-style escapes.
            return stripcslashes(substr($rawPath, 1, -1));
        }

        return $rawPath;
    }
}
