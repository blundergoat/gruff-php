<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Provides parsing and analysis helpers for documentation rule tests.
 * Fixture helpers analyse files under tests/Fixtures/Docs using the default registry.
 * Inline-source helpers use temporary files and return findings for one selected rule.
 */
abstract class DocsRuleTestCase extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Create the parser used by the fixture analysis helpers.
     *
     * @return void - Stores a fresh parser for each test.
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Analyse documentation fixtures and return findings for assertions.
     *
     * @param string $fixture - Fixture filename under tests/Fixtures/Docs.
     * @param ?AnalysisConfig $config - Config selecting enabled rules; null uses registry defaults.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - All enabled-rule findings under the effective config; empty when none are reported.
     */
    protected function analyseFixture(string $fixture, ?AnalysisConfig $config = null): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config ??= AnalysisConfig::fromRegistry($registry);

        return $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
    }

    /**
     * Analyse a documentation fixture and retain findings for one rule.
     *
     * @param string $fixture - Fixture filename under tests/Fixtures/Docs.
     * @param string $ruleId - Rule id to keep; findings from every other rule are discarded.
     * @param ?AnalysisConfig $config - Config selecting enabled rules; null uses registry defaults.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Selected-rule findings in registry report order; empty when that rule reports none.
     */
    protected function analyseRule(string $fixture, string $ruleId, ?AnalysisConfig $config = null): array
    {
        return array_values(array_filter(
                                $this->analyseFixture($fixture, $config),
                                static fn($finding): bool => $finding->ruleId === $ruleId,
                            ));
    }

    /**
     * Analyse inline PHP without adding it to the snapshot-locked fixture corpus.
     *
     * @param string              $source - Inline PHP written to a throwaway file before parsing.
     * @param string              $ruleId - Rule id to keep; findings from every other rule are discarded.
     * @param AnalysisConfig|null $config - Effective config override; null uses registry defaults.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings emitted by the named rule for the inline source.
     */
    protected function analyseSourceRule(string $source, string $ruleId, ?AnalysisConfig $config = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-docs-');
        self::assertIsString($path);

        try {
            file_put_contents($path, $source);

            $unit     = $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Docs/inline.php'));
            $registry = RuleRegistry::defaults();
            $findings = $registry->analyse(
                [$unit],
                new RuleContext(__DIR__ . '/../../..', $config ?? AnalysisConfig::fromRegistry($registry)),
            );

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
     * @param string $filename - Fixture filename under tests/Fixtures/Docs.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - parsed fixture with a repo-relative display path so finding output matches a real checkout
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Docs/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Docs/' . $filename));
    }
}
