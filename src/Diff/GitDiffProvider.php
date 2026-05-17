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
     * Ensure diff mode only runs inside a git working tree.
     *
     * @return void No return value.
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
     * @return list<string>
     */
    private function diffCommand(string $mode): array
    {
        return match ($mode) {
            'staged' => ['git', 'diff', '--cached', '--unified=0', '--no-ext-diff', '--'],
            'unstaged' => ['git', 'diff', '--unified=0', '--no-ext-diff', '--'],
            'working-tree' => ['git', 'diff', '--unified=0', '--no-ext-diff', 'HEAD', '--'],
            default => ['git', 'diff', '--unified=0', '--no-ext-diff', $this->validatedRef($mode), '--'],
        };
    }

    /**
     * Reject unsafe refs before passing them to git.
     *
     * @return string The validated git ref name.
     */
    private function validatedRef(string $ref): string
    {
        if ($ref === '' || str_starts_with($ref, '-') || preg_match('/^[A-Za-z0-9._\/@^~+-]+$/', $ref) !== 1) {
            throw new DiffException(sprintf('Diff base ref "%s" is not a safe git ref name.', $ref));
        }

        return $ref;
    }

    /**
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

                if ($currentFile !== null && !in_array($currentFile, $changedFiles, true)) {
                    $changedFiles[]             = $currentFile;
                    $changedLines[$currentFile] = [];
                }

                continue;
            }

            if ($currentFile === null || !preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
                continue;
            }

            $startLine                    = (int) $matches[1];
            $length                       = isset($matches[2]) ? (int) $matches[2] : 1;
            $changedLines[$currentFile][] = ChangedLineRange::fromStartAndLength($startLine, $length);
        }

        sort($changedFiles, SORT_STRING);
        ksort($changedLines, SORT_STRING);

        return [
            'files' => $changedFiles,
            'lines' => $changedLines,
        ];
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
        if (strlen($rawPath) >= 2 && $rawPath[0] === '"' && $rawPath[strlen($rawPath) - 1] === '"') {
            return stripcslashes(substr($rawPath, 1, -1));
        }

        $tabIndex = strpos($rawPath, "\t");

        if ($tabIndex !== false) {
            return substr($rawPath, 0, $tabIndex);
        }

        return $rawPath;
    }
}
