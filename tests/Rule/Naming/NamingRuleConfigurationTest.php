<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Rules\Naming\AbbreviationAllowlistRule;
use GruffPhp\Rules\Naming\IdentifierQualityRule;
use GruffPhp\Rules\Naming\ShortVariableRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;

/**
 * Covers accepted abbreviations and undeclared-name findings.
 * Pins the default abbreviation list and checks naming-rule overlap resolution.
 * Checks remediation metadata for abbreviations that require a project decision.
 */
final class NamingRuleConfigurationTest extends NamingRuleTestCase
{
    /**
     * Verify accepted abbreviations can suppress short variable findings.
     *
     * @return void - Asserts x remains reported and the accepted abbreviation a is absent.
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
     * @return void - Asserts undeclared names and the remediation metadata for project vocabulary decisions.
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
     * Verify the default abbreviation list contains the expected sixteen entries.
     * Check that dto, utc, and dob are reported while the seeded id is not.
     *
     * @return void - Asserts the exact default list and the findings for unseeded domain abbreviations.
     */
    public function testAbbreviationAllowlistSeedsExactlyTheRatifiedSixteen(): void
    {
        self::assertSame(
            ['age', 'app', 'db', 'fs', 'id', 'io', 'key', 'log', 'max', 'min', 'now', 'raw', 'rx', 'tx', 'ui', 'url'],
            AnalysisConfig::DEFAULT_ACCEPTED_ABBREVIATIONS,
        );

        $findings = $this->analyseSourceRule(<<<'PHP'
<?php
function mapDomainValues(string $dto, string $utc, string $dob, string $id): void
{
}
PHP, AbbreviationAllowlistRule::ID);
        $names = array_map(
            static fn($finding): mixed => $finding->metadata['identifierName'] ?? null,
            $findings,
        );
        sort($names);


        self::assertSame(['dob', 'dto', 'utc'], $names);
    }

    /**
     * Verify identifier quality takes precedence over abbreviation findings for cb and still reports strX.
     *
     * @return void - Asserts cb has identifier quality without an abbreviation finding and strX retains identifier quality.
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

        self::assertContains(IdentifierQualityRule::ID, $rules['strX'] ?? []);
    }

    /**
     * Build abbreviation findings for the naming rule.
     *
     * @param list<string> $acceptedAbbreviations - Accepted abbreviation values to apply before analysing the fixture.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Abbreviation findings under the supplied allowlist; empty when none remain.
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
