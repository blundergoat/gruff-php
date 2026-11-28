<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\ClassLengthRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rules\Waste\UnreachableCodeRule;
use GruffPhp\Tests\Rule\Docs\DocsRuleTestCase;

/**
 * Pins FAMILY-CONTRACT section 12, under "Code lines in every line count", with rule-level fixtures.
 * Covers length thresholds with attributes, comments and blank lines in the source.
 * Contrasts a comment after return with an unreachable statement after return.
 */
final class CodeLineCountingTest extends DocsRuleTestCase
{
    /** Class-length warning bar used by the attribute-line control. */
    private const CLASS_WARNING_LINES = 12;

    /** Default minTestLines boundary for the fixture with one SUT call and an assertion. */
    private const MIN_SUT_TEST_LINES = 12;

    /**
     * Verify attribute lines do not count toward a class's length, while code lines still do.
     *
     * @return void - Asserts no finding for two methods and one finding reporting 15 lines for three.
     */
    public function testClassLengthLeavesAttributeLinesOut(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            ClassLengthRule::ID,
            new RuleSettings(true, ['warning' => self::CLASS_WARNING_LINES, 'error' => 100]),
        );

        self::assertSame([], $this->analyseSourceRule($this->attributedClass(2), ClassLengthRule::ID, $config));

        $findings = $this->analyseSourceRule($this->attributedClass(3), ClassLengthRule::ID, $config);
        self::assertCount(1, $findings);
        self::assertStringContainsString('is 15 substantive lines', $findings[0]->message);
    }

    /**
     * Verify test-longer-than-sut measures a test in code lines, and still reports a test long in code.
     *
     * @return void - Asserts no finding for six assignments and one finding with testLines metadata of 12 for seven.
     */
    public function testTestLongerThanSutComparesCodeLines(): void
    {
        $config = AnalysisConfig::fromRegistry(RuleRegistry::defaults())->withRuleSettings(
            TestLongerThanSutRule::ID,
            new RuleSettings(true, ['minTestLines' => self::MIN_SUT_TEST_LINES]),
        );
        self::assertSame([], $this->analyseSourceRule($this->addressTest(6), TestLongerThanSutRule::ID, $config));

        $findings = $this->analyseSourceRule($this->addressTest(7), TestLongerThanSutRule::ID, $config);
        self::assertCount(1, $findings);
        self::assertSame(self::MIN_SUT_TEST_LINES, $findings[0]->metadata['testLines'] ?? null);
    }

    /**
     * Verify a comment after a terminator is not unreachable code, while a statement after a return still is.
     *
     * @return void - Asserts one finding at the statement after return, with none for the trailing comment.
     */
    public function testUnreachableCodeIgnoresACommentAfterATerminator(): void
    {
        $findings = $this->analyseSourceRule(<<<'PHP'
<?php
function relayAnswer(): int
{
    return 1;
    // The relay always answers with one.
}

function brokenRelayAnswer(): int
{
    return 1;
    echo 'never';
}
PHP, UnreachableCodeRule::ID);

        self::assertCount(1, $findings);
        self::assertSame(11, $findings[0]->line);
    }

    /**
     * Build a class whose methods each carry four attribute lines and contribute four code lines beyond the class shell.
     *
     * @param int $methods - Number of four-code-line methods in the class.
     *
     * @return string - PHP source for a class with the requested number of attributed methods.
     */
    private function attributedClass(int $methods): string
    {
        $source = "<?php\nfinal class Relay\n{\n";
        for ($index = 0; $index < $methods; ++$index) {
            $source .= "    #[\\Deprecated]\n    #[\\JetBrains\\PhpStorm\\Pure(\n        true,\n    )]\n";
            $source .= sprintf("    public function answer%d(): int\n    {\n        return %d;\n    }\n", $index, $index);
        }

        return $source . "}\n";
    }

    /**
     * Build a test method with literals + 5 code lines: literal assignments, one SUT call and one assertion,
     * with a comment line above each assignment.
     *
     * @param int $literals - Literal assignments before the SUT call.
     *
     * @return string - PHP source for one PHPUnit test class.
     */
    private function addressTest(int $literals): string
    {
        $source = "<?php\nfinal class AddressTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function testFormats(): void\n    {\n";
        for ($index = 0; $index < $literals; ++$index) {
            $source .= sprintf("        // Part %d of the postal address.\n        \$part%d = 'line-%d';\n", $index, $index, $index);
        }

        return $source . "        \$formatted = formatAddress(\$part0);\n        self::assertSame('line-0', \$formatted);\n    }\n}\n";
    }
}
