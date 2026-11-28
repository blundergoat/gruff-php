<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\ClassLengthRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers class-length findings under explicit warning and error thresholds.
 * Verifies finding counts, severity, and declaration symbols where asserted.
 * Includes an interface exemption and a trait that exceeds its warning threshold.
 */
final class ClassLengthRuleTest extends TestCase
{
    /** Rule instance under test. */
    private ClassLengthRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Initializes the class-length rule and fixture parser before each test.
     *
     * @return void - Assigns fresh instances to the rule and parser properties.
     */
    protected function setUp(): void
    {
        $this->rule   = new ClassLengthRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for small class.
     *
     * @return void - Asserts an empty finding list with warning 300 and error 500.
     */
    public function testNoFindingsForSmallClass(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 300, 'error' => 500]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for class above warning threshold.
     *
     * @return void - Asserts one class-length warning naming LongMethodFixture.
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
     * @return void - Asserts a single finding with error severity.
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
     * @return void - Asserts the interface fixture produces no class-length findings.
     */
    public function testInterfaceIsNotFlagged(): void
    {
        $findings = $this->analyse('interface-fixture.php', ['warning' => 5, 'error' => 10]);

        self::assertSame([], $findings);
    }

    /**
     * Verify trait is flagged.
     *
     * @return void - Asserts one warning naming LongTraitFixture.
     */
    public function testTraitIsFlagged(): void
    {
        $findings = $this->analyse('trait-fixture.php', ['warning' => 5, 'error' => 500]);

        self::assertCount(1, $findings);
        self::assertSame('LongTraitFixture', $findings[0]->symbol);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    /**
     * Analyses one size fixture with the supplied class-length thresholds.
     *
     * @param string             $fixture - Fixture filename under tests/Fixtures/Size to scan.
     * @param array<string, int> $thresholds - Warning/error thresholds supplied to this rule.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings from this rule only; empty if no measured declaration exceeds its threshold.
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit        = $this->parseFixture($fixture);
        $registry    = RuleRegistry::defaults();
        $config      = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            ClassLengthRule::ID,
            new RuleSettings(true, $thresholds),
        );
        $ruleContext = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $ruleContext);
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Name of a PHP fixture under tests/Fixtures/Size.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - parsed fixture ready to feed to the rule, with its display path kept repo-relative
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
