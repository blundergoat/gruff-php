<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Parser;

use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers PHP file parsing into analysis units and reporting of syntax errors as parse diagnostics.
 */
final class PhpFileParserTest extends TestCase
{
    /**
     * Verify parses valid PHP file into analysis unit.
     *
     * @return void
     */
    public function testParsesValidPhpFileIntoAnalysisUnit(): void
    {
        $path = $this->fixturePath('mixed/alpha.php');
        $unit = (new PhpFileParser())->parse(new SourceFile($path, 'alpha.php'));

        self::assertFalse($unit->hasParseErrors());
        self::assertNotSame([], $unit->statements);
        self::assertNotSame([], $unit->tokens);
        self::assertGreaterThan(0, $unit->lineCount());
    }

    /**
     * Verify reports syntax error diagnostic.
     *
     * @return void
     */
    public function testReportsSyntaxErrorDiagnostic(): void
    {
        $path = $this->fixturePath('syntax-error/broken.php');
        $unit = (new PhpFileParser())->parse(new SourceFile($path, 'broken.php'));

        self::assertTrue($unit->hasParseErrors());
        self::assertSame([], $unit->statements);
        self::assertCount(1, $unit->diagnostics);
        self::assertGreaterThanOrEqual(1, $unit->diagnostics[0]->line);
        self::assertNotSame('', $unit->diagnostics[0]->message);
    }

    /**
     * Resolve a parser fixture path.
     *
     * @param string $path - Filesystem path.
     *
     * @return string - absolute, realpath-resolved path to the named fixture; the assertion guarantees the file exists
     */
    private function fixturePath(string $path): string
    {
        $fixture = realpath(__DIR__ . '/../Fixtures/Source/' . $path);

        self::assertIsString($fixture);

        return $fixture;
    }
}
