<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\PropertyCountRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class PropertyCountRuleTest extends TestCase
{
    private PropertyCountRule $rule;
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->rule   = new PropertyCountRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for few properties.
     *
     * @return void No return value.
     */
    public function testNoFindingsForFewProperties(): void
    {
        $findings = $this->analyse('few-properties.php', ['warning' => 15, 'error' => 25]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for too many declared properties.
     *
     * @return void No return value.
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
     * @return void No return value.
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
     * @return void No return value.
     */
    public function testTraitPropertiesFlagged(): void
    {
        $findings = $this->analyse('trait-fixture.php', ['warning' => 15, 'error' => 25]);

        self::assertCount(1, $findings);
        self::assertSame('LongTraitFixture', $findings[0]->symbol);
        self::assertSame(16, $findings[0]->metadata['properties']);
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
            PropertyCountRule::ID,
            new RuleSettings(true, $thresholds),
        );
        $context = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $context);
    }

    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
