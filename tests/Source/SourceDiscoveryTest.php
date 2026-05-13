<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Source;

use GruffPhp\Source\SourceDiscovery;
use PHPUnit\Framework\TestCase;

final class SourceDiscoveryTest extends TestCase
{
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
}
