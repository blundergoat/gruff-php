<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Rules\Size\SubstantiveLineCounter;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use WeakReference;

/**
 * Compares substantive-line counts with repeated-replacement masking across PHP fixtures and class-like spans.
 * Exercises CRLF comments, range bounds, repeated calls, empty input, raw text, and a syntax error.
 * Checks collection of a counted unit and recounting after eviction and source release.
 */
final class SubstantiveLineCounterTest extends TestCase
{
    /**
     * Compare every PHP fixture and every parsed class-like span with the pre-optimization algorithm.
     *
     * @return void - Asserts matching counts for each fixture and at least one class-like span overall.
     */
    public function testMatchesReferenceAcrossEveryPhpFixtureAndClassLikeSpan(): void
    {
        $parser         = new PhpFileParser();
        $classSpanCount = 0;

        foreach ($this->phpFixturePaths() as $absolutePath) {
            $displayPath = str_replace($this->projectRoot() . '/', '', $absolutePath);
            $unit        = $parser->parse(new SourceFile($absolutePath, $displayPath));

            self::assertSame(
                $this->referenceCountAll($unit),
                SubstantiveLineCounter::countAll($unit),
                $displayPath . ' whole-file count',
            );

            $classLikes = (new NodeFinder())->findInstanceOf($unit->statements, ClassLike::class);
            foreach ($classLikes as $classLike) {
                ++$classSpanCount;
                $startLine = $classLike->getStartLine();
                $endLine   = $classLike->getEndLine();

                self::assertSame(
                    $this->referenceCountRange($unit, $startLine, $endLine),
                    SubstantiveLineCounter::countRange($unit, $startLine, $endLine),
                    $displayPath . ':' . $startLine . '-' . $endLine,
                );
            }
        }

        self::assertGreaterThan(0, $classSpanCount, 'Fixture sweep must exercise at least one class-like span.');
    }

    /**
     * Verify CRLF comments, trailing comments, invalid ranges, and repeated calls retain exact behaviour.
     *
     * @return void - Asserts a count of five and reference-matching range counts across repeated calls.
     */
    public function testPreservesMaskingBoundsAndRepeatedCalls(): void
    {
        $source = "<?php\r\n"
            . "/**\r\n"
            . " * documentation only\r\n"
            . " */\r\n"
            . "final class Demo\r\n"
            . "{\r\n"
            . "    public int \$value = 1; // trailing comment\r\n"
            . "}\r\n";

        $unit                   = $this->parseInline($source);
        $expectedWholeFileCount = 5;

        self::assertSame(
            [$expectedWholeFileCount, $expectedWholeFileCount],
            [SubstantiveLineCounter::countAll($unit), SubstantiveLineCounter::countAll($unit)],
        );

        foreach ([[-2, 0], [0, 0], [0, 2], [1, 1], [1, 999], [4, 3], [999, 1000]] as [$startLine, $endLine]) {
            $expected = $this->referenceCountRange($unit, $startLine, $endLine);
            $context  = sprintf('range %d-%d', $startLine, $endLine);

            self::assertSame($expected, SubstantiveLineCounter::countRange($unit, $startLine, $endLine), $context . ' first count');
            self::assertSame($expected, SubstantiveLineCounter::countRange($unit, $startLine, $endLine), $context . ' cached count');
        }
    }

    /**
     * Verify empty, token-free, and syntax-error units preserve their established fallbacks.
     *
     * @return void - Asserts zero for empty input, two for raw text, and the reference count after a parse error.
     */
    public function testHandlesEmptyRawAndSyntaxErrorUnits(): void
    {
        $empty = new AnalysisUnit(new SourceFile(__FILE__, 'empty.php'), '', [], [], []);
        self::assertSame(0, SubstantiveLineCounter::countAll($empty));
        self::assertSame(0, SubstantiveLineCounter::countRange($empty, 1, 1));

        $raw = new AnalysisUnit(
            new SourceFile(__FILE__, 'raw.txt', SourceFile::TYPE_TEXT),
            "alpha\n\n// raw comment\n",
            [],
            [],
            [],
        );
        $expectedRawCount = 2;
        self::assertSame($expectedRawCount, SubstantiveLineCounter::countAll($raw));

        $syntaxError = $this->parseInline(
            "<?php\n// comment only\nfinal class Broken\n{\n    public function broken(\n}\n",
        );
        self::assertTrue($syntaxError->hasParseErrors());
        self::assertSame($this->referenceCountAll($syntaxError), SubstantiveLineCounter::countAll($syntaxError));
    }

    /**
     * Verify a cached prefix cannot keep an otherwise unreachable analysis unit alive.
     *
     * @return void - Asserts that the counted unit's weak reference clears after unset and cycle collection.
     */
    public function testDoesNotRetainCachedAnalysisUnit(): void
    {
        $unit                   = $this->parseInline("<?php\nfinal class Cached {}\n");
        $expectedWholeFileCount = 2;
        self::assertSame($expectedWholeFileCount, SubstantiveLineCounter::countAll($unit));

        $reference = WeakReference::create($unit);
        unset($unit);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    /**
     * Verify counting a retained unit after eviction and source release yields zero.
     *
     * @return void - Asserts the count changes from two to zero after eviction and source release.
     */
    public function testEvictsCachedPrefixBeforeUnitRelease(): void
    {
        $unit                   = $this->parseInline("<?php\nfinal class Cached {}\n");
        $expectedWholeFileCount = 2;
        self::assertSame($expectedWholeFileCount, SubstantiveLineCounter::countAll($unit));

        SubstantiveLineCounter::evictUnit($unit);
        $unit->release();

        self::assertSame(0, SubstantiveLineCounter::countAll($unit));
    }

    /**
     * List every PHP fixture in stable path order.
     *
     * @return list<string> - Absolute PHP fixture paths.
     */
    private function phpFixturePaths(): array
    {
        $paths  = [];
        $finder = (new Finder())
            ->files()
            ->in($this->projectRoot() . '/tests/Fixtures')
            ->name('*.php')
            ->sortByName();

        foreach ($finder as $fixture) {
            $paths[] = $fixture->getPathname();
        }

        return $paths;
    }

    /**
     * Return the repository root.
     *
     * @return string - Absolute repository root.
     */
    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Parse inline PHP through the real file parser.
     *
     * @param string $source - PHP source to parse.
     *
     * @return AnalysisUnit - Source and diagnostics for inline.php; its temporary file is removed before return.
     */
    private function parseInline(string $source): AnalysisUnit
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-substantive-');
        self::assertNotFalse($path);

        try {
            self::assertNotFalse(file_put_contents($path, $source));

            return (new PhpFileParser())->parse(new SourceFile($path, 'inline.php'));
        } finally {
            self::assertTrue(unlink($path));
        }
    }

    /**
     * Count a whole unit with the original repeated-replacement implementation.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose source and tokens are inspected.
     *
     * @return int - Reference substantive-line count.
     */
    private function referenceCountAll(AnalysisUnit $analysisUnit): int
    {
        return $this->referenceCountLines($this->referenceMaskedLines($analysisUnit));
    }

    /**
     * Count a reference range with a clamped offset and the original requested span length.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose source and tokens are inspected.
     * @param int          $startLine    - Inclusive start; offsets before the source clamp to its first line.
     * @param int          $endLine      - Inclusive end used with the requested start to calculate the slice length.
     *
     * @return int - Reference count; zero for a nonpositive slice length or an offset past the source.
     */
    private function referenceCountRange(AnalysisUnit $analysisUnit, int $startLine, int $endLine): int
    {
        $lines = $this->referenceMaskedLines($analysisUnit);
        $slice = array_slice($lines, max(0, $startLine - 1), max(0, $endLine - $startLine + 1));

        return $this->referenceCountLines($slice);
    }

    /**
     * Reproduce the masking with plain repeated replacement: comment tokens, then parsed attribute groups.
     *
     * @param AnalysisUnit $analysisUnit - Unit supplying source, comment tokens, and parsed attribute offsets.
     *
     * @return list<string> - Source lines with comment and attribute bytes replaced by spaces except for newlines.
     */
    private function referenceMaskedLines(AnalysisUnit $analysisUnit): array
    {
        $masked = $analysisUnit->source;

        foreach ($analysisUnit->tokens as $token) {
            if (!$token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }

            // Blank every character except newlines, so the comment keeps its line count.
            $blanked = preg_replace('/[^\n]/', ' ', $token->text) ?? $token->text;
            $masked  = substr_replace($masked, $blanked, $token->pos, strlen($token->text));
        }

        // Attribute syntax is ignored like comment text, so attribute-only lines do not count.
        foreach ((new NodeFinder())->findInstanceOf($analysisUnit->statements, AttributeGroup::class) as $attributeGroup) {
            $start   = $attributeGroup->getStartFilePos();
            $length  = $attributeGroup->getEndFilePos() - $start + 1;
            $blanked = preg_replace('/[^\n]/', ' ', substr($masked, $start, $length)) ?? '';
            $masked  = substr_replace($masked, $blanked, $start, $length);
        }

        return explode("\n", $masked);
    }

    /**
     * Count non-blank lines in a reference mask.
     *
     * @param list<string> $lines - Masked source lines whose non-blank entries are counted.
     *
     * @return int - Number of non-blank lines.
     */
    private function referenceCountLines(array $lines): int
    {
        $count = 0;

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                ++$count;
            }
        }

        return $count;
    }
}
