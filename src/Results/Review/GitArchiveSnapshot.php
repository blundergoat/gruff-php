<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Diff\DiffException;
use GruffPhp\Support\PathHelper;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extracts a throwaway copy of a git ref's tree to a temp directory, so a branch review can analyse
 * the base branch without ever touching the user's working tree.
 *
 * To tell the user what their branch changed, gruff has to analyse the base branch too - but it can't
 * just check the base out, that would clobber uncommitted work. Instead this archives the ref with
 * `git archive`, extracts it to a temp folder, and hands back the path. It validates the ref against an
 * allowlist first (so a crafted ref can't smuggle in git options or shell metacharacters), narrows the
 * archive to the paths under review, and always cleans up the intermediate tar and temp tree afterwards.
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
     * @throws RuntimeException When the temporary snapshot directory cannot be created.
     *
     * @throws DiffException When the ref is unsafe or git/tar cannot produce the snapshot.
     */
    public function create(string $projectRoot, string $ref, array $paths = []): string
    {
        $ref      = $this->validatedRef($ref);
        $tempRoot = rtrim(sys_get_temp_dir(), '/') . '/gruff-review-' . bin2hex(random_bytes(6));
        // The temp directory could not be created and does not already exist, so there is nowhere to extract into.
        if (!mkdir($tempRoot, 0700, true) && !is_dir($tempRoot)) {
            throw new RuntimeException(sprintf('Unable to create review snapshot directory "%s".', $tempRoot));
        }

        $archivePath = $tempRoot . '.tar';

        try {
            // Only narrow the archive to specific files when the caller actually asked for paths.
            $hasPathFilter = $paths !== [];
            $archivePaths  = $hasPathFilter ? $this->existingPathsInRef($projectRoot, $ref, $paths) : [];

            // The user asked for paths, but none exist at this ref, so archiving would error - hand back an empty snapshot instead.
            if ($hasPathFilter && $archivePaths === []) {
                // None of the requested paths exist at the ref, so git archive would error; yield an empty snapshot.
                return $tempRoot;
            }

            $archiveCommand = ['git', 'archive', '--format=tar', '--output', $archivePath, $ref];
            // Restrict the archive to just the paths that do exist at the ref.
            if ($archivePaths !== []) {
                $archiveCommand[] = '--';
                array_push($archiveCommand, ...$archivePaths);
            }

            $archiveProcess = new Process($archiveCommand, $projectRoot);
            $archiveProcess->run();

            // git archive failed, so surface its error (or a fallback message) as a diff error.
            if (!$archiveProcess->isSuccessful()) {
                throw new DiffException(trim($archiveProcess->getErrorOutput()) !== ''
                                            ? trim($archiveProcess->getErrorOutput())
                                            : sprintf('Unable to archive base ref "%s".', $ref));
            }

            $extractProcess = new Process(['tar', '-xf', $archivePath, '-C', $tempRoot]);
            $extractProcess->run();

            // Extraction failed, so the snapshot tree is unusable - report it.
            if (!$extractProcess->isSuccessful()) {
                throw new DiffException(trim($extractProcess->getErrorOutput()) !== ''
                                            ? trim($extractProcess->getErrorOutput())
                                            : sprintf('Unable to extract base ref "%s".', $ref));
            }

            // Archive and extract both succeeded; the snapshot root now holds the ref's tree for comparison.
            return $tempRoot;
        } catch (\Throwable $throwable) {
            // On any failure, delete the partial archive before cleaning up so no temp file is left behind.
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
     * Recursively deletes a snapshot directory, tolerating one that is already gone so cleanup can be
     * called freely from any exit path.
     *
     * @param string $path - Snapshot directory path to remove.
     *
     * @return void
     */
    public function remove(string $path): void
    {
        // Already gone or never created, so there is nothing to delete.
        if (!is_dir($path)) {
            // Already gone or never created, so deletion is a no-op; tolerating this keeps cleanup idempotent.
            return;
        }

        $recursiveIteratorIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        // Walk the tree depth-first (children before parents) so each directory is empty by the time it is removed.
        foreach ($recursiveIteratorIterator as $file) {
            // Skip anything the iterator yields that is not a real filesystem entry.
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $pathname = $file->getPathname();

            // A directory is removed once its children are gone; a plain file is simply unlinked.
            if ($file->isDir()) {
                rmdir($pathname);
                continue;
            }

            unlink($pathname);
        }

        rmdir($path);
    }

    /**
     * Lists which of the requested paths actually exist at the ref, so the archive is limited to real
     * files and git archive is never handed a path that would make it error.
     *
     * @param string       $projectRoot - Working tree the git ls-tree runs in.
     * @param string       $ref - Git ref whose tree is queried for the requested paths.
     * @param list<string> $paths - Path filters to test; normalised to root-relative before the query.
     *
     * @return list<string> - Root-relative paths present at the ref, sorted and de-duplicated; empty when none of the requested paths exist there.
     */
    private function existingPathsInRef(string $projectRoot, string $ref, array $paths): array
    {
        $candidatePaths = $this->normaliseArchivePaths($projectRoot, $paths);
        // Nothing survived normalisation, so there is nothing to look up - report no matching paths.
        if ($candidatePaths === []) {
            // Nothing survived normalisation, so skip the ls-tree call and report no matching paths.
            return [];
        }

        $process = new Process(
            array_merge(['git', 'ls-tree', '-r', '-z', '--name-only', $ref, '--'], $candidatePaths),
            $projectRoot,
        );
        $process->run();

        // git ls-tree failed, so we cannot tell which paths exist - report it as a diff error.
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

        // Empty means no requested path exists at the ref; the caller reads that as "archive nothing", not an error.
        return $paths;
    }

    /**
     * Rewrites the caller's path filters to clean root-relative form, dropping anything that points
     * outside the project so only in-tree paths ever reach git.
     *
     * @param string       $projectRoot - Root that absolute inputs are made relative to; paths outside it are dropped.
     * @param list<string> $paths - Caller path filters, absolute or relative, possibly with `./` prefixes.
     *
     * @return list<string> - Root-relative path filters, sorted and de-duplicated; empty when every input pointed outside the tree.
     */
    private function normaliseArchivePaths(string $projectRoot, array $paths): array
    {
        $root       = rtrim(PathHelper::canonical($projectRoot), '/');
        $normalised = [];

        // Normalise each requested path in turn, keeping only the ones that land inside the project.
        foreach ($paths as $path) {
            $candidate = PathHelper::normalizeSeparators($path);
            // A blank path names nothing, so skip it.
            if ($candidate === '') {
                continue;
            }

            // An absolute path has to be brought back inside the project root before git can use it.
            if (PathHelper::isAbsolute($candidate)) {
                $candidate = rtrim(PathHelper::canonical($candidate), '/');
                // Re-anchor it: the root itself becomes ".", a path inside the root becomes relative to
                // it, and anything outside the tree is dropped.
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

        // Paths outside the project root are dropped, not rejected, so empty can mean every input was out-of-tree.
        return $paths;
    }

    /**
     * Allowlists a caller-supplied ref before it is spliced into a git command line, so a hostile ref
     * cannot inject git options or shell metacharacters.
     *
     * @param string $ref - Untrusted caller-supplied ref name to allowlist before it reaches the command line.
     *
     * @return string - The same ref, returned unchanged once it passes the allowlist so callers can splice it in unquoted.
     */
    private function validatedRef(string $ref): string
    {
        // Allow only ref characters that can be passed to git archive without shell expansion or option confusion.
        if ($ref === '' || str_starts_with($ref, '-') || preg_match('/^[A-Za-z0-9._\/@^~+-]+$/', $ref) !== 1) {
            throw new DiffException(sprintf('Git archive base ref "%s" is not a safe git ref name.', $ref));
        }

        // Having passed the ref-character allowlist, the value cannot carry shell metacharacters or a
        // leading dash, so callers may splice it into the git command line unquoted without injection risk.
        return $ref;
    }
}
