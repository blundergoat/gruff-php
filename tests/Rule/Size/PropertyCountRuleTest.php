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
 * Exercises property-count findings using parsed fixtures and configured warning/error thresholds.
 * Checks declared, promoted and trait property counts with finding symbols and severities.
 * Covers readonly data-carrier classification and a behaviour-method case that retains warning severity.
 */
final class PropertyCountRuleTest extends TestCase
{
    /** Rule instance under test. */
    private PropertyCountRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Create the rule and parser used by each fixture-based test.
     *
     * @return void - Initializes fresh rule and parser instances before each test.
     */
    protected function setUp(): void
    {
        $this->rule   = new PropertyCountRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for few properties.
     *
     * @return void - Asserts an empty finding list for the two-property fixture.
     */
    public function testNoFindingsForFewProperties(): void
    {
        $findings = $this->analyse('few-properties.php', ['warning' => 15, 'error' => 25]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for too many declared properties.
     *
     * @return void - Asserts one warning for ManyPropertiesFixture with sixteen properties and the property-count rule ID.
     */
    public function testWarningForTooManyDeclaredProperties(): void
    {
        // A warning tier of 10 puts 16 properties above one and a half times the limit, retaining warning severity.
        $findings = $this->analyse('many-properties.php', ['warning' => 10, 'error' => 25]);

        self::assertCount(1, $findings);
        self::assertSame(PropertyCountRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('ManyPropertiesFixture', $findings[0]->symbol);
        self::assertSame(16, $findings[0]->metadata['properties']);
    }

    /**
     * Verify promoted properties counted.
     *
     * @return void - Asserts one finding for ManyParamsFixture with property-count metadata of six.
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
     * @return void - Asserts one finding for LongTraitFixture with property-count metadata of sixteen.
     */
    public function testTraitPropertiesFlagged(): void
    {
        $findings = $this->analyse('trait-fixture.php', ['warning' => 15, 'error' => 25]);

        self::assertCount(1, $findings);
        self::assertSame('LongTraitFixture', $findings[0]->symbol);
        self::assertSame(16, $findings[0]->metadata['properties']);
    }

    /**
     * Verify readonly data-carrier metadata and advisory severity for sixteen properties.
     *
     * @return void - Asserts one finding with carrier classification, raw warning threshold type and advisory output.
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
     * Verify a readonly class with an untyped method keeps the configured property-count severity.
     *
     * @return void - Asserts one finding for ReadonlyCarrierWithBehaviourFixture with property-count kind and warning severity.
     */
    public function testReadonlyClassWithBehaviourMethodKeepsConfiguredSeverity(): void
    {
        // The untyped method fails the data-carrier heuristic, so its property-count finding keeps warning severity.
        $findings = $this->analyse('readonly-carrier-with-behaviour.php', ['warning' => 10, 'error' => 25]);

        self::assertCount(1, $findings);
        self::assertSame('ReadonlyCarrierWithBehaviourFixture', $findings[0]->symbol);
        self::assertSame('property-count', $findings[0]->metadata['findingKind']);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    /**
     * Analyse one size fixture with explicit property-count thresholds.
     *
     * @param string             $fixture - Fixture filename under tests/Fixtures/Size to scan.
     * @param array<string, int> $thresholds - Warning/error property-count limits for this case.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Property-count findings; empty when no declaration exceeds the reporting threshold.
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
     * @param string $filename - Name resolved under tests/Fixtures/Size.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - parsed fixture with its display path set repo-relative for finding reports
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
