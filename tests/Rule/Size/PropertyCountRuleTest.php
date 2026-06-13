<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\PropertyCountRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers property-count thresholds for declared, promoted constructor, and trait properties.
 */
final class PropertyCountRuleTest extends TestCase
{
    /** Rule instance under test. */
    private PropertyCountRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule   = new PropertyCountRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for few properties.
     *
     * @return void
     */
    public function testNoFindingsForFewProperties(): void
    {
        $findings = $this->analyse('few-properties.php', ['warning' => 15, 'error' => 25]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for too many declared properties.
     *
     * @return void
     */
    public function testWarningForTooManyDeclaredProperties(): void
    {
        $findings = $this->analyse('many-properties.php', ['warning' => 15, 'error' => 25]);

        self::assertCount(1, $findings);
        self::assertSame(PropertyCountRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('ManyPropertiesFixture', $findings[0]->symbol);
        self::assertSame(16, $findings[0]->metadata['properties']);
    }

    /**
     * Verify promoted properties counted.
     *
     * @return void
     */
    public function testPromotedPropertiesCounted(): void
    {
        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 25]);

        self::assertCount(1, $findings);
        self::assertSame(6, $findings[0]->metadata['properties']);
        self::assertSame('ManyParamsFixture', $findings[0]->symbol);
    }

    /**
     * Verify trait properties flagged.
     *
     * @return void
     */
    public function testTraitPropertiesFlagged(): void
    {
        $findings = $this->analyse('trait-fixture.php', ['warning' => 15, 'error' => 25]);

        self::assertCount(1, $findings);
        self::assertSame('LongTraitFixture', $findings[0]->symbol);
        self::assertSame(16, $findings[0]->metadata['properties']);
    }

    /**
     * Verify readonly payload objects are advisory instead of severe solely for field count.
     *
     * @return void
     */
    public function testReadonlyDataCarrierPropertyCountIsAdvisory(): void
    {
        $findings = $this->analyse('readonly-data-carrier-properties.php', ['warning' => 15, 'error' => 25]);

        self::assertCount(1, $findings);
        self::assertSame('ReadonlySessionPayloadFixture', $findings[0]->symbol);
        self::assertSame(16, $findings[0]->metadata['properties']);
        self::assertSame('readonly-data-carrier', $findings[0]->metadata['findingKind']);
        self::assertSame('warning', $findings[0]->metadata['rawThresholdType']);
        self::assertSame(Severity::Advisory, $findings[0]->severity);
    }

    /**
     * Analyse fixture paths and return findings for assertions.
     *
     * @param string             $fixture - Fixture filename under tests/Fixtures/Size to scan.
     * @param array<string, int> $thresholds - Warning/error property-count limits for this case.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - property-count findings the rule raised for this fixture; empty when the count stays under both
     *                                         thresholds
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit        = $this->parseFixture($fixture);
        $registry    = RuleRegistry::defaults();
        $config      = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            PropertyCountRule::ID,
            new RuleSettings(true, $thresholds),
        );
        $ruleContext = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $ruleContext);
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Fixture filename.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - parsed fixture with its display path set repo-relative for finding reports
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
