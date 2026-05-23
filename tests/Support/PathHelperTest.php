<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Support;

use GruffPhp\Support\PathHelper;
use PHPUnit\Framework\TestCase;

/**
 * Covers PathHelper behavior.
 */
final class PathHelperTest extends TestCase
{
    /**
     * Verify absolute path detection supports Unix, UNC, and drive-letter inputs.
     *
     * @return void
     */
    public function testIsAbsoluteRecognizesSupportedAbsoluteForms(): void
    {
        self::assertTrue(PathHelper::isAbsolute('/repo/src'));
        self::assertTrue(PathHelper::isAbsolute('C:\\repo\\src'));
        self::assertTrue(PathHelper::isAbsolute('C:/repo/src'));
        self::assertTrue(PathHelper::isAbsolute('\\\\server\\share\\repo'));
        self::assertFalse(PathHelper::isAbsolute('src/Foo.php'));
        self::assertFalse(PathHelper::isAbsolute('C:relative'));
    }

    /**
     * Verify relative paths resolve against roots without rewriting absolute paths.
     *
     * @return void
     */
    public function testResolveAgainstPreservesAbsolutePaths(): void
    {
        self::assertSame('/repo/src', PathHelper::resolveAgainst('/repo', 'src'));
        self::assertSame('C:/repo/src', PathHelper::resolveAgainst('/repo', 'C:\\repo\\src'));
    }

    /**
     * Verify canonical fallback collapses dot segments in paths that do not exist.
     *
     * @return void
     */
    public function testCanonicalCollapsesDotSegmentsWhenPathDoesNotExist(): void
    {
        self::assertSame('/repo/src', PathHelper::canonical('/repo/../repo/src'));
        self::assertSame('C:/repo/src', PathHelper::canonical('C:\\repo\\..\\repo\\src'));
        self::assertSame('src/File.php', PathHelper::canonical('./src/../src/File.php'));
    }

    /**
     * Verify display path normalization keeps absolute paths absolute.
     *
     * @return void
     */
    public function testNormalizeRelativeDoesNotStripAbsoluteRoots(): void
    {
        self::assertSame('src/Foo.php', PathHelper::normalizeRelative('./src/Foo.php'));
        self::assertSame('/outside/Foo.php', PathHelper::normalizeRelative('/outside/Foo.php'));
    }
}
