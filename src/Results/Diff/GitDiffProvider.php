<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

use Symfony\Component\Process\Process;

/**
 * Turns "only show me what I changed" into concrete files and line ranges by shelling out to git.
 *
 * This backs diff mode for the `analyse` and `hook` commands. When a user runs `gruff-php analyse
 * --diff` (bare, or `staged`, `unstaged`, `working-tree`, or a base ref like `main`), or the `hook`
 * command with `--since`, it runs the matching `git diff`, parses the unified output into the
 * exact changed files and line ranges, and returns a `DiffResult`. The analyser then hides findings
 * on lines the user did not touch, so a reviewer sees only what their branch introduced rather than
 * the whole project's standing backlog.
 */
final readonly class GitDiffProvider
{
    /**
     * The one entry point for diff mode: runs `git diff` for the requested scope and returns the
     * changed files and line ranges the analyser filters against. Called once per `analyse --diff`
     * or hook `--since` run, before any finding is scored.
     *
     * @param string $projectRoot - Git working tree root the diff is computed inside.
     * @param string $mode - Which change set to diff: `staged`, `unstaged`, `working-tree`, or a base ref such as `main`.
     *
     * @return DiffResult - Changed-line ranges per file plus diff metadata; its base ref is null for the local `staged`/`unstaged`/`working-tree` modes and set to the ref only for a base-ref comparison.
     * @throws DiffException When git diff cannot run or the base ref is unsafe.
     */
    public function changedLines(string $projectRoot, string $mode): DiffResult
    {
        $this->ensureGitWorkTree($projectRoot);
        $command = $this->diffCommand($mode);
        $process = new Process($command, $projectRoot);
        $process->run();

        // The diff command itself failed to run - most often a base ref that does not exist (say
        // `--diff=nope`); surface git's own stderr so the user sees why, falling back to a generic
        // message when git stayed silent.
        if (!$process->isSuccessful()) {
            throw new DiffException(trim($process->getErrorOutput()) !== ''
                                        ? trim($process->getErrorOutput())
                                        : sprintf('Unable to compute git diff for mode "%s".', $mode));
        }

        $parsed      = (new UnifiedDiffParser())->parse($process->getOutput());
        $isLocalMode = in_array($mode, ['staged', 'unstaged', 'working-tree'], true);

        // Working-tree mode is the "everything I've touched locally" scope, so also fold in files the
        // user has just created but not `git add`ed yet - plain `git diff HEAD` never lists those, and
        // skipping them would give a falsely clean pass on brand-new code.
        if ($mode === 'working-tree') {
            $this->appendUntrackedFiles($projectRoot, $parsed['files'], $parsed['lines']);
        }

        // Local modes (`staged`/`unstaged`/`working-tree`) have no ref to name, so `base` stays null;
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
     * Folds brand-new files into working-tree scope so a file the user just created - not yet staged
     * or committed - still gets analysed. Honours `.gitignore` (generated and vendored files stay out),
     * and runs only for the `working-tree` mode.
     *
     * @param string                                $projectRoot - Git working tree root the untracked-file listing runs in.
     * @param list<string>                          $changedFiles - Changed files gathered so far; freshly found untracked paths are appended here in place.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file; each new file gets an empty range bucket, which downstream reads as "the whole file changed".
     *
     * @return void
     * @throws DiffException When Git cannot list untracked files.
     */
    private function appendUntrackedFiles(string $projectRoot, array &$changedFiles, array &$changedLines): void
    {
        $process = new Process(['git', 'ls-files', '--others', '--exclude-standard', '-z'], $projectRoot);
        $process->run();

        // Listing untracked files failed - rare, but rather than quietly drop the user's new files and
        // hand back a scope that looks clean only because those files were skipped, stop with git's reason.
        if (!$process->isSuccessful()) {
            throw new DiffException(trim($process->getErrorOutput()) !== ''
                                        ? trim($process->getErrorOutput())
                                        : 'Unable to list untracked files for working-tree diff mode.');
        }

        // Walk each NUL-separated path git reported as untracked-but-not-ignored and add it to scope.
        foreach (explode("\0", $process->getOutput()) as $filePath) {
            // The `-z` output ends in a trailing NUL, so the final split piece is empty; skip it rather
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
     * Guards diff mode behind a real git checkout, so running `gruff-php analyse --diff` outside a
     * repository fails fast with a clear message instead of a confusing empty or half-broken diff.
     *
     * @param string $projectRoot - Directory the git probe runs in; must be inside the working tree to inspect.
     *
     * @return void
     */
    private function ensureGitWorkTree(string $projectRoot): void
    {
        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $projectRoot);
        $process->run();

        // The probe errored or answered anything other than `true`, meaning we are not inside a git
        // checkout at all; stop here so `--diff` reports a clear reason instead of an empty diff.
        if (!$process->isSuccessful() || trim($process->getOutput()) !== 'true') {
            throw new DiffException('Diff mode requires a git working tree.');
        }
    }

    /**
     * Translates the user's `--diff` choice into the exact `git diff` argv to run - one fixed shape
     * per built-in scope, and a merge-base comparison for anything else, treated as a base ref.
     *
     * @param string $mode - The requested scope: `staged`, `unstaged`, `working-tree`, or a base ref like `main` handled by the default arm.
     *
     * @return list<string> - The git command argv (element 0 is `git`); the trailing `--` ends option parsing so a ref or path can never be mistaken for a flag.
     */
    private function diffCommand(string $mode): array
    {
        // `--unified=0` reports the exact changed-line ranges the filter needs; `--find-renames` keeps a
        // file the user moved in review scope instead of showing it as an unrelated delete plus add.
        return match ($mode) {
            'staged' => ['git', 'diff', '--cached', '--unified=0', '--no-ext-diff', '--find-renames', '--'],
            'unstaged' => ['git', 'diff', '--unified=0', '--no-ext-diff', '--find-renames', '--'],
            'working-tree' => ['git', 'diff', '--unified=0', '--no-ext-diff', '--find-renames', 'HEAD', '--'],
            default => ['git', 'diff', '--merge-base', '--unified=0', '--no-ext-diff', '--find-renames', $this->validatedRef($mode), '--'],
        };
    }

    /**
     * Sanity-checks a user-supplied base ref before it reaches git, so a value like `--diff=--evil` can't
     * slip in as an extra git option. The command runs via an argv array, not a shell, so option injection - not shell injection - is the risk guarded here.
     *
     * @param string $ref - The caller-supplied base ref (e.g. from `--diff=main`); rejected unless it is a plain, safe ref with no leading dash.
     *
     * @return string - The same ref, returned unchanged once it clears the safe-character guard so it is safe to hand to git.
     */
    private function validatedRef(string $ref): string
    {
        // Accept only a plain ref: no empty value, no leading dash git would read as an option, and only
        // the characters the `preg_match` whitelists - anything else is a malformed or hostile `--diff`
        // value and is turned away before it can reach git.
        if ($ref === '' || str_starts_with($ref, '-') || preg_match('/^[A-Za-z0-9._\/@^~+-]+$/', $ref) !== 1) {
            throw new DiffException(sprintf('Diff base ref "%s" is not a safe git ref name.', $ref));
        }

        return $ref;
    }

    /**
     * Registers one untracked file in the changed set exactly once, giving it an empty range bucket
     * that marks the whole file as changed. The de-duplication stops a path being scanned twice when
     * it turns up more than once.
     *
     * @param string|null                           $filePath - Project-relative changed path; a null or already-seen path is ignored, so no phantom entry is created.
     * @param list<string>                          $changedFiles - Changed files collected so far; the path is appended here only when it is new.
     * @param array<string, list<ChangedLineRange>> $changedLines - Changed ranges keyed by file; a fresh file gets an empty bucket, which downstream reads as "every line changed".
     *
     * @return void
     */
    private function appendChangedFile(?string $filePath, array &$changedFiles, array &$changedLines): void
    {
        // Ignore a missing path or one already recorded, so each changed file keeps exactly one range
        // bucket and is never counted - or scanned - twice.
        if ($filePath === null || in_array($filePath, $changedFiles, true)) {
            return;
        }

        $changedFiles[]          = $filePath;
        $changedLines[$filePath] = [];
    }
}
