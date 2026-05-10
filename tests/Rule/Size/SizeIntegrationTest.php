<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\AverageMethodLengthRule;
use GruffPhp\Rule\Size\ClassLengthRule;
use GruffPhp\Rule\Size\FileLengthRule;
use GruffPhp\Rule\Size\MethodLengthRule;
use GruffPhp\Rule\Size\ParameterCountRule;
use GruffPhp\Rule\Size\PropertyCountRule;
use GruffPhp\Rule\Size\PublicMethodCountRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class SizeIntegrationTest extends TestCase
{
    public function testCumulativeFixtureTriggersMultipleRules(): void
    {
        $parser = new PhpFileParser();
        $path = __DIR__ . '/../../Fixtures/Size/cumulative-violations.php';
        $unit = $parser->parse(new SourceFile($path, 'tests/Fixtures/Size/cumulative-violations.php'));

        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);

        $config = $config
            ->withRuleSettings(FileLengthRule::ID, new RuleSettings(true, ['warning' => 10, 'error' => 800]))
            ->withRuleSettings(MethodLengthRule::ID, new RuleSettings(true, ['warning' => 5, 'error' => 60]))
            ->withRuleSettings(ClassLengthRule::ID, new RuleSettings(true, ['warning' => 10, 'error' => 500]))
            ->withRuleSettings(ParameterCountRule::ID, new RuleSettings(true, ['warning' => 5, 'error' => 8]))
            ->withRuleSettings(PublicMethodCountRule::ID, new RuleSettings(true, ['warning' => 5, 'error' => 25]))
            ->withRuleSettings(PropertyCountRule::ID, new RuleSettings(true, ['warning' => 5, 'error' => 25]))
            ->withRuleSettings(AverageMethodLengthRule::ID, new RuleSettings(true, ['warning' => 2, 'error' => 40]));

        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $ruleIds = array_unique(array_map(static fn ($f) => $f->ruleId, $findings));
        sort($ruleIds);

        self::assertContains(FileLengthRule::ID, $ruleIds);
        self::assertContains(MethodLengthRule::ID, $ruleIds);
        self::assertContains(ClassLengthRule::ID, $ruleIds);
        self::assertContains(ParameterCountRule::ID, $ruleIds);
        self::assertContains(PublicMethodCountRule::ID, $ruleIds);
        self::assertContains(PropertyCountRule::ID, $ruleIds);
        self::assertContains(AverageMethodLengthRule::ID, $ruleIds);

        self::assertGreaterThanOrEqual(7, count($findings));

        $duplicateFingerprints = [];

        foreach ($findings as $finding) {
            $fp = $finding->fingerprint();
            self::assertArrayNotHasKey($fp, $duplicateFingerprints, sprintf('Duplicate fingerprint for %s', $finding->ruleId));
            $duplicateFingerprints[$fp] = true;
        }
    }

    public function testConfigOverrideChangesFindings(): void
    {
        $parser = new PhpFileParser();
        $path = __DIR__ . '/../../Fixtures/Size/cumulative-violations.php';
        $unit = $parser->parse(new SourceFile($path, 'tests/Fixtures/Size/cumulative-violations.php'));

        $registry = RuleRegistry::defaults();

        $defaultConfig = AnalysisConfig::fromRegistry($registry);
        $defaultFindings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $defaultConfig));

        $tightConfig = AnalysisConfig::fromRegistry($registry);
        $tightConfig = $tightConfig
            ->withRuleSettings(FileLengthRule::ID, new RuleSettings(true, ['warning' => 10, 'error' => 800]))
            ->withRuleSettings(MethodLengthRule::ID, new RuleSettings(true, ['warning' => 5, 'error' => 60]))
            ->withRuleSettings(ClassLengthRule::ID, new RuleSettings(true, ['warning' => 10, 'error' => 500]))
            ->withRuleSettings(ParameterCountRule::ID, new RuleSettings(true, ['warning' => 2, 'error' => 5]))
            ->withRuleSettings(PublicMethodCountRule::ID, new RuleSettings(true, ['warning' => 3, 'error' => 10]))
            ->withRuleSettings(PropertyCountRule::ID, new RuleSettings(true, ['warning' => 3, 'error' => 10]))
            ->withRuleSettings(AverageMethodLengthRule::ID, new RuleSettings(true, ['warning' => 2, 'error' => 10]));

        $tightFindings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $tightConfig));

        self::assertGreaterThan(count($defaultFindings), count($tightFindings));
    }

    public function testCleanFixtureProducesNoSizeFindings(): void
    {
        $parser = new PhpFileParser();
        $path = __DIR__ . '/../../Fixtures/Size/short-method.php';
        $unit = $parser->parse(new SourceFile($path, 'tests/Fixtures/Size/short-method.php'));

        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $sizeFindings = array_filter($findings, static fn ($f) => str_starts_with($f->ruleId, 'size.'));
        self::assertSame([], array_values($sizeFindings));
    }
}
