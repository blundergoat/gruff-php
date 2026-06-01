<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\ClassLengthRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers class-length thresholds (warning/error), interface exemption, and trait inclusion.
 */
final class ClassLengthRuleTest extends TestCase
{
    /** Rule instance under test. */
    private ClassLengthRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule   = new ClassLengthRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for small class.
     *
     * @return void
     */
    public function testNoFindingsForSmallClass(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 300, 'error' => 500]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for class above warning threshold.
     *
     * @return void
     */
    public function testWarningForClassAboveWarningThreshold(): void
    {
        $findings = $this->analyse('long-method.php', ['warning' => 10, 'error' => 500]);

        self::assertCount(1, $findings);
        self::assertSame(ClassLengthRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('LongMethodFixture', $findings[0]->symbol);
    }

    /**
     * Verify error for class above error threshold.
     *
     * @return void
     */
    public function testErrorForClassAboveErrorThreshold(): void
    {
        $findings = $this->analyse('long-method.php', ['warning' => 5, 'error' => 10]);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    /**
     * Verify interface is not flagged.
     *
     * @return void
     */
    public function testInterfaceIsNotFlagged(): void
    {
        $findings = $this->analyse('interface-fixture.php', ['warning' => 5, 'error' => 10]);

        self::assertSame([], $findings);
    }

    /**
     * Verify trait is flagged.
     *
     * @return void
     */
    public function testTraitIsFlagged(): void
    {
        $findings = $this->analyse('trait-fixture.php', ['warning' => 5, 'error' => 500]);

        self::assertCount(1, $findings);
        self::assertSame('LongTraitFixture', $findings[0]->symbol);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    /**
     * Analyse fixture paths and return findings for assertions.
     *
     * @param string             $fixture    Fixture filename under tests/Fixtures/Size to scan.
     * @param array<string, int> $thresholds Rule option overrides applied before the fixture runs.
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            ClassLengthRule::ID,
            new RuleSettings(true, $thresholds),
        );
        $ruleContext = new RuleContext(__DIR__ . '/../../..', $config);

        // Only this rule runs, so every finding returned belongs to the case under test.
        return $this->rule->analyse($unit, $ruleContext);
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     * @return \GruffPhp\Parser\AnalysisUnit
     */
    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        // Display path stays repo-relative so findings report the fixture, not the temp absolute path.
        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
