<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\PublicMethodCountRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers PublicMethodCountRuleTest behavior.
 */
final class PublicMethodCountRuleTest extends TestCase
{
    /** Rule instance under test. */
    private PublicMethodCountRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void No return value.
     */
    protected function setUp(): void
    {
        $this->rule   = new PublicMethodCountRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify allowed public method shapes are not flagged.
     *
     * @param string             $fixture    Fixture filename.
     * @param array<string, int> $thresholds Rule thresholds.
     * @return void No return value.
     */
    #[DataProvider('allowedPublicMethodShapeProvider')]
    public function testAllowedPublicMethodShapesAreNotFlagged(string $fixture, array $thresholds): void
    {
        $findings = $this->analyse($fixture, $thresholds);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for too many public methods.
     *
     * @return void No return value.
     */
    public function testWarningForTooManyPublicMethods(): void
    {
        $findings = $this->analyse('many-public-methods.php', ['warning' => 15, 'error' => 25]);

        self::assertCount(1, $findings);
        self::assertSame(PublicMethodCountRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('ManyPublicMethodsFixture', $findings[0]->symbol);
        self::assertSame(16, $findings[0]->metadata['publicMethods']);
    }

    /**
     * @param array<string, int> $thresholds
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            PublicMethodCountRule::ID,
            new RuleSettings(true, $thresholds),
        );
        $context = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $context);
    }

    /**
     * Provide fixture and threshold combinations that should stay below the rule limit.
     *
     * @return iterable<string, array{0: string, 1: array<string, int>}>
     */
    public static function allowedPublicMethodShapeProvider(): iterable
    {
        yield 'few public methods' => ['short-method.php', ['warning' => 15, 'error' => 25]];
        yield 'private and protected not counted' => ['many-public-methods.php', ['warning' => 16, 'error' => 25]];
        yield 'interfaces are ignored' => ['interface-fixture.php', ['warning' => 5, 'error' => 25]];
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     * @return \GruffPhp\Parser\AnalysisUnit Fixture value.
     */
    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
