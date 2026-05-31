<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Rule\Naming\AbbreviationAllowlistRule;
use GruffPhp\Rule\Naming\BooleanPrefixRule;
use GruffPhp\Rule\Naming\HungarianNotationRule;
use GruffPhp\Rule\Naming\IdentifierQualityRule;
use GruffPhp\Rule\Naming\NegativeBooleanRule;
use GruffPhp\Rule\Naming\ShortVariableRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;

/**
 * Covers configurable naming-rule behaviour and cross-rule deferral.
 */
final class NamingRuleConfigurationTest extends NamingRuleTestCase
{
    /**
     * Verify accepted abbreviations can suppress short variable findings.
     *
     * @return void
     */
    public function testAcceptedAbbreviationsCanSuppressShortVariableFindings(): void
    {
        $unit                  = $this->parseFixture('short-variable.php');
        $registry              = RuleRegistry::defaults();
        $config                = AnalysisConfig::fromRegistry($registry)->withAcceptedAbbreviations(['a']);
        $findings              = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $shortVariableFindings = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === ShortVariableRule::ID,
        ));

        $vars = array_map(static fn ($finding): mixed => $finding->metadata['variable'] ?? null, $shortVariableFindings);
        self::assertContains('x', $vars);
        self::assertNotContains('a', $vars);
    }

    /**
     * Verify undeclared short lowercase abbreviations are reported.
     *
     * @return void
     */
    public function testAbbreviationAllowlistDetectsUndeclaredNames(): void
    {
        $findings = $this->abbreviationFindings(['id', 'fqn', 'raw', 'uri']);
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertContains('cfg', $names);
        self::assertContains('db', $names);
        self::assertContains('tmp', $names);
        self::assertNotContains('id', $names);
        self::assertNotContains('fqn', $names);
        self::assertNotContains('raw', $names);
        self::assertNotContains('uri', $names);
        self::assertNotContains('key', $names);
        self::assertNotContains('row', $names);
        self::assertNotContains('end', $names);
        self::assertNotContains('ex', $names);
    }

    /**
     * Verify projects can opt in additional boolean prefixes.
     *
     * @return void
     */
    public function testBooleanPrefixAllowedPrefixesCanBeConfigured(): void
    {
        $unit       = $this->parseFixture('boolean-prefix.php');
        $registry   = RuleRegistry::defaults();
        $settings   = AnalysisConfig::fromRegistry($registry)->ruleSettings(BooleanPrefixRule::ID);
        $prefixes   = $settings->stringListOption('allowedPrefixes');
        $prefixes[] = 'did';
        $config     = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(true, $settings->thresholds, ['allowedPrefixes' => $prefixes]),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $symbols  = array_map(static fn ($finding): ?string => $finding->symbol, array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === BooleanPrefixRule::ID,
        ));

        self::assertNotContains('BooleanPrefixFixture::didRun()', $symbols);
        self::assertContains('BooleanPrefixFixture::active()', $symbols);
    }

    /**
     * Verify typed bool properties and parameters need boolean-style names.
     *
     * @return void
     */
    public function testBooleanPrefixDetectsPropertiesAndParameters(): void
    {
        $findings = $this->booleanPrefixPropertyFindings();
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertContains('changedOnly', $names);
        self::assertContains('infectionRun', $names);
        self::assertContains('infectionRunCtor', $names);
        self::assertNotContains('isPest', $names);
        self::assertNotContains('active', $names);
        self::assertNotContains('emitted', $names);
        self::assertNotContains('valid', $names);
        self::assertNotContains('silent', $names);
        self::assertNotContains('interactive', $names);
        self::assertNotContains('flag', $names);
    }

    /**
     * Verify the state adjective allowlist controls bool property and parameter exemptions.
     *
     * @return void
     */
    public function testBooleanPrefixStateAdjectiveAllowlistCanBeConfigured(): void
    {
        $findings = $this->booleanPrefixPropertyFindings(['stateAdjectiveAllowlist' => ['active']]);
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertContains('emitted', $names);
        self::assertContains('interactive', $names);
        self::assertNotContains('active', $names);
        self::assertNotContains('isPest', $names);
    }

    /**
     * Verify acceptedBooleanNames clears exact boolean names without a rename (P3).
     *
     * A boolean-returning method named `active()` is normally flagged, and the
     * only in-code fix is the caller-breaking rename to `isActive()`. Listing the
     * exact name in acceptedBooleanNames clears the finding while leaving the
     * public name intact, the non-breaking resolution P3 requires; an unlisted
     * name such as `enabled()` still fires.
     *
     * @return void
     */
    public function testBooleanPrefixAcceptedBooleanNamesCanBeConfigured(): void
    {
        $unit     = $this->parseFixture('boolean-prefix.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(BooleanPrefixRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, ['acceptedBooleanNames' => ['active', 'check']])),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $symbols  = array_map(static fn ($finding): ?string => $finding->symbol, array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === BooleanPrefixRule::ID,
        ));

        self::assertNotContains('BooleanPrefixFixture::active()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::check()', $symbols);
        self::assertContains('BooleanPrefixFixture::enabled()', $symbols);
        self::assertContains('BooleanPrefixFixture::didRun()', $symbols);
    }

    /**
     * Verify Hungarian notation type prefixes can be limited to structural types.
     *
     * @return void
     */
    public function testHungarianNotationTypePrefixesCanBeConfigured(): void
    {
        $findings = $this->hungarianFindings(['arr', 'obj']);
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['variable'] ?? null, $findings);

        self::assertContains('arrItems', $names);
        self::assertContains('objUser', $names);
        self::assertNotContains('strName', $names);
        self::assertNotContains('intCount', $names);
        self::assertNotContains('boolReady', $names);
    }

    /**
     * Verify negative boolean flags are reported and CLI mirror keys can exempt them.
     *
     * @return void
     */
    public function testNegativeBooleanDetectsFlagsAndHonoursCliMirrorAllowlist(): void
    {
        $findings = $this->negativeBooleanFindings();
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);
        $keys     = array_map(static fn ($finding): mixed => $finding->metadata['allowlistKey'] ?? null, $findings);

        self::assertContains('noConfig', $names);
        self::assertContains('disableCache', $names);
        self::assertContains('skipValidation', $names);
        self::assertNotContains('nonNull', $names);
        self::assertNotContains('notFound', $names);
        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::noConfig', $keys);
        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::configure::disableCache', $keys);
        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::configure::skipValidation', $keys);
        self::assertContains('Fixtures\\Naming\\CliMirrorOptions::noConfig', $keys);

        $allowlistedFindings = $this->negativeBooleanFindings(['Fixtures\\Naming\\CliMirrorOptions::noConfig']);
        $allowlistedKeys     = array_map(static fn ($finding): mixed => $finding->metadata['allowlistKey'] ?? null, $allowlistedFindings);

        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::noConfig', $allowlistedKeys);
        self::assertNotContains('Fixtures\\Naming\\CliMirrorOptions::noConfig', $allowlistedKeys);
    }

    /**
     * Verify overlapping naming findings keep the documented primary rule.
     *
     * @return void
     */
    public function testDuplicateNamingFindingsRespectDeferralOrder(): void
    {
        $unit     = $this->parseFixture('duplicate-deferral.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(IdentifierQualityRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            IdentifierQualityRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, [
                'genericTokens' => ['cb', 'str', 'x'],
            ])),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $rules    = $this->rulesByIdentifierName($findings);

        self::assertContains(IdentifierQualityRule::ID, $rules['cb'] ?? []);
        self::assertNotContains(AbbreviationAllowlistRule::ID, $rules['cb'] ?? []);

        self::assertContains(NegativeBooleanRule::ID, $rules['disableCache'] ?? []);
        self::assertNotContains(BooleanPrefixRule::ID, $rules['disableCache'] ?? []);

        self::assertContains(IdentifierQualityRule::ID, $rules['strX'] ?? []);
        self::assertNotContains(HungarianNotationRule::ID, $rules['strX'] ?? []);
    }

    /**
     * Build abbreviation findings for the naming rule.
     *
     * @param list<string> $acceptedAbbreviations
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function abbreviationFindings(array $acceptedAbbreviations): array
    {
        $unit     = $this->parseFixture('abbreviation-allowlist.php');
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withAcceptedAbbreviations($acceptedAbbreviations);
        $settings = $config->ruleSettings(IdentifierQualityRule::ID);
        $config   = $config->withRuleSettings(
            IdentifierQualityRule::ID,
            new RuleSettings(false, $settings->thresholds, $settings->options),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === AbbreviationAllowlistRule::ID,
        ));
    }

    /**
     * Build hungarian findings for the naming rule.
     *
     * @param list<string> $typePrefixes
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function hungarianFindings(array $typePrefixes): array
    {
        $unit     = $this->parseFixture('hungarian.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(HungarianNotationRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            HungarianNotationRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, ['typePrefixes' => $typePrefixes])),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === HungarianNotationRule::ID,
        ));
    }

    /**
     * Build boolean prefix property findings for the naming rule.
     *
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function booleanPrefixPropertyFindings(array $options = []): array
    {
        $unit     = $this->parseFixture('boolean-prefix-properties.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(BooleanPrefixRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, $options)),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === BooleanPrefixRule::ID,
        ));
    }

    /**
     * Build negative boolean findings for the naming rule.
     *
     * @param list<string> $cliMirrorAllowlist
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function negativeBooleanFindings(array $cliMirrorAllowlist = []): array
    {
        $unit     = $this->parseFixture('negative-boolean.php');
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $settings = $config->ruleSettings(NegativeBooleanRule::ID);
        $config   = $config->withRuleSettings(
            NegativeBooleanRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, ['cliMirrorAllowlist' => $cliMirrorAllowlist])),
        );
        $booleanSettings = $config->ruleSettings(BooleanPrefixRule::ID);
        $config          = $config->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(false, $booleanSettings->thresholds, $booleanSettings->options),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === NegativeBooleanRule::ID,
        ));
    }

    /**
     * Group naming findings by reported identifier name.
     *
     * @param list<\GruffPhp\Finding\Finding> $findings
     * @return array<string, list<string>>
     */
    private function rulesByIdentifierName(array $findings): array
    {
        $rules = [];

        foreach ($findings as $finding) {
            $name = $finding->metadata['identifierName']
                ?? $finding->metadata['variable']
                ?? $finding->metadata['parameter']
                ?? null;

            if (!is_string($name)) {
                continue;
            }

            $rules[$name][] = $finding->ruleId;
        }

        return $rules;
    }
}
