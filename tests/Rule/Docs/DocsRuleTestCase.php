<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Provides shared fixture helpers for documentation rule tests.
 */
abstract class DocsRuleTestCase extends TestCase
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
     * Analyse documentation fixtures and return findings for assertions.
     *
     * @param string $fixture Fixture filename under tests/Fixtures/Docs.
     * @return list<\GruffPhp\Finding\Finding>
     */
    protected function analyseFixture(string $fixture): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);

        // Every finding the default rule set raises against this fixture, unfiltered.
        return $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
    }

    /**
     * Analyse documentation fixtures and return findings for assertions.
     *
     * @param string $fixture Fixture filename under tests/Fixtures/Docs.
     * @param string $ruleId Rule id to keep; findings from every other rule are discarded.
     * @return list<\GruffPhp\Finding\Finding>
     */
    protected function analyseRule(string $fixture, string $ruleId): array
    {
        // Narrow the full fixture run down to the one rule under test.
        return array_values(array_filter(
            $this->analyseFixture($fixture),
            static fn ($finding): bool => $finding->ruleId === $ruleId,
        ));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     * @return \GruffPhp\Parser\AnalysisUnit
     */
    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Docs/' . $filename;

        // Display path stays repo-relative so finding output matches a real checkout.
        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Docs/' . $filename));
    }
}
