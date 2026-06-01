<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Provides shared fixture helpers for naming rule tests.
 */
abstract class NamingRuleTestCase extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Analyse naming fixtures and return findings for assertions.
     *
     * @param string $fixture - Fixture filename under tests/Fixtures/Naming.
     * @param string $ruleId - Rule id to keep; findings from every other rule are discarded.
     *
     * @return list<\GruffPhp\Finding\Finding> - findings emitted by the named rule, in detection order; empty when the fixture is clean
     */
    protected function analyseRule(string $fixture, string $ruleId): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter($findings, static fn($finding): bool => $finding->ruleId === $ruleId));
    }

    /**
     * Analyse naming fixtures and return findings for assertions.
     *
     * @param string $source - Inline PHP written to a throwaway temp file before parsing.
     * @param string $ruleId - Rule id to keep; findings from every other rule are discarded.
     * @param string $displayPath - Path the rule sees, letting a test exercise path-sensitive naming checks.
     *
     * @return list<\GruffPhp\Finding\Finding> - findings emitted by the named rule for the inline source, in detection order; empty when no
     *                                         violation fires
     */
    protected function analyseSourceRule(string $source, string $ruleId, string $displayPath = 'tests/Fixtures/Naming/inline.php'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-naming-');
        self::assertIsString($path);

        try {
            file_put_contents($path, $source);

            $unit     = $this->parser->parse(new SourceFile($path, $displayPath));
            $registry = RuleRegistry::defaults();
            $config   = AnalysisConfig::fromRegistry($registry);
            $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

            return array_values(array_filter($findings, static fn($finding): bool => $finding->ruleId === $ruleId));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Fixture filename.
     *
     * @return \GruffPhp\Parser\AnalysisUnit - the parsed fixture ready for rule analysis, carrying its repo-relative display path
     */
    protected function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Naming/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Naming/' . $filename));
    }
}
