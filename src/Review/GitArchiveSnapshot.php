<?php

declare(strict_types=1);

namespace GruffPhp\Review;

use GruffPhp\Diff\DiffException;
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
                return $tempRoot;
            }

            $archiveCommand = ['git', 'archive', '--format=tar', '--output', $archivePath, $ref];
            if ($archivePaths !== []) {
                $archiveCommand[] = '--';
                array_push($archiveCommand, ...$archivePaths);
            }

            $archive = new Process($archiveCommand, $projectRoot);
            $archive->run();

            if (!$archive->isSuccessful()) {
                throw new DiffException(trim($archive->getErrorOutput()) !== ''
                    ? trim($archive->getErrorOutput())
                    : sprintf('Unable to archive base ref "%s".', $ref));
            }

            $extract = new Process(['tar', '-xf', $archivePath, '-C', $tempRoot]);
            $extract->run();

            if (!$extract->isSuccessful()) {
                throw new DiffException(trim($extract->getErrorOutput()) !== ''
                    ? trim($extract->getErrorOutput())
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
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
        }
    }

    /**
     * Recursively remove a snapshot directory.
     *
     * @param string $path Snapshot directory path to remove.
     * @return void No return value.
     */
    public function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
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
     * @param list<string> $paths
     * @return list<string>
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
            static fn (string $path): bool => $path !== '',
        ));
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normaliseArchivePaths(string $projectRoot, array $paths): array
    {
        $root       = $this->normalisePath(realpath($projectRoot) ?: $projectRoot);
        $normalised = [];

        foreach ($paths as $path) {
            $candidate = $this->normalisePath($path);
            if ($candidate === '') {
                continue;
            }

            if (str_starts_with($candidate, '/')) {
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

        return $paths;
    }

    /**
     * Normalise path separators and duplicate slashes for Git path comparisons.
     *
     * @return string Normalised path.
     */
    private function normalisePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        while (str_contains($path, '//')) {
            $path = str_replace('//', '/', $path);
        }

        return $path;
    }

    /**
     * Validate a Git ref before passing it to archive commands.
     *
     * @return string Safe Git ref name.
     */
    private function validatedRef(string $ref): string
    {
        if ($ref === '' || str_starts_with($ref, '-') || preg_match('/^[A-Za-z0-9._\/@^~+-]+$/', $ref) !== 1) {
            throw new DiffException(sprintf('Git archive base ref "%s" is not a safe git ref name.', $ref));
        }

        return $ref;
    }
}
