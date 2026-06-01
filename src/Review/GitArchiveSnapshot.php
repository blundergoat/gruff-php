<?php

declare(strict_types=1);

namespace GruffPhp\Review;

use GruffPhp\Diff\DiffException;
use GruffPhp\Support\PathHelper;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Creates temporary git archive snapshots for branch review comparisons.
 */
final readonly class GitArchiveSnapshot
{
    /**
     * @param string       $projectRoot Git working tree root.
     * @param string       $ref         Git ref to archive.
     * @param list<string> $paths       Optional path filters to include in the archive.
     * @throws DiffException When the ref is unsafe or git/tar cannot produce the snapshot.
     * @throws RuntimeException When the temporary snapshot directory cannot be created.
     *
     * @return string Temporary snapshot root path.
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

            if ($hasPathFilter && $archivePaths === []) {
                // None of the requested paths exist at the ref, so git archive would error; yield an empty snapshot.
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

            // Archive and extract both succeeded; the snapshot root now holds the ref's tree for comparison.
            return $tempRoot;
        } catch (\Throwable $throwable) {
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
            $this->remove($tempRoot);

            throw $throwable;
        } finally {
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
        }
    }

    /**
     * Recursively remove a snapshot directory.
     *
     * @param string $path Snapshot directory path to remove.
     * @return void
     */
    public function remove(string $path): void
    {
        if (!is_dir($path)) {
            // Already gone or never created, so deletion is a no-op; tolerating this keeps cleanup idempotent.
            return;
        }

        $recursiveIteratorIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

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
     * List requested paths that exist at a git ref.
     *
     * @param string       $projectRoot Working tree the git ls-tree runs in.
     * @param string       $ref         Git ref whose tree is queried for the requested paths.
     * @param list<string> $paths       Path filters to test; normalised to root-relative before the query.
     * @return list<string>
     */
    private function existingPathsInRef(string $projectRoot, string $ref, array $paths): array
    {
        $candidatePaths = $this->normaliseArchivePaths($projectRoot, $paths);
        if ($candidatePaths === []) {
            // Nothing survived normalisation, so skip the ls-tree call and report no matching paths.
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
            static fn (string $path): bool => $path !== '',
        ));
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        // Empty means no requested path exists at the ref; the caller reads that as "archive nothing", not an error.
        return $paths;
    }

    /**
     * Normalise archive paths for the branch-review workflow.
     *
     * @param string       $projectRoot Root that absolute inputs are made relative to; paths outside it are dropped.
     * @param list<string> $paths       Caller path filters, absolute or relative, possibly with `./` prefixes.
     * @return list<string>
     */
    private function normaliseArchivePaths(string $projectRoot, array $paths): array
    {
        $root       = rtrim(PathHelper::canonical($projectRoot), '/');
        $normalised = [];

        foreach ($paths as $path) {
            $candidate = PathHelper::normalizeSeparators($path);
            if ($candidate === '') {
                continue;
            }

            if (PathHelper::isAbsolute($candidate)) {
                $candidate = rtrim(PathHelper::canonical($candidate), '/');
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
            $normalised[$candidate === '' ? '.' : $candidate] = $candidate === '' ? '.' : $candidate;
        }

        $paths = array_values($normalised);
        sort($paths, SORT_STRING);

        // Paths outside the project root are dropped, not rejected, so empty can mean every input was out-of-tree.
        return $paths;
    }

    /**
     * Validate a Git ref before passing it to archive commands.
     *
     * @param string $ref Untrusted caller-supplied ref name to allowlist before it reaches the command line.
     * @return string Safe Git ref name.
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
