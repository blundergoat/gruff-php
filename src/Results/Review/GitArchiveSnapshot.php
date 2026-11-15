<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Diff\DiffException;
use GruffPhp\Support\PathHelper;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extracts a Git ref into a temporary tree for branch-review analysis without checking out that ref.
 * Rejects empty refs, leading dashes, and unsupported characters; requested paths may restrict the archive.
 * Attempts to remove the intermediate tar on success or failure; callers own removal of successfully returned trees.
 */
final readonly class GitArchiveSnapshot
{
    /**
     * Builds a temp snapshot of the ref's tree for the review to analyse, tidying up the intermediate
     * archive whether the run succeeds or fails.
     *
     * @param string       $projectRoot - Git working tree the archive is taken from.
     * @param string       $ref - Git ref to snapshot (validated against a safe-character allowlist first).
     * @param list<string> $paths - Optional path filters to include; empty archives the whole tree.
     *
     * @return string - Path to the extracted snapshot tree; an empty directory when path filters match nothing at the ref.
     * @throws RuntimeException - When the temporary snapshot directory cannot be created.
     * @throws DiffException - When the ref is unsafe or git/tar cannot produce the snapshot.
     */
    public function create(string $projectRoot, string $ref, array $paths = []): string
    {
        $ref      = $this->validatedRef($ref);
        $tempRoot = rtrim(sys_get_temp_dir(), '/') . '/gruff-review-' . bin2hex(random_bytes(6));

        if (!mkdir($tempRoot, 0700, true) && !is_dir($tempRoot)) {
            throw new RuntimeException(sprintf('Unable to create review snapshot directory "%s".', $tempRoot));
        }

        $archivePath = $tempRoot . '.tar';

        try {

            $hasPathFilter = $paths !== [];
            $archivePaths  = $hasPathFilter ? $this->existingPathsInRef($projectRoot, $ref, $paths) : [];

            // The user asked for paths, but none exist at this ref, so archiving would error - hand back an empty snapshot instead.
            if ($hasPathFilter && $archivePaths === []) {

                return $tempRoot;
            }

            $archiveCommand = ['git', 'archive', '--format=tar', '--output', $archivePath, $ref];

            if ($archivePaths !== []) {
                $archiveCommand[] = '--';
                array_push($archiveCommand, ...$archivePaths);
            }

            $archiveProcess = new Process($archiveCommand, $projectRoot);
            $archiveProcess->run();


            if (!$archiveProcess->isSuccessful()) {
                throw new DiffException(trim($archiveProcess->getErrorOutput()) !== ''
                                            ? trim($archiveProcess->getErrorOutput())
                                            : sprintf('Unable to archive base ref "%s".', $ref));
            }

            $extractProcess = new Process(['tar', '-xf', $archivePath, '-C', $tempRoot]);
            $extractProcess->run();


            if (!$extractProcess->isSuccessful()) {
                throw new DiffException(trim($extractProcess->getErrorOutput()) !== ''
                                            ? trim($extractProcess->getErrorOutput())
                                            : sprintf('Unable to extract base ref "%s".', $ref));
            }


            return $tempRoot;
        } catch (\Throwable $throwable) {

            if (is_file($archivePath)) {
                unlink($archivePath);
            }
            $this->remove($tempRoot);

            throw $throwable;
        } finally {
            // The extracted tree is what we return; the intermediate .tar is always removed, success or not.
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
        }
    }

    /**
     * Recursively deletes a snapshot directory, tolerating one that is already gone so cleanup can be called freely from any exit path.
     *
     * @param string $path - Snapshot directory path to remove.
     *
     * @return void - No cleanup is attempted when the path is not a directory.
     */
    public function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $recursiveIteratorIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        // Walk the tree depth-first (children before parents) so each directory is empty by the time it is removed.
        foreach ($recursiveIteratorIterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $pathname = $file->getPathname();

            if ($file->isDir()) {
                rmdir($pathname);
                continue;
            }

            unlink($pathname);
        }

        rmdir($path);
    }

    /**
     * Lists tracked files matched by the requested paths, so git archive receives only entries present
     * at the ref.
     *
     * @param string       $projectRoot - Working tree the git ls-tree runs in.
     * @param string       $ref - Git ref whose tree is queried for the requested paths.
     * @param list<string> $paths - Path filters normalised for Git before the query; escaping relative paths remain for Git to reject.
     *
     * @return list<string> - Matched root-relative file paths, sorted and de-duplicated; empty when no requested path matches a tracked file.
     */
    private function existingPathsInRef(string $projectRoot, string $ref, array $paths): array
    {
        $candidatePaths = $this->normaliseArchivePaths($projectRoot, $paths);

        if ($candidatePaths === []) {

            return [];
        }

        $process = new Process(
            array_merge(['git', 'ls-tree', '-r', '-z', '--name-only', $ref, '--'], $candidatePaths),
            $projectRoot,
        );
        $process->run();


        if (!$process->isSuccessful()) {
            throw new DiffException(trim($process->getErrorOutput()) !== ''
                                        ? trim($process->getErrorOutput())
                                        : sprintf('Unable to list files for base ref "%s".', $ref));
        }

        $paths = array_values(array_filter(
                                  explode("\0", $process->getOutput()),
                                  // Drop the empty trailing entry the NUL-separated output leaves behind.
                                  static fn(string $path): bool => $path !== '',
                              ));
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        // No requested path matched a tracked file, so the caller archives nothing rather than reporting an error.
        return $paths;
    }

    /**
     * Normalises the caller's path filters for Git, dropping absolute paths outside the project.
     * Relative traversal segments remain for Git to reject if they escape the tree.
     *
     * @param string       $projectRoot - Root that absolute inputs are made relative to; absolute paths outside it are dropped.
     * @param list<string> $paths - Caller path filters, absolute or relative, possibly with ./ prefixes.
     *
     * @return list<string> - Sorted, de-duplicated filters; empty when every input was blank or an absolute path outside the tree.
     */
    private function normaliseArchivePaths(string $projectRoot, array $paths): array
    {
        $root       = rtrim(PathHelper::canonical($projectRoot), '/');
        $normalised = [];

        // Normalise each requested path; only absolute paths are checked against the project root.
        foreach ($paths as $path) {
            $candidate = PathHelper::normalizeSeparators($path);
            if ($candidate === '') {
                continue;
            }

            // An absolute path has to be brought back inside the project root before git can use it.
            if (PathHelper::isAbsolute($candidate)) {
                $candidate = rtrim(PathHelper::canonical($candidate), '/');
                // Re-anchor it to the root: the root itself becomes "." and anything outside the tree is dropped.
                if ($candidate === $root) {
                    $candidate = '.';
                } elseif (str_starts_with($candidate, $root . '/')) {
                    $candidate = substr($candidate, strlen($root) + 1);
                } else {
                    continue;
                }
            }

            while (str_starts_with($candidate, './')) {
                $candidate = substr($candidate, 2);
            }

            $candidate                                        = rtrim($candidate, '/');
            // A path that emptied out collapses to ".", the whole-tree filter.
            $normalised[$candidate === '' ? '.' : $candidate] = $candidate === '' ? '.' : $candidate;
        }

        $paths = array_values($normalised);
        sort($paths, SORT_STRING);

        // Absolute paths outside the project root are dropped, not rejected, so empty can mean every input was out-of-tree.
        return $paths;
    }

    /**
     * Checks an untrusted ref against the argument allowlist before passing it separately to Git.
     * Empty values, leading dashes, and characters outside the allowlist are rejected.
     *
     * @param string $ref - Untrusted caller-supplied ref value to check before passing it to Git.
     *
     * @return string - Original ref after the allowlist check, for use as a separate Process argument.
     */
    private function validatedRef(string $ref): string
    {
        // Restrict untrusted refs to a conservative character set and reject option-like values before invoking git archive.
        if ($ref === '' || str_starts_with($ref, '-') || preg_match('/^[A-Za-z0-9._\/@^~+-]+$/', $ref) !== 1) {
            throw new DiffException(sprintf('Git archive base ref "%s" is not a safe git ref name.', $ref));
        }



        return $ref;
    }
}
