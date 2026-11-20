<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Support;

use GruffPhp\Support\PathHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests absolute-path detection for Unix, UNC, and drive-letter inputs.
 * Checks root resolution and dot-segment reduction for representative paths.
 * Checks that relative-path normalization preserves a Unix absolute file path.
 */
final class PathHelperTest extends TestCase
{
    /**
     * Verify absolute path detection supports Unix, UNC, and drive-letter inputs.
     *
     * @return void - Asserts supported absolute forms and rejects a relative path and a drive-relative path.
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
     * Verify root resolution for a relative path and separator normalization for a Windows absolute path.
     *
     * @return void - Asserts a rooted relative result and a separator-normalized absolute result.
     */
    public function testResolveAgainstPreservesAbsolutePaths(): void
    {
        self::assertSame('/repo/src', PathHelper::resolveAgainst('/repo', 'src'));
        self::assertSame('C:/repo/src', PathHelper::resolveAgainst('/repo', 'C:\\repo\\src'));
    }

    /**
     * Verify dot-segment reduction for Unix, drive-letter, and relative path inputs.
     *
     * @return void - Asserts the expected path after dot-segment reduction for each input.
     */
    public function testCanonicalCollapsesDotSegmentsWhenPathDoesNotExist(): void
    {
        self::assertSame('/repo/src', PathHelper::canonical('/repo/../repo/src'));
        self::assertSame('C:/repo/src', PathHelper::canonical('C:\\repo\\..\\repo\\src'));
        self::assertSame('src/File.php', PathHelper::canonical('./src/../src/File.php'));
    }

    /**
     * Verify leading './' removal while preserving the tested Unix absolute file path.
     *
     * @return void - Asserts the normalized relative path and unchanged absolute file path.
     */
    public function testNormalizeRelativeDoesNotStripAbsoluteRoots(): void
    {
        self::assertSame('src/Foo.php', PathHelper::normalizeRelative('./src/Foo.php'));
        self::assertSame('/outside/Foo.php', PathHelper::normalizeRelative('/outside/Foo.php'));
    }
}
