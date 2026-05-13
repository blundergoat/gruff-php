<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers RepeatedStructureMissingDataProviderRule behavior.
 */
final class RepeatedStructureMissingDataProviderRuleTest extends TestCase
{
    /** Rule instance under test. */
    private RepeatedStructureMissingDataProviderRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void No return value.
     */
    protected function setUp(): void
    {
        $this->rule = new RepeatedStructureMissingDataProviderRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify repeated structures are found after non-candidate methods.
     *
     * @return void No return value.
     */
    public function testRepeatedStructuresAreFoundAfterNonCandidates(): void
    {
        $findings = $this->analyse('repeated-structure-mutation-cases.php');
        $symbols = array_map(static fn (Finding $finding): ?string => $finding->symbol, $findings);

        self::assertSame([
            'ContinuePastNonTestMethodTest::testAlpha()',
            'ContinuePastShortMethodTest::testAlpha()',
            'ContinuePastProviderTest::testShapeAlpha()',
            'ContinuePastSmallGroupTest::testRealAlpha()',
            'NonProviderAttributeStillAnalysedTest::testDecoratedAlpha()',
        ], $symbols);
    }

    /**
     * Verify findings carry the grouped method metadata and message.
     *
     * @return void No return value.
     */
    public function testFindingsCarryGroupedMethodMetadata(): void
    {
        $findings = $this->analyse('repeated-structure-mutation-cases.php');

        self::assertCount(5, $findings);
        self::assertSame(3, $findings[0]->metadata['count'] ?? null);
        self::assertSame(['testAlpha', 'testBeta', 'testGamma'], $findings[0]->metadata['methods'] ?? null);
        self::assertSame(3, $findings[3]->metadata['count'] ?? null);
        self::assertSame(['testRealAlpha', 'testRealBeta', 'testRealGamma'], $findings[3]->metadata['methods'] ?? null);
        self::assertStringContainsString(
            'ContinuePastSmallGroupTest has 3 structurally identical test methods',
            $findings[3]->message,
        );
    }

    /**
     * Parse and analyse a repeated-structure fixture.
     *
     * @param string $fixture Fixture filename.
     * @return list<Finding> Findings emitted by the rule.
     */
    private function analyse(string $fixture): array
    {
        $unit = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);
        $context = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $context);
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     * @return AnalysisUnit Fixture value.
     */
    private function parseFixture(string $filename): AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/TestQuality/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/TestQuality/' . $filename));
    }
}
