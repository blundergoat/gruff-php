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
     * Verify the shipped seed is exactly the ratified sixteen, so domain vocabulary stays a project decision.
     *
     * `dto` and `utc` were seeded here until 2026-09-06. FAMILY-CONTRACT.md section 8 ratifies sixteen entries and
     * replace-not-merge loading, so two extra defaults meant a gruff-php project silently accepted two names no
     * other port did, and a user who listed their own vocabulary lost them without being told.
     *
     * @return void
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

        // `id` is one of the sixteen and stays quiet; the three that are not are reported until a project lists them.
        self::assertSame(['dob', 'dto', 'utc'], $names);
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

        self::assertContains(IdentifierQualityRule::ID, $rules['strX'] ?? []);
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
