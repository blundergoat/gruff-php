<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

use Symfony\Component\Process\Process;

final readonly class GitDiffProvider
{
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

        $parsed = $this->parseUnifiedDiff($process->getOutput());

        return new DiffResult(
            active: true,
            mode: $this->normaliseMode($mode),
            base: $this->baseForMode($mode),
            changedLines: $parsed['lines'],
            changedFiles: $parsed['files'],
            message: 'Diff mode filters findings to changed lines when line ranges are available, otherwise to changed files.',
        );
    }

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
            default => ['git', 'diff', '--unified=0', '--no-ext-diff', $mode, '--'],
        };
    }

    private function normaliseMode(string $mode): string
    {
        return in_array($mode, ['staged', 'unstaged', 'working-tree'], true) ? $mode : 'base-ref';
    }

    private function baseForMode(string $mode): ?string
    {
        return in_array($mode, ['staged', 'unstaged', 'working-tree'], true) ? null : $mode;
    }

    /**
     * @return array{files: list<string>, lines: array<string, list<ChangedLineRange>>}
     */
    private function parseUnifiedDiff(string $diff): array
    {
        $changedFiles = [];
        $changedLines = [];
        $currentFile = null;

        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (str_starts_with($line, '+++ ')) {
                $currentFile = $this->parseNewFilePath($line);

                if ($currentFile !== null && !in_array($currentFile, $changedFiles, true)) {
                    $changedFiles[] = $currentFile;
                    $changedLines[$currentFile] = [];
                }

                continue;
            }

            if ($currentFile === null || !preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
                continue;
            }

            $startLine = (int) $matches[1];
            $length = isset($matches[2]) ? (int) $matches[2] : 1;
            $changedLines[$currentFile][] = ChangedLineRange::fromStartAndLength($startLine, $length);
        }

        sort($changedFiles, SORT_STRING);
        ksort($changedLines, SORT_STRING);

        return [
            'files' => $changedFiles,
            'lines' => $changedLines,
        ];
    }

    private function parseNewFilePath(string $line): ?string
    {
        $rawPath = substr($line, 4);

        if ($rawPath === '/dev/null') {
            return null;
        }

        if (str_starts_with($rawPath, 'b/')) {
            return substr($rawPath, 2);
        }

        return $rawPath;
    }
}
