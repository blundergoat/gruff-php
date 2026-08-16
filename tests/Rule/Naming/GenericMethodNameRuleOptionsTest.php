<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Rules\Naming\GenericMethodNameRule;
use GruffPhp\Rules\Naming\IdentifierQualityRule;
use GruffPhp\Rules\RuleRegistry;

/**
 * Covers replacement-list configuration for generic method names.
 */
final class GenericMethodNameRuleOptionsTest extends NamingRuleTestCase
{
    /**
     * Verify configured generic names replace defaults without changing helper-class classification.
     *
     * @return void
     */
    public function testConfiguredGenericNamesReplaceDefaultsAndPreserveHelperClassBehavior(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $settings = $config->ruleSettings(GenericMethodNameRule::ID);
        $config   = $config->withRuleSettings(
            GenericMethodNameRule::ID,
            new RuleSettings(
                true,
                $settings->thresholds,
                [...$settings->options, 'genericNames' => ['FeTcH']],
            ),
        );
        $source = <<<'PHP'
<?php
final class BillingHelper
{
    public function fetch(): void
    {
    }

    public function process(): void
    {
    }
}
PHP;

        $genericFindings = $this->analyseSourceRule($source, GenericMethodNameRule::ID, config: $config);
        self::assertSame(
            ['BillingHelper::fetch()'],
            array_map(static fn ($finding): ?string => $finding->symbol, $genericFindings),
        );

        self::assertSame(
            [],
            $this->analyseSourceRule($source, IdentifierQualityRule::ID, config: $config),
        );
    }

    /**
     * Verify padded and blank configured names normalise instead of silently never matching.
     *
     * @return void
     */
    public function testConfiguredGenericNamesTolerateSurroundingWhitespace(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $settings = $config->ruleSettings(GenericMethodNameRule::ID);
        $config   = $config->withRuleSettings(
            GenericMethodNameRule::ID,
            new RuleSettings(
                true,
                $settings->thresholds,
                [...$settings->options, 'genericNames' => ['  fetch  ', '', '   ']],
            ),
        );
        $source = <<<'PHP'
<?php
final class BillingHelper
{
    public function fetch(): void
    {
    }
}
PHP;

        self::assertSame(
            ['BillingHelper::fetch()'],
            array_map(
                static fn ($finding): ?string => $finding->symbol,
                $this->analyseSourceRule($source, GenericMethodNameRule::ID, config: $config),
            ),
        );
    }
}
