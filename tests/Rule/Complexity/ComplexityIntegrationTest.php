<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\CognitiveComplexityRule;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\Complexity\HalsteadVolumeRule;
use GruffPhp\Rule\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rule\Complexity\NestingDepthRule;
use GruffPhp\Rule\Complexity\NpathComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers co-firing of complexity rules on cumulative fixtures, silence on simple inputs, response to config overrides, and N-path cap surfacing in metadata and messages.
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
            ->withRuleSettings(NpathComplexityRule::ID, new RuleSettings(true, ['warning' => 3, 'error' => 500]))
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
            ->withRuleSettings(NpathComplexityRule::ID, new RuleSettings(true, ['warning' => 1, 'error' => 5]))
            ->withRuleSettings(HalsteadVolumeRule::ID, new RuleSettings(true, ['warning' => 10, 'error' => 50]))
            ->withRuleSettings(MaintainabilityIndexRule::ID, new RuleSettings(true, ['warning' => 90, 'error' => 70]));

        $tightFindings = array_filter(
            $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $tightConfig)),
            static fn ($finding) => str_starts_with($finding->ruleId, 'complexity.'),
        );

        self::assertGreaterThan(count($defaultFindings), count($tightFindings));
    }

    /**
     * Verify NPath cap is explicit in metadata and message.
     *
     * @return void
     */
    public function testNpathCapIsExplicitInMetadataAndMessage(): void
    {
        $phpFileParser = new PhpFileParser();
        $unit          = $phpFileParser->parse(new SourceFile(
            __DIR__ . '/../../Fixtures/Complexity/npath-cap.php',
            'tests/Fixtures/Complexity/npath-cap.php',
        ));
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)
            ->withRuleSettings(NpathComplexityRule::ID, new RuleSettings(true, ['warning' => 1, 'error' => 2]));

        $findings = array_values(array_filter(
            $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config)),
            static fn ($finding): bool => $finding->ruleId === NpathComplexityRule::ID,
        ));

        self::assertCount(1, $findings);
        self::assertSame(100000, $findings[0]->metadata['npath']);
        self::assertTrue($findings[0]->metadata['capped']);
        self::assertStringContainsString('>=100,000 (cap reached)', $findings[0]->message);
    }
}
