<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Rules\Naming\AbbreviationAllowlistRule;
use GruffPhp\Rules\Naming\BooleanPrefixRule;
use GruffPhp\Rules\Naming\HungarianNotationRule;
use GruffPhp\Rules\Naming\IdentifierQualityRule;
use GruffPhp\Rules\Naming\NegativeBooleanRule;
use GruffPhp\Rules\Naming\ShortVariableRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;

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
                                                  static fn($finding): bool => $finding->ruleId === ShortVariableRule::ID,
                                              ));

        $vars = array_map(static fn($finding): mixed => $finding->metadata['variable'] ?? null, $shortVariableFindings);
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
        $names    = array_map(static fn($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

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

        $decisionFindings = $this->analyseSourceRule(<<<'PHP'
<?php
final class DomainTerms
{
    public function labels(): array
    {
        $dob = '2000-01-01';
        $tmp = 'transient';

        return [$dob, $tmp];
    }
}
PHP, AbbreviationAllowlistRule::ID);
        $tmpFindings = array_values(array_filter(
            $findings,
            static fn($finding): bool => ($finding->metadata['identifierName'] ?? null) === 'tmp',
        ));
        $decisionFindings = array_merge($decisionFindings, $tmpFindings);
        $decisionNames    = array_map(
            static fn($finding): mixed => $finding->metadata['identifierName'] ?? null,
            $decisionFindings,
        );
        sort($decisionNames);
        self::assertSame(['dob', 'tmp'], $decisionNames);
        self::assertSame(
            [RemediationAction::Consider->value],
            array_values(array_unique(array_map(
                static fn($finding): string => is_string($finding->metadata['remediationAction'] ?? null)
                    ? $finding->metadata['remediationAction']
                    : '',
                $decisionFindings,
            ))),
        );
        self::assertSame(
            ['allowlists.acceptedAbbreviations'],
            array_values(array_unique(array_map(
                static fn($finding): string => is_string($finding->metadata['configurationKey'] ?? null)
                    ? $finding->metadata['configurationKey']
                    : '',
                $decisionFindings,
            ))),
        );
        self::assertSame(
            ['Rename the identifier or add the abbreviation to allowlists.acceptedAbbreviations with a documented meaning.'],
            array_values(array_unique(array_map(
                static fn($finding): ?string => $finding->remediation,
                $decisionFindings,
            ))),
        );
    }

    /**
     * Verify universal DTO and UTC vocabulary stays quiet while domain and out-of-band names retain their contracts.
     *
     * @return void
     */
    public function testAbbreviationAllowlistShipsUniversalDtoAndUtcDefaults(): void
    {
        $findings = $this->analyseSourceRule(<<<'PHP'
<?php
function mapDomainValues(string $dto, string $utc, string $dob, string $uuid): void
{
}
PHP, AbbreviationAllowlistRule::ID);
        $names = array_map(
            static fn($finding): mixed => $finding->metadata['identifierName'] ?? null,
            $findings,
        );
        sort($names);

        self::assertSame(['dob'], $names);
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
        $findings   = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $symbols    = array_map(static fn($finding): ?string => $finding->symbol, array_filter(
                                                                                      $findings,
                                                                                      static fn($finding): bool => $finding->ruleId === BooleanPrefixRule::ID,
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
        $names    = array_map(static fn($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertContains('changedOnly', $names);
        self::assertContains('infectionRun', $names);
        self::assertContains('infectionRunCtor', $names);
        // A prefix that does not lead the name is no predicate, with or without a trailing word.
        self::assertContains('force', $names);
        self::assertContains('forceShould', $names);
        self::assertNotContains('isPest', $names);
        // snake_case prefixes read as predicates: the underscore is a word boundary.
        self::assertNotContains('is_valid', $names);
        self::assertNotContains('active', $names);
        self::assertNotContains('emitted', $names);
        self::assertNotContains('valid', $names);
        self::assertNotContains('silent', $names);
        self::assertNotContains('interactive', $names);
        self::assertNotContains('flag', $names);
        self::assertContains('data', $names);
        self::assertContains('result', $names);
        self::assertContains('mode', $names);
        self::assertContains('required', $names);
        self::assertNotContains('focusModePayloadPresent', $names);
        self::assertNotContains('printableTodayScheduleRequested', $names);
        self::assertNotContains('bookingRequiresConfirmation', $names);
        self::assertNotContains('limited', $names);
        self::assertNotContains('printable', $names);
        self::assertNotContains('paymentRequested', $names);
        self::assertNotContains('assistantIntentRequiresContext', $names);
        self::assertNotContains('resolved', $names);
        self::assertNotContains('declineCodeExplanationRequested', $names);

        $privatePromotion = array_values(array_filter(
            $findings,
            static fn($finding): bool => $finding->metadata['identifierName'] === 'infectionRunCtor',
        ))[0] ?? null;
        $publicProperty = array_values(array_filter(
            $findings,
            static fn($finding): bool => $finding->symbol === '$changedOnly',
        ))[0] ?? null;
        $publicParameter = array_values(array_filter(
            $findings,
            static fn($finding): bool => $finding->symbol === 'BooleanPrefixPropertiesFixture::configure()'
                && $finding->metadata['identifierName'] === 'changedOnly',
        ))[0] ?? null;
        $localClosure = $this->analyseRule('closure-coverage.php', BooleanPrefixRule::ID)[0] ?? null;

        self::assertNotNull($privatePromotion);
        self::assertNotNull($publicProperty);
        self::assertNotNull($publicParameter);
        self::assertNotNull($localClosure);
        self::assertSame(RemediationAction::Apply->value, $privatePromotion->metadata['remediationAction'] ?? null);
        self::assertSame(RemediationAction::Consider->value, $publicProperty->metadata['remediationAction'] ?? null);
        self::assertSame(RemediationAction::Consider->value, $publicParameter->metadata['remediationAction'] ?? null);
        self::assertSame(RemediationAction::Apply->value, $localClosure->metadata['remediationAction'] ?? null);

        $expectedConfigurationKey = 'rules.naming.boolean-prefix.options.acceptedBooleanNames';
        self::assertSame($expectedConfigurationKey, $privatePromotion->metadata['configurationKey'] ?? null);
        self::assertSame($expectedConfigurationKey, $publicProperty->metadata['configurationKey'] ?? null);
        self::assertSame($expectedConfigurationKey, $publicParameter->metadata['configurationKey'] ?? null);
        self::assertSame($expectedConfigurationKey, $localClosure->metadata['configurationKey'] ?? null);
    }

    /**
     * Verify the state adjective allowlist controls bool property and parameter exemptions.
     *
     * @return void
     */
    public function testBooleanPrefixStateAdjectiveAllowlistCanBeConfigured(): void
    {
        $findings = $this->booleanPrefixPropertyFindings(['stateAdjectiveAllowlist' => ['active']]);
        $names    = array_map(static fn($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

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
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, ['acceptedBooleanNames' => ['ACTIVE', 'CHECK']])),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $symbols  = array_map(static fn($finding): ?string => $finding->symbol, array_filter(
                                                                                    $findings,
                                                                                    static fn($finding): bool => $finding->ruleId === BooleanPrefixRule::ID,
                                                                                ));

        self::assertNotContains('BooleanPrefixFixture::active()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::check()', $symbols);
        self::assertContains('BooleanPrefixFixture::enabled()', $symbols);
        self::assertContains('BooleanPrefixFixture::didRun()', $symbols);
    }

    /**
     * Verify projects can limit Boolean naming checks to private and local declarations.
     *
     * @return void
     */
    public function testBooleanPrefixCanSkipCallerVisibleApi(): void
    {
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(BooleanPrefixRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, ['includePublicApi' => false])),
        );
        $findings = $this->analyseSourceRule(<<<'PHP'
<?php
final class ApiFlags
{
    public bool $publicState = false;
    private bool $privateState = false;

    public function publicStatus(bool $publicFlag): bool
    {
        return $publicFlag;
    }

    private function privateStatus(bool $privateFlag): bool
    {
        return $privateFlag;
    }

    public function registerCallback(): void
    {
        $callback = static fn (bool $closureFlag): bool => $closureFlag;
    }
}

function publicFunctionStatus(bool $functionFlag): bool
{
    return $functionFlag;
}
PHP, BooleanPrefixRule::ID, config: $config);
        $symbols = array_map(static fn($finding): ?string => $finding->symbol, $findings);
        $names   = array_map(static fn($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertNotContains('$publicState', $symbols);
        self::assertNotContains('ApiFlags::publicStatus()', $symbols);
        self::assertNotContains('publicFunctionStatus()', $symbols);
        self::assertNotContains('publicFlag', $names);
        self::assertNotContains('functionFlag', $names);
        self::assertContains('$privateState', $symbols);
        self::assertContains('ApiFlags::privateStatus()', $symbols);
        self::assertContains('privateFlag', $names);
        self::assertContains('closureFlag', $names);
    }

    /**
     * Verify suffix, proposition, adjective, and exact-name configuration stay independent.
     *
     * @return void
     */
    public function testBooleanPrefixStateAndPropositionAllowlistsCanBeConfigured(): void
    {
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(BooleanPrefixRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, [
                'stateSuffixAllowlist' => ['requested'],
                'stateAdjectiveAllowlist' => ['active', 'required'],
                'propositionVerbAllowlist' => ['requires'],
                'acceptedBooleanNames' => ['status'],
            ])),
        );
        $units = [
            $this->parseFixture('boolean-prefix.php'),
            $this->parseFixture('boolean-prefix-properties.php'),
        ];
        $findings        = $registry->analyse($units, new RuleContext(__DIR__ . '/../../..', $config));
        $booleanFindings = array_values(array_filter(
            $findings,
            static fn($finding): bool => $finding->ruleId === BooleanPrefixRule::ID,
        ));
        $symbols = array_map(static fn($finding): ?string => $finding->symbol, $booleanFindings);
        $names   = array_map(static fn($finding): mixed => $finding->metadata['identifierName'] ?? null, $booleanFindings);

        self::assertNotContains('BooleanStateVocabularyFixture::paymentRequested()', $symbols);
        self::assertNotContains('BooleanStateVocabularyFixture::assistantIntentRequiresContext()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::status()', $symbols);
        self::assertContains('BooleanStateVocabularyFixture::valid()', $symbols);
        self::assertContains('focusModePayloadPresent', $names);
        self::assertNotContains('required', $names);
        self::assertContains('resolved', $names);
        self::assertNotContains('assistantIntentRequiresContext', $names);
    }

    /**
     * Verify Hungarian notation type prefixes can be limited to structural types.
     *
     * @return void
     */
    public function testHungarianNotationTypePrefixesCanBeConfigured(): void
    {
        $findings = $this->hungarianFindings(['arr', 'obj']);
        $names    = array_map(static fn($finding): mixed => $finding->metadata['variable'] ?? null, $findings);

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
        $names    = array_map(static fn($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);
        $keys     = array_map(static fn($finding): mixed => $finding->metadata['allowlistKey'] ?? null, $findings);

        self::assertContains('noConfig', $names);
        self::assertContains('disableCache', $names);
        self::assertContains('skipValidation', $names);
        self::assertNotContains('nonNull', $names);
        self::assertNotContains('notFound', $names);
        // Snake_case negative prefixes report at underscore boundaries; word-internal "no"/"non" never match.
        self::assertContains('no_cache', $names);
        self::assertContains('not_ready', $names);
        self::assertNotContains('normalised_output', $names);
        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::noConfig', $keys);
        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::configure::disableCache', $keys);
        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::configure::skipValidation', $keys);
        self::assertContains('Fixtures\\Naming\\CliMirrorOptions::noConfig', $keys);
        self::assertContains('Fixtures\\Naming\\SnakeCaseSyncOptions::no_cache', $keys);
        self::assertContains('Fixtures\\Naming\\SnakeCaseSyncOptions::synchronise::not_ready', $keys);
        self::assertContains('Fixtures\\Naming\\SnakeCliMirrorOptions::no_color', $keys);

        $allowlistedFindings = $this->negativeBooleanFindings([
                                                                  'Fixtures\\Naming\\CliMirrorOptions::noConfig',
                                                                  'Fixtures\\Naming\\SnakeCliMirrorOptions::no_color',
                                                              ]);
        $allowlistedKeys     = array_map(static fn($finding): mixed => $finding->metadata['allowlistKey'] ?? null, $allowlistedFindings);

        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::noConfig', $allowlistedKeys);
        self::assertNotContains('Fixtures\\Naming\\CliMirrorOptions::noConfig', $allowlistedKeys);
        self::assertContains('Fixtures\\Naming\\SnakeCaseSyncOptions::no_cache', $allowlistedKeys);
        self::assertNotContains('Fixtures\\Naming\\SnakeCliMirrorOptions::no_color', $allowlistedKeys);
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

        // Snake_case negatives get exactly one owner too: negative-boolean wins, boolean-prefix defers.
        $snakeUnit     = $this->parseFixture('negative-boolean.php');
        $snakeFindings = $registry->analyse([$snakeUnit], new RuleContext(__DIR__ . '/../../..', AnalysisConfig::fromRegistry($registry)));
        $snakeRules    = $this->rulesByIdentifierName($snakeFindings);

        self::assertContains(NegativeBooleanRule::ID, $snakeRules['no_cache'] ?? []);
        self::assertNotContains(BooleanPrefixRule::ID, $snakeRules['no_cache'] ?? []);
    }

    /**
     * Build abbreviation findings for the naming rule.
     *
     * @param list<string> $acceptedAbbreviations - Accepted abbreviation values to apply before analysing the fixture.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - surviving abbreviation-allowlist findings after the supplied accepted abbreviations are applied;
     *                                         empty when every short name is accepted
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
                                static fn($finding): bool => $finding->ruleId === AbbreviationAllowlistRule::ID,
                            ));
    }

    /**
     * Build hungarian findings for the naming rule.
     *
     * @param list<string> $typePrefixes - Hungarian prefixes treated as type markers for this assertion.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Hungarian-notation findings produced under the supplied type prefixes; empty when no prefixed
     *                                         variable matches
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
                                static fn($finding): bool => $finding->ruleId === HungarianNotationRule::ID,
                            ));
    }

    /**
     * Build boolean prefix property findings for the naming rule.
     *
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options - Boolean-prefix rule option overrides.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - boolean-prefix findings for the fixture's properties and parameters under the supplied options;
     *                                         empty when every bool name is exempt
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
                                static fn($finding): bool => $finding->ruleId === BooleanPrefixRule::ID,
                            ));
    }

    /**
     * Build negative boolean findings for the naming rule.
     *
     * @param list<string> $cliMirrorAllowlist - Negated boolean names exempted as CLI mirror flags.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - negative-boolean findings with BooleanPrefixRule disabled so each negated name is counted once;
     *                                         empty when the allowlist exempts them all
     */
    private function negativeBooleanFindings(array $cliMirrorAllowlist = []): array
    {
        $unit            = $this->parseFixture('negative-boolean.php');
        $registry        = RuleRegistry::defaults();
        $config          = AnalysisConfig::fromRegistry($registry);
        $settings        = $config->ruleSettings(NegativeBooleanRule::ID);
        $config          = $config->withRuleSettings(
            NegativeBooleanRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, ['cliMirrorAllowlist' => $cliMirrorAllowlist])),
        );
        $booleanSettings = $config->ruleSettings(BooleanPrefixRule::ID);
        $config          = $config->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(false, $booleanSettings->thresholds, $booleanSettings->options),
        );
        $findings        = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        // Keeps only NegativeBooleanRule findings; this config disables BooleanPrefixRule so the two
        // rules cannot both flag the same negated boolean name and inflate the result.
        return array_values(array_filter(
                                $findings,
                                static fn($finding): bool => $finding->ruleId === NegativeBooleanRule::ID,
                            ));
    }

    /**
     * Group naming findings by reported identifier name.
     *
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Naming findings to group by reported identifier name.
     *
     * @return array<string, list<string>> - identifier name mapped to the rule ids that flagged it, for asserting which names overlap across rules
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
