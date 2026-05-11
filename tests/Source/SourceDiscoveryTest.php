<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Source;

use GruffPhp\Source\SourceDiscovery;
use PHPUnit\Framework\TestCase;

final class SourceDiscoveryTest extends TestCase
{
    public function testDiscoversPhpFilesDeterministicallyAndIgnoresDefaultDirectories(): void
    {
        $root = $this->fixtureRoot('mixed');
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

    public function testCanIncludeIgnoredDirectoriesExplicitly(): void
    {
        $root = $this->fixtureRoot('mixed');
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

    public function testDefaultIgnoresWellKnownLockfileNames(): void
    {
        $root = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['.']);

        $paths = array_map(static fn ($file): string => $file->displayPath, $result->files);
        self::assertNotContains('package-lock.json', $paths, 'package-lock.json must be ignored by default.');
        self::assertNotContains('composer.lock', $paths, 'composer.lock must be ignored by default.');
    }

    public function testExplicitLockfilePathIsStillIgnoredWithoutIncludeFlag(): void
    {
        $root = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['package-lock.json']);

        self::assertSame([], $result->files);
        self::assertContains('package-lock.json', $result->ignoredPaths);
    }

    public function testConfiguredIgnoresUseProjectRelativeGlobPatterns(): void
    {
        $root = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(
            ['.'],
            includeIgnored: true,
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

    public function testReportsMissingPaths(): void
    {
        $root = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['missing.php']);

        self::assertSame(['missing.php'], $result->missingPaths);
        self::assertTrue($result->hasInputErrors());
    }

    private function fixtureRoot(string $name): string
    {
        $root = realpath(__DIR__ . '/../Fixtures/Source/' . $name);

        self::assertIsString($root);

        return $root;
    }
}
