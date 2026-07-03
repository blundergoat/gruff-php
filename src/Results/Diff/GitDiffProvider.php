<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

use Symfony\Component\Process\Process;

/**
 * Reads git diff output and converts it into changed-line ranges.
 */
final readonly class GitDiffProvider
{
    /**
     * Read changed files and line ranges from git diff output.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string $projectRoot - Git working tree root.
     * @param string $mode - Diff mode or base ref.
     *
     * @return DiffResult - changed-line ranges per file plus diff metadata; base ref is null for local modes
     * @throws DiffException When git diff cannot run or the base ref is unsafe.
     */
    public function changedLines(string $projectRoot, string $mode): DiffResult
    {
        $this->ensureGitWorkTree($projectRoot);
        $command = $this->diffCommand($mode);
        $process = new Process($command, $projectRoot);
        $process->run();

        // User view: choose the review diff feedback branch for this case.
        if (!$process->isSuccessful()) {
            // User view: an empty value becomes a clear review diff feedback fallback.
            throw new DiffException(trim($process->getErrorOutput()) !== ''
                                        ? trim($process->getErrorOutput())
                                        : sprintf('Unable to compute git diff for mode "%s".', $mode));
        }

        $parsed      = (new UnifiedDiffParser())->parse($process->getOutput());
        $isLocalMode = in_array($mode, ['staged', 'unstaged', 'working-tree'], true);

        // User view: choose the review diff feedback branch for this case.
        if ($mode === 'working-tree') {
            $this->appendUntrackedFiles($projectRoot, $parsed['files'], $parsed['lines']);
        }

        // Local modes carry no base ref; non-local modes record the ref under base for reporting.
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
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string                                $projectRoot - Git working tree root.
     * @param list<string>                          $changedFiles - Changed files collected so far.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file.
     *
     * @return void
     * @throws DiffException When Git cannot list untracked files.
     */
    private function appendUntrackedFiles(string $projectRoot, array &$changedFiles, array &$changedLines): void
    {
        $process = new Process(['git', 'ls-files', '--others', '--exclude-standard', '-z'], $projectRoot);
        $process->run();

        // User view: choose the review diff feedback branch for this case.
        if (!$process->isSuccessful()) {
            // User view: an empty value becomes a clear review diff feedback fallback.
            throw new DiffException(trim($process->getErrorOutput()) !== ''
                                        ? trim($process->getErrorOutput())
                                        : 'Unable to list untracked files for working-tree diff mode.');
        }

        // User view: add each item that can appear in review diff feedback.
        foreach (explode("\0", $process->getOutput()) as $filePath) {
            // User view: choose the review diff feedback branch for this case.
            // User view: an empty value becomes a clear review diff feedback fallback.
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
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string $projectRoot - Directory the git probe runs in; must be the working tree to inspect.
     *
     * @return void
     */
    private function ensureGitWorkTree(string $projectRoot): void
    {
        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $projectRoot);
        $process->run();

        // User view: choose the review diff feedback branch for this case.
        if (!$process->isSuccessful() || trim($process->getOutput()) !== 'true') {
            throw new DiffException('Diff mode requires a git working tree.');
        }
    }

    /**
     * Build the git diff command used to calculate changed lines.
     *
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string $mode - One of staged|unstaged|working-tree, or a base ref name validated as the diff target.
     *
     * @return list<string> - git command argv where element 0 is "git"; the trailing "--" ends option parsing before paths
     */
    private function diffCommand(string $mode): array
    {
        // --unified=0 yields exact changed-line ranges; --find-renames keeps moved files in scope.
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
      * User flow: Narrows analysis feedback to the code under review.
      *
     * @param string $ref - Caller-supplied base ref; rejected unless it is a safe git ref with no leading dash.
     *
     * @return string - the same ref unchanged once it has cleared the safe-character guard, safe to pass to git
     */
    private function validatedRef(string $ref): string
    {
        // Allow only ref characters that can be passed to git without shell expansion or option confusion.
        // User view: choose the review diff feedback branch for this case.
        // User view: an empty value becomes a clear review diff feedback fallback.
        if ($ref === '' || str_starts_with($ref, '-') || preg_match('/^[A-Za-z0-9._\/@^~+-]+$/', $ref) !== 1) {
            throw new DiffException(sprintf('Diff base ref "%s" is not a safe git ref name.', $ref));
        }

        return $ref;
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
}
