<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Provides fixture-file and inline-source helpers for naming-rule tests.
 * Uses the default rule registry, with optional configuration for inline analysis.
 * Filters findings to a requested rule and parses fixtures with repository-relative display paths.
 */
abstract class NamingRuleTestCase extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Create the parser used by each naming-rule test.
     *
     * @return void - Initializes the parser property for fixture and inline-source analysis.
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
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for the requested rule in canonical report order; empty when none remain.
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
     * Analyse inline PHP and return findings for assertions.
     *
     * @param string              $source      - Inline PHP written to a throwaway temp file before parsing.
     * @param string              $ruleId      - Rule id to keep; findings from every other rule are discarded.
     * @param string              $displayPath - Path the rule sees, letting a test exercise path-sensitive naming checks.
     * @param AnalysisConfig|null $config      - Effective config override; null uses registry defaults.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for the requested rule in canonical report order; empty when none remain.
     */
    protected function analyseSourceRule(
        string $source,
        string $ruleId,
        string $displayPath = 'tests/Fixtures/Naming/inline.php',
        ?AnalysisConfig $config = null,
    ): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-naming-');
        self::assertIsString($path);

        try {
            file_put_contents($path, $source);

            $unit     = $this->parser->parse(new SourceFile($path, $displayPath));
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
     * @param string $filename - Fixture filename relative to tests/Fixtures/Naming.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - Parsed fixture with diagnostics and a repository-relative display path.
     */
    protected function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Naming/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Naming/' . $filename));
    }
}
