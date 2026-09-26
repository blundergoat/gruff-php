<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Parser;

use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceFile;
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
     * Verify either bound degrades PHP to raw text without marking it unanalysed.
     *
     * @return void
     */
    public function testDeepScanBudgetProducesNonfatalRawTextUnit(): void
    {
        $path = $this->fixturePath('mixed/alpha.php');
        $unit = (new PhpFileParser())->parse(
            new SourceFile($path, 'alpha.php'),
            ['enabled' => true, 'maxLines' => 1, 'maxBytes' => 1, 'override' => 'cli'],
        );

        self::assertFalse($unit->hasParseErrors());
        self::assertTrue($unit->isDeepScanBounded());
        self::assertSame([], $unit->statements);
        self::assertSame([], $unit->tokens);
        self::assertNotSame('', $unit->source);
        self::assertCount(1, $unit->diagnostics);
        self::assertSame('bounded-deep-scan', $unit->diagnostics[0]->type);
        self::assertFalse($unit->diagnostics[0]->isFatal);
        self::assertStringContainsString('path=alpha.php;', $unit->diagnostics[0]->message);
        self::assertStringContainsString('maxLines=1; maxBytes=1; override=cli', $unit->diagnostics[0]->message);
    }

    /**
     * Verify the guard is never applied to non-code text, even above both limits.
     *
     * @return void
     */
    public function testDeepScanBudgetDoesNotApplyToTextSources(): void
    {
        $path = $this->fixturePath('mixed/alpha.php');
        $unit = (new PhpFileParser())->parse(
            new SourceFile($path, 'alpha.php', SourceFile::TYPE_TEXT),
            ['enabled' => true, 'maxLines' => 1, 'maxBytes' => 1, 'override' => 'cli'],
        );

        self::assertFalse($unit->isDeepScanBounded());
        self::assertSame([], $unit->diagnostics);
        self::assertNotSame('', $unit->source);
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
