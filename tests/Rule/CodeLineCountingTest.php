<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\ClassLengthRule;
use GruffPhp\Rules\TestQuality\SetupBloatRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rules\TestQuality\TestMethodTooLongRule;
use GruffPhp\Rules\Waste\UnreachableCodeRule;
use GruffPhp\Tests\Rule\Docs\DocsRuleTestCase;

/**
 * Pins FAMILY-CONTRACT section 12 (search `Code lines in every line count`) for the rules precision-floor M12
 * moved to code lines: comment, attribute and blank lines never push a unit over a limit, and a unit one code
 * line over the limit still reports.
 */
final class CodeLineCountingTest extends DocsRuleTestCase
{
    /** Class-length warning bar used by the attribute-line control. */
    private const CLASS_WARNING_LINES = 12;

    /** test-longer-than-sut's default minTestLines: a test this long in code lines reports. */
    private const MIN_SUT_TEST_LINES = 12;

    /** test-method-too-long's default maxMeaningfulLines: one line more reports. */
    private const MAX_MEANINGFUL_LINES = 25;

    /**
     * Verify attribute lines do not count toward a class's length, while code lines still do.
     *
     * @return void
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
     * Verify setup-bloat weighs setUp() and the tests by code lines, and still reports a setUp() long in code.
     *
     * @return void
     */
    public function testSetupBloatComparesCodeLines(): void
    {
        self::assertSame([], $this->analyseSourceRule($this->relayTest(4), SetupBloatRule::ID));
        self::assertCount(1, $this->analyseSourceRule($this->relayTest(5), SetupBloatRule::ID));
    }

    /**
     * Verify test-longer-than-sut measures a test in code lines, and still reports a test long in code.
     *
     * @return void
     */
    public function testTestLongerThanSutComparesCodeLines(): void
    {
        self::assertSame([], $this->analyseSourceRule($this->addressTest(6), TestLongerThanSutRule::ID));

        $findings = $this->analyseSourceRule($this->addressTest(7), TestLongerThanSutRule::ID);
        self::assertCount(1, $findings);
        self::assertSame(self::MIN_SUT_TEST_LINES, $findings[0]->metadata['testLines'] ?? null);
    }

    /**
     * Verify test-method-too-long leaves the prose lines of a block comment out, and still reports a long test.
     *
     * @return void
     */
    public function testTestMethodTooLongReadsTheSharedMask(): void
    {
        self::assertSame([], $this->analyseSourceRule($this->longTest(24), TestMethodTooLongRule::ID));

        $findings = $this->analyseSourceRule($this->longTest(25), TestMethodTooLongRule::ID);
        self::assertCount(1, $findings);
        self::assertSame(self::MAX_MEANINGFUL_LINES + 1, $findings[0]->metadata['meaningfulLines'] ?? null);
    }

    /**
     * Verify a comment after a terminator is not unreachable code, while a statement after a return still is.
     *
     * @return void
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
     * Build a class whose methods each carry four attribute lines: `3 + 4 * methods` code lines.
     *
     * @param int $methods - Number of four-code-line methods in the class.
     *
     * @return string - PHP source for one class.
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
     * Build a test class whose setUp() has `statements + 3` code lines among comment and blank lines, beside one
     * four-code-line test.
     *
     * @param int $statements - Statements in setUp().
     *
     * @return string - PHP source for one PHPUnit test class.
     */
    private function relayTest(int $statements): string
    {
        $source = "<?php\nfinal class RelayTest extends \\PHPUnit\\Framework\\TestCase\n{\n    private array \$hosts = [];\n\n"
            . "    protected function setUp(): void\n    {\n";
        for ($index = 0; $index < $statements; ++$index) {
            $source .= sprintf("        // Host %d joins the relay pool.\n\n        \$this->hosts[] = 'host-%d';\n", $index, $index);
        }

        return $source . "    }\n\n    public function testSends(): void\n    {\n        self::assertCount(1, \$this->hosts);\n    }\n}\n";
    }

    /**
     * Build a test whose body is `literals + 5` code lines: literal assignments, one SUT call and one assertion,
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

    /**
     * Build a test with `statements + 1` meaningful lines and a block comment whose prose lines have no leading `*`.
     *
     * @param int $statements - Statement lines in the test body.
     *
     * @return string - PHP source for one PHPUnit test class.
     */
    private function longTest(int $statements): string
    {
        $source = "<?php\nfinal class LedgerTest extends \\PHPUnit\\Framework\\TestCase\n{\n"
            . "    #[\\PHPUnit\\Framework\\Attributes\\Group('slow')]\n    public function testBalances(): void\n    {\n"
            . "        /*\n        Context: the ledger replays every entry\n        before it compares the balance\n        */\n";
        for ($index = 0; $index < $statements - 1; ++$index) {
            $source .= sprintf("        \$entry%d = %d;\n", $index, $index);
        }

        return $source . "        self::assertSame(0, \$entry0);\n    }\n}\n";
    }
}
