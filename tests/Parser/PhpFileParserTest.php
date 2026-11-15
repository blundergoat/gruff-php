<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Parser;

use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers conversion of PHP source fixtures into analysis units.
 * Checks syntax-error diagnostics and nonfatal limits on structural parsing.
 * Checks that text-source fixtures remain unbounded and have no diagnostics.
 */
final class PhpFileParserTest extends TestCase
{
    /**
     * Checks that the valid fixture produces a populated analysis unit without parse errors.
     *
     * @return void - Asserts nonempty statements and tokens, a positive line count, and no parse errors.
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
     * Malformed PHP is represented by a parser diagnostic instead of parsed statements.
     *
     * @return void - Asserts one diagnostic with a nonempty message and positive line number, and no statements.
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
     * Checks the raw-text unit and diagnostic returned with both parsing limits set to one.
     *
     * @return void - Asserts retained source, no statements or tokens, and one nonfatal budget diagnostic.
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
     * Checks that a text-source fixture is unbounded with both parsing limits set to one.
     *
     * @return void - Asserts retained source and no budget or parse diagnostics.
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
     * @param string $path - Fixture path relative to tests/Fixtures/Source.
     *
     * @return string - Canonical fixture path after asserting that realpath() returned a string.
     */
    private function fixturePath(string $path): string
    {
        $fixture = realpath(__DIR__ . '/../Fixtures/Source/' . $path);

        self::assertIsString($fixture);

        return $fixture;
    }
}
