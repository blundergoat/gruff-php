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
use PHPUnit\Framework\TestCase;

final class PublicMethodCountRuleTest extends TestCase
{
    private PublicMethodCountRule $rule;
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
     * Verify no findings for few public methods.
     *
     * @return void No return value.
     */
    public function testNoFindingsForFewPublicMethods(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 15, 'error' => 25]);

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
     * Verify private and protected not counted.
     *
     * @return void No return value.
     */
    public function testPrivateAndProtectedNotCounted(): void
    {
        $findings = $this->analyse('many-public-methods.php', ['warning' => 16, 'error' => 25]);

        self::assertSame([], $findings);
    }

    /**
     * Verify interface not flagged.
     *
     * @return void No return value.
     */
    public function testInterfaceNotFlagged(): void
    {
        $findings = $this->analyse('interface-fixture.php', ['warning' => 5, 'error' => 25]);

        self::assertSame([], $findings);
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
