<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

use Symfony\Component\Process\Process;

/**
 * Reads git diff output and converts it into changed-line ranges.
 */
final readonly class GitDiffProvider
{
    /**
     * Read changed files and line ranges from git diff output.
     *
     * @param string $projectRoot Git working tree root.
     * @param string $mode        Diff mode or base ref.
     * @throws DiffException When git diff cannot run or the base ref is unsafe.
     * @return DiffResult Diff metadata and changed-line ranges for the requested mode.
     */
    public function changedLines(string $projectRoot, string $mode): DiffResult
    {
        $this->ensureGitWorkTree($projectRoot);
        $command = $this->diffCommand($mode);
        $process = new Process($command, $projectRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new DiffException(trim($process->getErrorOutput()) !== ''
                ? trim($process->getErrorOutput())
                : sprintf('Unable to compute git diff for mode "%s".', $mode));
        }

        $parsed      = $this->parseUnifiedDiff($process->getOutput());
        $isLocalMode = in_array($mode, ['staged', 'unstaged', 'working-tree'], true);

        if ($mode === 'working-tree') {
            $this->appendUntrackedFiles($projectRoot, $parsed['files'], $parsed['lines']);
        }

        return new DiffResult(
            active:       true,
            mode:         $isLocalMode ? $mode : 'base-ref',
            base:         $isLocalMode ? null : $mode,
            changedLines: $parsed['lines'],
            changedFiles: $parsed['files'],
            message:      'Diff mode filters findings to changed lines when line ranges are available, otherwise to changed files.',
        );
    }

    /**
     * Include untracked, unignored files in the working-tree diff scope.
     *
     * @param string                                $projectRoot  Git working tree root.
     * @param list<string>                          $changedFiles Changed files collected so far.
     * @param array<string, list<ChangedLineRange>> $changedLines Changed ranges keyed by file.
     * @throws DiffException When Git cannot list untracked files.
     * @return void
     */
    private function appendUntrackedFiles(string $projectRoot, array &$changedFiles, array &$changedLines): void
    {
        $process = new Process(['git', 'ls-files', '--others', '--exclude-standard', '-z'], $projectRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new DiffException(trim($process->getErrorOutput()) !== ''
                ? trim($process->getErrorOutput())
                : 'Unable to list untracked files for working-tree diff mode.');
        }

        foreach (explode("\0", $process->getOutput()) as $filePath) {
            if ($filePath === '') {
                continue;
            }

            $this->appendChangedFile($filePath, $changedFiles, $changedLines);
        }

        sort($changedFiles, SORT_STRING);
        ksort($changedLines, SORT_STRING);
    }

    /**
     * Ensure diff mode only runs inside a git working tree.
     *
     * @return void
     */
    private function ensureGitWorkTree(string $projectRoot): void
    {
        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $projectRoot);
        $process->run();

        if (!$process->isSuccessful() || trim($process->getOutput()) !== 'true') {
            throw new DiffException('Diff mode requires a git working tree.');
        }
    }

    /**
     * Build the git diff command used to calculate changed lines.
     *
     * @return list<string>
     */
    private function diffCommand(string $mode): array
    {
        return match ($mode) {
            'staged' => ['git', 'diff', '--cached', '--unified=0', '--no-ext-diff', '--find-renames', '--'],
            'unstaged' => ['git', 'diff', '--unified=0', '--no-ext-diff', '--find-renames', '--'],
            'working-tree' => ['git', 'diff', '--unified=0', '--no-ext-diff', '--find-renames', 'HEAD', '--'],
            default => ['git', 'diff', '--merge-base', '--unified=0', '--no-ext-diff', '--find-renames', $this->validatedRef($mode), '--'],
        };
    }

    /**
     * Reject unsafe refs before passing them to git.
     *
     * @return string The validated git ref name.
     */
    private function validatedRef(string $ref): string
    {
        // Allow only ref characters that can be passed to git without shell expansion or option confusion.
        if ($ref === '' || str_starts_with($ref, '-') || preg_match('/^[A-Za-z0-9._\/@^~+-]+$/', $ref) !== 1) {
            throw new DiffException(sprintf('Diff base ref "%s" is not a safe git ref name.', $ref));
        }

        return $ref;
    }

    /**
     * Parse unified diff for the diff parser.
     *
     * @return array{files: list<string>, lines: array<string, list<ChangedLineRange>>}
     */
    private function parseUnifiedDiff(string $diff): array
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

            // Read a unified-diff hunk header and capture the starting new-file line and length.
            if ($currentFile === null || !preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
                continue;
            }

            $startLine = (int) $matches[1];
            $length    = isset($matches[2]) ? (int) $matches[2] : 1;
            if ($length === 0) {
                continue;
            }

            $endLine = $startLine + $length - 1;

            $changedLines[$currentFile][] = new ChangedLineRange($startLine, $endLine);
        }

        sort($changedFiles, SORT_STRING);
        ksort($changedLines, SORT_STRING);

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
            return;
        }

        $changedFiles[]          = $filePath;
        $changedLines[$filePath] = [];
    }

    /**
     * Parse the destination path from a unified diff header.
     *
     * @return string|null Current-file path, or null for deleted files.
     */
    private function parseNewFilePath(string $line): ?string
    {
        $rawPath = $this->normaliseHeaderPath(substr($line, 4));

        if ($rawPath === '/dev/null') {
            return null;
        }

        if (str_starts_with($rawPath, 'b/')) {
            return substr($rawPath, 2);
        }

        return $rawPath;
    }

    /**
     * Parse the source path from a unified diff header.
     *
     * @return string|null Previous-file path, or null for new files.
     */
    private function parseOldFilePath(string $line): ?string
    {
        $rawPath = $this->normaliseHeaderPath(substr($line, 4));

        if ($rawPath === '/dev/null') {
            return null;
        }

        if (str_starts_with($rawPath, 'a/')) {
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
     * @return string Cleaned header path.
     */
    private function normaliseHeaderPath(string $rawPath): string
    {
        $tabIndex = strpos($rawPath, "\t");

        if ($tabIndex !== false) {
            $rawPath = substr($rawPath, 0, $tabIndex);
        }

        if (strlen($rawPath) >= 2 && $rawPath[0] === '"' && $rawPath[strlen($rawPath) - 1] === '"') {
            return stripcslashes(substr($rawPath, 1, -1));
        }

        return $rawPath;
    }
}
