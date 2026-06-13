<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\AverageMethodLengthRule;
use GruffPhp\Rules\Size\ClassLengthRule;
use GruffPhp\Rules\Size\FileLengthRule;
use GruffPhp\Rules\Size\MethodLengthRule;
use GruffPhp\Rules\Size\ParameterCountRule;
use GruffPhp\Rules\Size\PropertyCountRule;
use GruffPhp\Rules\Size\PublicMethodCountRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers co-firing of size rules on cumulative fixtures, config-override response, and silence on clean inputs.
 */
final class SizeIntegrationTest extends TestCase
{
    /**
     * Verify cumulative fixture triggers multiple rules.
     *
     * @return void
     */
    public function testCumulativeFixtureTriggersMultipleRules(): void
    {
        $phpFileParser = new PhpFileParser();
        $path          = __DIR__ . '/../../Fixtures/Size/cumulative-violations.php';
        $unit          = $phpFileParser->parse(new SourceFile($path, 'tests/Fixtures/Size/cumulative-violations.php'));

        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);

        $config = $config
            ->withRuleSettings(FileLengthRule::ID, new RuleSettings(true, ['warning' => 10, 'error' => 800]))
            ->withRuleSettings(MethodLengthRule::ID, new RuleSettings(true, ['warning' => 5, 'error' => 60]))
            ->withRuleSettings(ClassLengthRule::ID, new RuleSettings(true, ['warning' => 10, 'error' => 500]))
            ->withRuleSettings(ParameterCountRule::ID, new RuleSettings(true, ['warning' => 5, 'error' => 8]))
            ->withRuleSettings(PublicMethodCountRule::ID, new RuleSettings(true, ['warning' => 5, 'error' => 25]))
            ->withRuleSettings(PropertyCountRule::ID, new RuleSettings(true, ['warning' => 5, 'error' => 25]))
            ->withRuleSettings(AverageMethodLengthRule::ID, new RuleSettings(true, ['warning' => 2, 'error' => 40]));

        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $ruleIds = array_unique(array_map(static fn ($finding): string => $finding->ruleId, $findings));
        sort($ruleIds);

        self::assertContains(FileLengthRule::ID, $ruleIds);
        self::assertContains(MethodLengthRule::ID, $ruleIds);
        self::assertContains(ClassLengthRule::ID, $ruleIds);
        self::assertContains(ParameterCountRule::ID, $ruleIds);
        self::assertContains(PublicMethodCountRule::ID, $ruleIds);
        self::assertContains(PropertyCountRule::ID, $ruleIds);
        self::assertContains(AverageMethodLengthRule::ID, $ruleIds);

        self::assertGreaterThanOrEqual(7, count($findings));

        $fingerprints = array_map(static fn ($finding): string => $finding->fingerprint(), $findings);

        self::assertCount(count($fingerprints), array_unique($fingerprints), 'Size fixture should not produce duplicate fingerprints.');
    }

    /**
     * Verify config override changes findings.
     *
     * @return void
     */
    public function testConfigOverrideChangesFindings(): void
    {
        $phpFileParser = new PhpFileParser();
        $path          = __DIR__ . '/../../Fixtures/Size/cumulative-violations.php';
        $unit          = $phpFileParser->parse(new SourceFile($path, 'tests/Fixtures/Size/cumulative-violations.php'));

        $registry = RuleRegistry::defaults();

        $defaultConfig   = AnalysisConfig::fromRegistry($registry);
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

    /**
     * Verify clean fixture produces no size findings.
     *
     * @return void
     */
    public function testCleanFixtureProducesNoSizeFindings(): void
    {
        $phpFileParser = new PhpFileParser();
        $path          = __DIR__ . '/../../Fixtures/Size/short-method.php';
        $unit          = $phpFileParser->parse(new SourceFile($path, 'tests/Fixtures/Size/short-method.php'));

        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $sizeFindings = array_filter($findings, static fn ($finding) => str_starts_with($finding->ruleId, 'size.'));
        self::assertSame([], array_values($sizeFindings));
    }
}
