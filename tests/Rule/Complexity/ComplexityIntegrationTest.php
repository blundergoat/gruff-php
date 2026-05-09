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

final class ComplexityIntegrationTest extends TestCase
{
    public function testComplexFixtureTriggersMultipleComplexityRules(): void
    {
        $parser = new PhpFileParser();
        $unit = $parser->parse(new SourceFile(
            __DIR__ . '/../../Fixtures/M06/Complexity/cyclomatic.php',
            'tests/Fixtures/M06/Complexity/cyclomatic.php',
        ));

        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry)
            ->withRuleSettings(CyclomaticComplexityRule::ID, new RuleSettings(true, ['warning' => 3, 'error' => 20]))
            ->withRuleSettings(CognitiveComplexityRule::ID, new RuleSettings(true, ['warning' => 2, 'error' => 30]))
            ->withRuleSettings(NestingDepthRule::ID, new RuleSettings(true, ['warning' => 1, 'error' => 6]))
            ->withRuleSettings(NpathComplexityRule::ID, new RuleSettings(true, ['warning' => 3, 'error' => 500]))
            ->withRuleSettings(HalsteadVolumeRule::ID, new RuleSettings(true, ['warning' => 30, 'error' => 2000]))
            ->withRuleSettings(MaintainabilityIndexRule::ID, new RuleSettings(true, ['warning' => 70, 'error' => 40]));

        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $ruleIds = array_unique(array_map(static fn ($f) => $f->ruleId, $findings));

        self::assertContains(CyclomaticComplexityRule::ID, $ruleIds);
        self::assertContains(CognitiveComplexityRule::ID, $ruleIds);
        self::assertContains(NestingDepthRule::ID, $ruleIds);

        $duplicateFingerprints = [];

        foreach ($findings as $finding) {
            $fp = $finding->fingerprint();
            self::assertArrayNotHasKey($fp, $duplicateFingerprints, sprintf('Duplicate fingerprint for %s', $finding->ruleId));
            $duplicateFingerprints[$fp] = true;
        }
    }

    public function testSimpleFixtureProducesNoComplexityFindings(): void
    {
        $parser = new PhpFileParser();
        $unit = $parser->parse(new SourceFile(
            __DIR__ . '/../../Fixtures/M06/Complexity/simple.php',
            'tests/Fixtures/M06/Complexity/simple.php',
        ));

        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $complexityFindings = array_filter($findings, static fn ($f) => str_starts_with($f->ruleId, 'complexity.'));
        self::assertSame([], array_values($complexityFindings));
    }

    public function testConfigOverrideChangesComplexityFindings(): void
    {
        $parser = new PhpFileParser();
        $unit = $parser->parse(new SourceFile(
            __DIR__ . '/../../Fixtures/M06/Complexity/cyclomatic.php',
            'tests/Fixtures/M06/Complexity/cyclomatic.php',
        ));

        $registry = RuleRegistry::defaults();

        $defaultConfig = AnalysisConfig::fromRegistry($registry);
        $defaultFindings = array_filter(
            $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $defaultConfig)),
            static fn ($f) => str_starts_with($f->ruleId, 'complexity.'),
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
            static fn ($f) => str_starts_with($f->ruleId, 'complexity.'),
        );

        self::assertGreaterThan(count($defaultFindings), count($tightFindings));
    }
}
