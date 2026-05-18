<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use GruffPhp\Diff\DiffException;
use GruffPhp\Review\GitArchiveSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers GitArchiveSnapshotTest behavior.
 */
final class GitArchiveSnapshotTest extends TestCase
{
    /**
     * Verify create archives only requested base paths.
     *
     * @return void No return value.
     */
    public function testCreateArchivesOnlyRequestedBasePaths(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo         = $this->repoWithBaseFiles();
        $snapshot     = new GitArchiveSnapshot();
        $snapshotRoot = null;

        try {
            $snapshotRoot = $snapshot->create($repo, 'HEAD', ['src/Target.php']);

            self::assertFileExists($snapshotRoot . '/src/Target.php');
            self::assertFileDoesNotExist($snapshotRoot . '/src/Unrelated.php');
            self::assertDirectoryDoesNotExist($snapshotRoot . '/big');
        } finally {
            if ($snapshotRoot !== null) {
                $snapshot->remove($snapshotRoot);
            }

            $this->removeDir($repo);
        }
    }

    /**
     * Verify create returns empty snapshot when requested paths do not exist in base.
     *
     * @return void No return value.
     */
    public function testCreateReturnsEmptySnapshotWhenRequestedPathsDoNotExistInBase(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo         = $this->repoWithBaseFiles();
        $snapshot     = new GitArchiveSnapshot();
        $snapshotRoot = null;

        try {
            $snapshotRoot = $snapshot->create($repo, 'HEAD', ['src/NewRisk.php']);

            self::assertDirectoryExists($snapshotRoot);
            self::assertSame([], $this->filesBelow($snapshotRoot));
        } finally {
            if ($snapshotRoot !== null) {
                $snapshot->remove($snapshotRoot);
            }

            $this->removeDir($repo);
        }
    }

    /**
     * Verify create removes temporary snapshot when base ref fails.
     *
     * @return void No return value.
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
     * @return array<string, array{string}>
     */
    public static function unsafeRefProvider(): array
    {
        return [
            'no-renames option' => ['--no-renames'],
            'upload-pack option' => ['--upload-pack=anything'],
            'leading hyphen ref' => ['-x'],
            'whitespace ref' => ['feature branch'],
        ];
    }

    /**
     * Verify create rejects unsafe refs before archiving.
     *
     * @param string $ref Unsafe git ref input.
     * @return void No return value.
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
     * @return string Fixture value.
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
     * @return list<string>
     */
    private function filesBelow(string $path): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $files[] = substr($file->getPathname(), strlen(rtrim($path, '/')) + 1);
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return list<string>
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
     * @return void No return value.
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
     * @param string $cwd Working directory.
     * @param string $args Command arguments.
     * @return void No return value.
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
     * @param string $prefix Temporary directory prefix.
     * @return string Fixture value.
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
     * @param string $path Filesystem path.
     * @return void No return value.
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
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
