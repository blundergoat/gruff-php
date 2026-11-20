<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

use Symfony\Component\Process\Process;

/**
 * Obtains changed paths and line ranges from Git for diff-scoped analysis and hook runs.
 * Local modes select staged, unstaged, or working-tree changes; other values name a base ref.
 * Working-tree mode also includes untracked files that Git does not ignore.
 *
 * The returned ranges support scope filtering; they do not prove that retained findings are newly introduced.
 */
final readonly class GitDiffProvider
{
    /**
     * Runs the selected Git comparison and parses its changed paths and line ranges.
     * Working-tree mode also includes untracked, non-ignored files.
     *
     * @param string $projectRoot - Git working tree root the diff is computed inside.
     * @param string $mode - Which change set to diff: staged, unstaged, working-tree, or a base ref such as main.
     *
     * @return DiffResult - Active diff metadata; base is null for local modes and the requested ref otherwise.
     * @throws DiffException - When no working tree is found, a Git command fails, or the ref is unsafe.
     */
    public function changedLines(string $projectRoot, string $mode): DiffResult
    {
        $this->ensureGitWorkTree($projectRoot);
        $command = $this->diffCommand($mode);
        $process = new Process($command, $projectRoot);
        $process->run();

        // If Git rejects the comparison or cannot run it, surface stderr so the user sees the reason;
        // when Git is silent, use a mode-specific fallback.
        if (!$process->isSuccessful()) {
            throw new DiffException(trim($process->getErrorOutput()) !== ''
                                        ? trim($process->getErrorOutput())
                                        : sprintf('Unable to compute git diff for mode "%s".', $mode));
        }

        $parsed      = (new UnifiedDiffParser())->parse($process->getOutput());
        $isLocalMode = in_array($mode, ['staged', 'unstaged', 'working-tree'], true);

        // Working-tree mode includes non-ignored files created but not added to Git; plain git diff HEAD
        // omits them, which could otherwise produce a falsely clean result for new code.
        if ($mode === 'working-tree') {
            $this->appendUntrackedFiles($projectRoot, $parsed['files'], $parsed['lines']);
        }

        // Local modes (staged/unstaged/working-tree) have no ref to name, so base stays null;
        // a base-ref run records the ref so the report can say exactly what it compared against.
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
     * Includes untracked paths in working-tree scope while honoring Git's standard ignore rules.
     * Paths receive empty range lists so filtering treats each as a whole changed file.
     *
     * @param string $projectRoot - Working directory for Git's untracked-file listing.
     * @param list<string> $changedFiles - Receives previously unseen paths, then is sorted by path.
     * @param array<string, list<ChangedLineRange>> $changedLines - Receives empty buckets for new paths, then is sorted by key.
     *
     * @return void - Updates the caller's file list and range map in place.
     * @throws DiffException - When Git cannot list untracked files.
     */
    private function appendUntrackedFiles(string $projectRoot, array &$changedFiles, array &$changedLines): void
    {
        $process = new Process(['git', 'ls-files', '--others', '--exclude-standard', '-z'], $projectRoot);
        $process->run();

        // If Git cannot enumerate untracked files, stop rather than return a scope that silently omits new files.
        if (!$process->isSuccessful()) {
            throw new DiffException(trim($process->getErrorOutput()) !== ''
                                        ? trim($process->getErrorOutput())
                                        : 'Unable to list untracked files for working-tree diff mode.');
        }


        foreach (explode("\0", $process->getOutput()) as $filePath) {
            // The -z output ends in a trailing NUL, so the final split piece is empty; skip it rather
            // than register a phantom file with no real path.
            if ($filePath === '') {
                continue;
            }

            $this->appendChangedFile($filePath, $changedFiles, $changedLines);
        }

        sort($changedFiles, SORT_STRING);
        ksort($changedLines, SORT_STRING);
    }

    /**
     * Guards diff mode behind a real git checkout, so running gruff-php analyse --diff outside a
     * repository fails fast with a clear message instead of a confusing empty or half-broken diff.
     *
     * @param string $projectRoot - Directory the git probe runs in; must be inside the working tree to inspect.
     *
     * @return void - Returns normally only when Git reports that the directory is inside a working tree.
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
     * Translates the user's --diff choice into the exact git diff argv to run - one fixed shape
     * per built-in scope, and a merge-base comparison for anything else, treated as a base ref.
     *
     * @param string $mode - The requested scope: staged, unstaged, working-tree, or a base ref like main handled by the default arm.
     *
     * @return list<string> - Git argv with zero-context hunks and rename detection; base refs are validated before insertion.
     */
    private function diffCommand(string $mode): array
    {
        // --unified=0 reports the exact changed-line ranges; --find-renames lets the parser
        // recognise moved paths and retain both path names.
        return match ($mode) {
            'staged' => ['git', 'diff', '--cached', '--unified=0', '--no-ext-diff', '--find-renames', '--'],
            'unstaged' => ['git', 'diff', '--unified=0', '--no-ext-diff', '--find-renames', '--'],
            'working-tree' => ['git', 'diff', '--unified=0', '--no-ext-diff', '--find-renames', 'HEAD', '--'],
            default => ['git', 'diff', '--merge-base', '--unified=0', '--no-ext-diff', '--find-renames', $this->validatedRef($mode), '--'],
        };
    }

    /**
     * Rejects empty refs, leading dashes, and characters outside the allowed ref-expression set.
     * Git receives an argv array; this guard prevents option injection without proving that the ref exists.
     *
     * @param string $ref - Caller-supplied ref expression; leading dashes and characters outside the allowlist are rejected.
     *
     * @return string - Input unchanged after the syntactic guard; Git still resolves and validates the ref.
     */
    private function validatedRef(string $ref): string
    {
        // Reject empty or option-like inputs and characters outside the ref-expression allowlist; Git
        // still resolves the accepted value and reports whether it names a usable revision.
        if ($ref === '' || str_starts_with($ref, '-') || preg_match('/^[A-Za-z0-9._\/@^~+-]+$/', $ref) !== 1) {
            throw new DiffException(sprintf('Diff base ref "%s" is not a safe git ref name.', $ref));
        }

        return $ref;
    }

    /**
     * Adds a non-null, previously unseen path and initializes its empty range bucket.
     * Existing paths retain their collected ranges; empty ranges allow whole-file filtering.
     *
     * @param string|null $filePath - Project-relative path to add; null or an existing path leaves both collections unchanged.
     * @param list<string> $changedFiles - List that receives the path only when it is new.
     * @param array<string, list<ChangedLineRange>> $changedLines - Range map; a newly added path receives an empty list.
     *
     * @return void - Updates both collections only for a new, non-null path.
     */
    private function appendChangedFile(?string $filePath, array &$changedFiles, array &$changedLines): void
    {


        if ($filePath === null || in_array($filePath, $changedFiles, true)) {
            return;
        }

        $changedFiles[]          = $filePath;
        $changedLines[$filePath] = [];
    }
}
