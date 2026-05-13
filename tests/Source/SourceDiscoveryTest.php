<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Source;

use GruffPhp\Source\SourceDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * Covers SourceDiscoveryTest behavior.
 */
final class SourceDiscoveryTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /**
     * Remove temporary discovery projects created by tests.
     *
     * @return void No return value.
     */
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $tempDir) {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify discovers PHP files deterministically and ignores default directories.
     *
     * @return void No return value.
     */
    public function testDiscoversPhpFilesDeterministicallyAndIgnoresDefaultDirectories(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['.']);

        self::assertSame([
            'alpha.php',
            'nested/beta.php',
        ], array_map(static fn ($file): string => $file->displayPath, $result->files));

        self::assertContains('vendor', $result->ignoredPaths);
        self::assertContains('cache', $result->ignoredPaths);
        self::assertContains('build', $result->ignoredPaths);
        self::assertContains('generated', $result->ignoredPaths);
        self::assertSame([], $result->missingPaths);
    }

    /**
     * Verify can include ignored directories explicitly.
     *
     * @return void No return value.
     */
    public function testCanIncludeIgnoredDirectoriesExplicitly(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['.'], includeIgnored: true);

        self::assertSame([
            'alpha.php',
            'build/ignored.php',
            'cache/ignored.php',
            'generated/ignored.php',
            'nested/beta.php',
            'package-lock.json',
            'vendor/ignored.php',
        ], array_map(static fn ($file): string => $file->displayPath, $result->files));
    }

    /**
     * Verify default ignores well known lockfile names.
     *
     * @return void No return value.
     */
    public function testDefaultIgnoresWellKnownLockfileNames(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['.']);

        $paths = array_map(static fn ($file): string => $file->displayPath, $result->files);
        self::assertNotContains('package-lock.json', $paths, 'package-lock.json must be ignored by default.');
        self::assertNotContains('composer.lock', $paths, 'composer.lock must be ignored by default.');
    }

    /**
     * Verify explicit lockfile path is still ignored without include flag.
     *
     * @return void No return value.
     */
    public function testExplicitLockfilePathIsStillIgnoredWithoutIncludeFlag(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['package-lock.json']);

        self::assertSame([], $result->files);
        self::assertContains('package-lock.json', $result->ignoredPaths);
    }

    /**
     * Verify configured ignores use project relative glob patterns.
     *
     * @return void No return value.
     */
    public function testConfiguredIgnoresUseProjectRelativeGlobPatterns(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(
            ['.'],
            includeIgnored:           true,
            configuredIgnorePatterns: ['nested/**', 'build'],
        );

        self::assertSame([
            'alpha.php',
            'cache/ignored.php',
            'generated/ignored.php',
            'package-lock.json',
            'vendor/ignored.php',
        ], array_map(static fn ($file): string => $file->displayPath, $result->files));
        self::assertContains('nested/beta.php', $result->ignoredPaths);
        self::assertContains('build', $result->ignoredPaths);
    }

    /**
     * Verify reports missing paths.
     *
     * @return void No return value.
     */
    public function testReportsMissingPaths(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['missing.php']);

        self::assertSame(['missing.php'], $result->missingPaths);
        self::assertTrue($result->hasInputErrors());
    }

    /**
     * Verify source discovery classifies PHP, text config, env, and unsupported files.
     *
     * @return void No return value.
     */
    public function testClassifiesSupportedSourceTypesAndSkipsUnsupportedFiles(): void
    {
        $root = $this->tempDir();
        file_put_contents($root . '/index.php', "<?php\n");
        file_put_contents($root . '/settings.yaml', "rules: {}\n");
        file_put_contents($root . '/.env.local', "APP_ENV=test\n");
        file_put_contents($root . '/notes.txt', "plain text\n");

        $result = (new SourceDiscovery($root))->discover(['']);
        $files = array_map(
            static fn ($file): array => [$file->displayPath, $file->type, $file->isPhp()],
            $result->files,
        );

        self::assertSame([
            ['.env.local', 'text', false],
            ['index.php', 'php', true],
            ['settings.yaml', 'text', false],
        ], $files);
        self::assertSame([], $result->missingPaths);
        self::assertSame([], $result->ignoredPaths);
        self::assertFalse($result->hasInputErrors());
    }

    /**
     * Verify duplicate absolute and relative requests collapse to canonical files.
     *
     * @return void No return value.
     */
    public function testCanonicalisesDuplicateAbsoluteAndRelativeRequests(): void
    {
        $root = $this->tempDir();
        file_put_contents($root . '/alpha.php', "<?php\n");

        $result = (new SourceDiscovery($root))->discover(['alpha.php', $root . '/alpha.php', './alpha.php']);

        self::assertSame(['alpha.php'], array_map(static fn ($file): string => $file->displayPath, $result->files));
    }

    /**
     * Verify default ignores nested root paths and configured question-mark globs.
     *
     * @return void No return value.
     */
    public function testDefaultAndConfiguredIgnorePatternsAreAppliedToNestedPaths(): void
    {
        $root = $this->tempDir();
        self::assertTrue(mkdir($root . '/src', 0777, true));
        self::assertTrue(mkdir($root . '/.goat-flow/logs', 0777, true));
        file_put_contents($root . '/src/A.php', "<?php\n");
        file_put_contents($root . '/src/B.php', "<?php\n");
        file_put_contents($root . '/.goat-flow/logs/ignored.php', "<?php\n");

        $result = (new SourceDiscovery($root))->discover(['.'], configuredIgnorePatterns: ['src/?.php']);

        self::assertSame([], $result->files);
        self::assertContains('.goat-flow/logs', $result->ignoredPaths);
        self::assertContains('src/A.php', $result->ignoredPaths);
        self::assertContains('src/B.php', $result->ignoredPaths);
    }

    /**
     * Resolve a source-discovery fixture root.
     *
     * @param string $name Fixture name.
     * @return string Fixture value.
     */
    private function fixtureRoot(string $name): string
    {
        $root = realpath(__DIR__ . '/../Fixtures/Source/' . $name);

        self::assertIsString($root);

        return $root;
    }

    /**
     * Create a temporary source-discovery project.
     *
     * @return string Fixture value.
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-source-discovery-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));
        $this->tempDirs[] = $path;

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Directory path.
     * @return void No return value.
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            is_dir($child) && !is_link($child) ? $this->removeDir($child) : unlink($child);
        }

        rmdir($path);
    }
}
