<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Complexity\CognitiveComplexityRule;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Complexity\HalsteadVolumeRule;
use GruffPhp\Rules\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rules\Complexity\NestingDepthRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers co-firing of complexity rules on cumulative fixtures, silence on simple inputs, and response to config overrides.
 */
final class ComplexityIntegrationTest extends TestCase
{
    /**
     * Verify complex fixture triggers multiple complexity rules.
     *
     * @return void
     */
    public function testComplexFixtureTriggersMultipleComplexityRules(): void
    {
        $phpFileParser = new PhpFileParser();
        $unit          = $phpFileParser->parse(new SourceFile(
            __DIR__ . '/../../Fixtures/Complexity/cyclomatic.php',
            'tests/Fixtures/Complexity/cyclomatic.php',
        ));

        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)
            ->withRuleSettings(CyclomaticComplexityRule::ID, new RuleSettings(true, ['warning' => 3, 'error' => 20]))
            ->withRuleSettings(CognitiveComplexityRule::ID, new RuleSettings(true, ['warning' => 2, 'error' => 30]))
            ->withRuleSettings(NestingDepthRule::ID, new RuleSettings(true, ['warning' => 1, 'error' => 6]))
            ->withRuleSettings(HalsteadVolumeRule::ID, new RuleSettings(true, ['warning' => 30, 'error' => 2000]))
            ->withRuleSettings(MaintainabilityIndexRule::ID, new RuleSettings(true, ['warning' => 70, 'error' => 40]));

        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $ruleIds = array_unique(array_map(static fn ($finding): string => $finding->ruleId, $findings));

        self::assertContains(CyclomaticComplexityRule::ID, $ruleIds);
        self::assertContains(CognitiveComplexityRule::ID, $ruleIds);
        self::assertContains(NestingDepthRule::ID, $ruleIds);

        $fingerprints = array_map(static fn ($finding): string => $finding->fingerprint(), $findings);

        self::assertCount(count($fingerprints), array_unique($fingerprints), 'Complexity fixture should not produce duplicate fingerprints.');
    }

    /**
     * Verify simple fixture produces no complexity findings.
     *
     * @return void
     */
    public function testSimpleFixtureProducesNoComplexityFindings(): void
    {
        $phpFileParser = new PhpFileParser();
        $unit          = $phpFileParser->parse(new SourceFile(
            __DIR__ . '/../../Fixtures/Complexity/simple.php',
            'tests/Fixtures/Complexity/simple.php',
        ));

        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $complexityFindings = array_filter($findings, static fn ($finding) => str_starts_with($finding->ruleId, 'complexity.'));
        self::assertSame([], array_values($complexityFindings));
    }

    /**
     * Verify config override changes complexity findings.
     *
     * @return void
     */
    public function testConfigOverrideChangesComplexityFindings(): void
    {
        $phpFileParser = new PhpFileParser();
        $unit          = $phpFileParser->parse(new SourceFile(
            __DIR__ . '/../../Fixtures/Complexity/cyclomatic.php',
            'tests/Fixtures/Complexity/cyclomatic.php',
        ));

        $registry = RuleRegistry::defaults();

        $defaultConfig   = AnalysisConfig::fromRegistry($registry);
        $defaultFindings = array_filter(
            $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $defaultConfig)),
            static fn ($finding) => str_starts_with($finding->ruleId, 'complexity.'),
        );

        $tightConfig = AnalysisConfig::fromRegistry($registry)
            ->withRuleSettings(CyclomaticComplexityRule::ID, new RuleSettings(true, ['warning' => 1, 'error' => 5]))
            ->withRuleSettings(CognitiveComplexityRule::ID, new RuleSettings(true, ['warning' => 1, 'error' => 5]))
            ->withRuleSettings(NestingDepthRule::ID, new RuleSettings(true, ['warning' => 1, 'error' => 3]))
            ->withRuleSettings(HalsteadVolumeRule::ID, new RuleSettings(true, ['warning' => 10, 'error' => 50]))
            ->withRuleSettings(MaintainabilityIndexRule::ID, new RuleSettings(true, ['warning' => 90, 'error' => 70]));

        $tightFindings = array_filter(
            $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $tightConfig)),
            static fn ($finding) => str_starts_with($finding->ruleId, 'complexity.'),
        );

        self::assertGreaterThan(count($defaultFindings), count($tightFindings));
    }
}
