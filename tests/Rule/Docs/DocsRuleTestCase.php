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
     * @param string $fixture - Fixture filename under tests/Fixtures/Docs.
     * @param ?AnalysisConfig $config - Optional analysis config; defaults to the full default-registry config.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - every finding the default rule set raises against the fixture, unfiltered; empty when the fixture is
     *                                         clean
     */
    protected function analyseFixture(string $fixture, ?AnalysisConfig $config = null): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config ??= AnalysisConfig::fromRegistry($registry);

        return $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
    }

    /**
     * Analyse documentation fixtures and return findings for assertions.
     *
     * @param string $fixture - Fixture filename under tests/Fixtures/Docs.
     * @param string $ruleId - Rule id to keep; findings from every other rule are discarded.
     * @param ?AnalysisConfig $config - Optional analysis config; defaults to the full default-registry config.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - findings from the named rule only, in encounter order; empty when that rule raises nothing
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
     * @param string $filename - Fixture filename.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - parsed fixture with a repo-relative display path so finding output matches a real checkout
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Docs/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Docs/' . $filename));
    }
}
