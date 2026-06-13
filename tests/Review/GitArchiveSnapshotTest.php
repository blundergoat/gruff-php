<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use GruffPhp\Results\Diff\DiffException;
use GruffPhp\Results\Review\GitArchiveSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers git archive snapshotting: requested-path scoping, absolute-path canonicalisation, empty snapshots, cleanup on failure, and rejection of
 * unsafe refs.
 */
final class GitArchiveSnapshotTest extends TestCase
{
    /**
     * Verify create archives only requested base paths.
     *
     * @return void
     */
    public function testCreateArchivesOnlyRequestedBasePaths(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo               = $this->repoWithBaseFiles();
        $gitArchiveSnapshot = new GitArchiveSnapshot();
        $snapshotRoot       = null;

        try {
            $snapshotRoot = $gitArchiveSnapshot->create($repo, 'HEAD', ['src/Target.php']);

            self::assertFileExists($snapshotRoot . '/src/Target.php');
            self::assertFileDoesNotExist($snapshotRoot . '/src/Unrelated.php');
            self::assertDirectoryDoesNotExist($snapshotRoot . '/big');
        } finally {
            if ($snapshotRoot !== null) {
                $gitArchiveSnapshot->remove($snapshotRoot);
            }

            $this->removeDir($repo);
        }
    }

    /**
     * Verify absolute snapshot paths are canonicalized before root trimming.
     *
     * @return void
     */
    public function testCreateCanonicalizesAbsoluteRequestedPaths(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo               = $this->repoWithBaseFiles();
        $gitArchiveSnapshot = new GitArchiveSnapshot();
        $snapshotRoot       = null;

        try {
            $dottedPath   = dirname($repo) . '/' . basename($repo) . '/../' . basename($repo) . '/src/Target.php';
            $snapshotRoot = $gitArchiveSnapshot->create($repo, 'HEAD', [$dottedPath]);

            self::assertFileExists($snapshotRoot . '/src/Target.php');
            self::assertFileDoesNotExist($snapshotRoot . '/src/Unrelated.php');
        } finally {
            if ($snapshotRoot !== null) {
                $gitArchiveSnapshot->remove($snapshotRoot);
            }

            $this->removeDir($repo);
        }
    }

    /**
     * Verify create returns empty snapshot when requested paths do not exist in base.
     *
     * @return void
     */
    public function testCreateReturnsEmptySnapshotWhenRequestedPathsDoNotExistInBase(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo               = $this->repoWithBaseFiles();
        $gitArchiveSnapshot = new GitArchiveSnapshot();
        $snapshotRoot       = null;

        try {
            $snapshotRoot = $gitArchiveSnapshot->create($repo, 'HEAD', ['src/NewRisk.php']);

            self::assertDirectoryExists($snapshotRoot);
            self::assertSame([], $this->filesBelow($snapshotRoot));
        } finally {
            if ($snapshotRoot !== null) {
                $gitArchiveSnapshot->remove($snapshotRoot);
            }

            $this->removeDir($repo);
        }
    }

    /**
     * Verify create removes temporary snapshot when base ref fails.
     *
     * @return void
     */
    public function testCreateRemovesTemporarySnapshotWhenBaseRefFails(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo   = $this->repoWithBaseFiles();
        $before = $this->reviewTempDirs();

        try {
            self::expectException(DiffException::class);
            self::expectExceptionMessageMatches('/does-not-exist/');
            try {
                (new GitArchiveSnapshot())->create($repo, 'does-not-exist', ['src/Target.php']);
            } finally {
                $created = array_values(array_diff($this->reviewTempDirs(), $before));

                foreach ($created as $path) {
                    $this->removeDir($path);
                }

                self::assertSame([], $created);
            }
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Provide unsafe ref cases for parameterized tests.
     *
     * @return array<string, array{string}> - data-provider rows keyed by scenario name; each row holds one unsafe ref string git must reject before
     *                       archiving
     */
    public static function unsafeRefProvider(): array
    {
        return [
            'no-renames option'  => ['--no-renames'],
            'upload-pack option' => ['--upload-pack=anything'],
            'leading hyphen ref' => ['-x'],
            'whitespace ref'     => ['feature branch'],
        ];
    }

    /**
     * Verify create rejects unsafe refs before archiving.
     *
     * @param string $ref - Unsafe git ref input.
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeRefProvider')]
    public function testCreateRejectsUnsafeRefsBeforeArchiving(string $ref): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo = $this->repoWithBaseFiles();

        try {
            self::expectException(DiffException::class);
            self::expectExceptionMessage('safe git ref name');

            (new GitArchiveSnapshot())->create($repo, $ref);
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Create a Git repository fixture with committed base files.
     *
     * @return string - absolute path to the committed repo root; the caller snapshots from it and owns teardown
     */
    private function repoWithBaseFiles(): string
    {
        $repo = $this->tempDir('gruff-snapshot-repo-');
        self::assertTrue(mkdir($repo . '/src', 0777, true));
        self::assertTrue(mkdir($repo . '/big', 0777, true));

        $this->runGit($repo, 'init');
        $this->runGit($repo, 'config', 'user.email', 'test@example.com');
        $this->runGit($repo, 'config', 'user.name', 'Gruff Test');

        file_put_contents($repo . '/src/Target.php', "<?php\nfinal class Target {}\n");
        file_put_contents($repo . '/src/Unrelated.php', "<?php\nfinal class Unrelated {}\n");
        file_put_contents($repo . '/big/Unrelated.txt', "not php\n");

        $this->runGit($repo, ...['add', 'src/Target.php', 'src/Unrelated.php', 'big/Unrelated.txt']);
        $this->runGit($repo, 'commit', '-m', 'base');

        return $repo;
    }

    /**
     * List files below a temporary test directory.
     *
     * @param string $path - directory root to walk; paths are returned relative to it, so callers
     *                     compare against expected layout without leaking the random temp prefix.
     *
     * @return list<string> - file paths found under the root, relative to it and sorted ascending; empty when the dir holds no files
     */
    private function filesBelow(string $path): array
    {
        $files                     = [];
        $recursiveIteratorIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($recursiveIteratorIterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $files[] = substr($file->getPathname(), strlen(rtrim($path, '/')) + 1);
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * List temporary branch-review directories created by a test.
     *
     * @return list<string> - absolute paths to matching gruff-review-* temp dirs, sorted ascending; empty when none exist
     */
    private function reviewTempDirs(): array
    {
        $paths = glob(sys_get_temp_dir() . '/gruff-review-*', GLOB_ONLYDIR);
        self::assertIsArray($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Skip the current test when Git is unavailable.
     *
     * @return void
     */
    private function skipWhenGitIsUnavailable(): void
    {
        $process = new Process(['git', '--version']);
        $process->run();

        if (!$process->isSuccessful()) {
            self::markTestSkipped('git is not available.');
        }
    }

    /**
     * Run a Git command in a fixture repository.
     *
     * @param string $cwd - Working directory.
     * @param string $args - Command arguments.
     *
     * @return void
     */
    private function runGit(string $cwd, string ...$args): void
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @param string $prefix - Temporary directory prefix.
     *
     * @return string - absolute path to the freshly created directory; the caller owns it and must tear it down via removeDir()
     */
    private function tempDir(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path - Filesystem path.
     *
     * @return void
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            // Nothing to remove (path absent or never created); stay a no-op so finally-block cleanup is idempotent.
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $directoryEntry) {
            if ($directoryEntry === '.' || $directoryEntry === '..') {
                continue;
            }

            $child = $path . '/' . $directoryEntry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
