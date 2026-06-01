<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

/**
 * Parses unified diff text into changed files and new-file line ranges.
 */
final readonly class UnifiedDiffParser
{
    /**
     * Parse unified diff text.
     *
     * @param string $diff Raw `git diff` output; only added-line hunks contribute to the returned ranges.
     * @return array{files: list<string>, lines: array<string, list<ChangedLineRange>>}
     */
    public function parse(string $diff): array
    {
        $changedFiles = [];
        $changedLines = [];
        $currentFile  = null;
        $oldFile      = null;

        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (str_starts_with($line, '--- ')) {
                $oldFile = $this->parseOldFilePath($line);
                continue;
            }

            if (str_starts_with($line, '+++ ')) {
                $currentFile = $this->parseNewFilePath($line) ?? $oldFile;
                $oldFile     = null;

                $this->appendChangedFile($currentFile, $changedFiles, $changedLines);

                continue;
            }

            if (str_starts_with($line, 'rename from ')) {
                $this->appendChangedFile($this->normaliseHeaderPath(substr($line, 12)), $changedFiles, $changedLines);

                continue;
            }

            if (str_starts_with($line, 'rename to ')) {
                $this->appendChangedFile($this->normaliseHeaderPath(substr($line, 10)), $changedFiles, $changedLines);

                continue;
            }

            if ($currentFile === null || !preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
                continue;
            }

            $startLine = (int) $matches[1];
            $length    = isset($matches[2]) ? (int) $matches[2] : 1;
            if ($length === 0) {
                continue;
            }

            $changedLines[$currentFile][] = new ChangedLineRange($startLine, $startLine + $length - 1);
        }

        sort($changedFiles, SORT_STRING);
        ksort($changedLines, SORT_STRING);

        // Files and per-file ranges are pre-sorted so downstream diff filtering is deterministic.
        return [
            'files' => $changedFiles,
            'lines' => $changedLines,
        ];
    }

    /**
     * Add a changed file once and prepare its range bucket.
     *
     * @param string|null                           $filePath     Project-relative changed path.
     * @param list<string>                          $changedFiles Changed files collected so far.
     * @param array<string, list<ChangedLineRange>> $changedLines Changed ranges keyed by file.
     * @return void
     */
    private function appendChangedFile(?string $filePath, array &$changedFiles, array &$changedLines): void
    {
        if ($filePath === null || in_array($filePath, $changedFiles, true)) {
            // Skip null paths and files already tracked so each path keeps a single range bucket.
            return;
        }

        $changedFiles[]          = $filePath;
        $changedLines[$filePath] = [];
    }

    /**
     * Parse the destination path from a unified diff header.
     *
     * @param string $line A `+++ ` header line; its `b/` prefix is stripped to a project-relative path.
     * @return string|null Current-file path, or null for deleted files.
     */
    private function parseNewFilePath(string $line): ?string
    {
        $rawPath = $this->normaliseHeaderPath(substr($line, 4));

        if ($rawPath === '/dev/null') {
            // /dev/null on the destination side marks a deletion: there is no current file.
            return null;
        }

        if (str_starts_with($rawPath, 'b/')) {
            // Drop git's `b/` destination prefix to recover the project-relative path.
            return substr($rawPath, 2);
        }

        // No `b/` prefix (e.g. already-normalised input): use the path unchanged.
        return $rawPath;
    }

    /**
     * Parse the source path from a unified diff header.
     *
     * @param string $line A `--- ` header line; its `a/` prefix is stripped to a project-relative path.
     * @return string|null Previous-file path, or null for new files.
     */
    private function parseOldFilePath(string $line): ?string
    {
        $rawPath = $this->normaliseHeaderPath(substr($line, 4));

        if ($rawPath === '/dev/null') {
            // /dev/null on the source side marks an addition: there is no previous file.
            return null;
        }

        if (str_starts_with($rawPath, 'a/')) {
            // Drop git's `a/` source prefix to recover the project-relative path.
            return substr($rawPath, 2);
        }

        // No `a/` prefix (e.g. already-normalised input): use the path unchanged.
        return $rawPath;
    }

    /**
     * Normalise the raw path portion of a git diff header.
     *
     * Handles git's quoted form (core.quotePath / non-ASCII filenames) and strips
     * trailing tab-separated metadata that some patch formats append.
     *
     * @param string $rawPath Path slice from a diff header, still possibly C-quoted or tab-suffixed.
     * @return string Cleaned header path.
     */
    private function normaliseHeaderPath(string $rawPath): string
    {
        $tabIndex = strpos($rawPath, "\t");

        if ($tabIndex !== false) {
            $rawPath = substr($rawPath, 0, $tabIndex);
        }

        if (strlen($rawPath) >= 2 && $rawPath[0] === '"' && $rawPath[strlen($rawPath) - 1] === '"') {
            // Quoted header: strip the surrounding quotes and decode git's C-style escapes.
            return stripcslashes(substr($rawPath, 1, -1));
        }

        // Unquoted ASCII path needs no decoding once any trailing metadata is gone.
        return $rawPath;
    }
}
