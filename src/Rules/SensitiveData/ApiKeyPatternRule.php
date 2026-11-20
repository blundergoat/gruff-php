<?php

declare(strict_types=1);

namespace GruffPhp\Rules\SensitiveData;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\SourceTextRuleInterface;

/**
 * Flags a hardcoded API key from a known provider (Stripe, GitHub, OpenAI, Anthropic, Slack, npm, Google,
 * Azure, GitLab), so the user can pull the committed credential from source and rotate it.
 *
 * A source-text rule: it runs each provider's prefix pattern over the raw file, skipping matches inside
 * comments and obvious dummy values, and redacts every reported key. Warning severity, high confidence -
 * the published provider prefixes make a non-dummy match very likely a real key.
 */
final readonly class ApiKeyPatternRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for API key pattern findings.
     */
    public const ID = 'sensitive-data.api-key-pattern';

    /**
     * Lists the provider key patterns this rule scans for, in scan order.
     *
     * @return list<array{name: string, pattern: string}> - Provider labels and detection regexes in scan order.
     */
    private function patterns(): array
    {
        // Provider-specific prefixes and structures narrow candidates before comment and dummy-value filtering.
        return [
            ['name' => 'stripe', 'pattern' => '/\bsk_live_[A-Za-z0-9]{16,}\b/'],
            ['name' => 'github', 'pattern' => '/\bghp_[A-Za-z0-9]{36}\b/'],
            ['name' => 'github-fine-grained', 'pattern' => '/\bgithub_pat_[A-Za-z0-9_]{22,}\b/'],
            ['name' => 'github-oauth', 'pattern' => '/\bgh[ours]_[A-Za-z0-9_]{36}\b/'],
            ['name' => 'openai', 'pattern' => '/\bsk-proj-[A-Za-z0-9_-]{20,}\b/'],
            ['name' => 'anthropic', 'pattern' => '/\bsk-ant-[A-Za-z0-9_-]{20,}\b/'],
            ['name' => 'slack', 'pattern' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/'],
            ['name' => 'slack-webhook', 'pattern' => '#https://hooks\.slack\.com/services/[A-Z0-9]{8,}/[A-Z0-9]{8,}/[A-Za-z0-9]{20,}#'],
            ['name' => 'npm', 'pattern' => '/\bnpm_[A-Za-z0-9]{20,}\b/'],
            ['name' => 'google-api-key', 'pattern' => '/\bAIza[A-Za-z0-9_-]{35}\b/'],
            ['name' => 'azure-sas', 'pattern' => '#\?(?=[^\s\'"]*\bsv=)(?=[^\s\'"]*\bsig=)[^\s\'"]{40,}#i'],
            ['name' => 'gitlab', 'pattern' => '/\bglpat-[A-Za-z0-9_-]{20,}\b/'],
        ];
    }

    /**
     * Describes the API-key-pattern sensitive-data rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata with warning severity and high confidence.
     */
    public function definition(): RuleDefinition
    {
        // High confidence: retained matches satisfy a provider-specific structure and pass comment and dummy-value filters.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Common API key pattern',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports each string that resembles a hardcoded provider API key, redacting the value.
     *
     * @param AnalysisUnit $analysisUnit - Supplies raw source and comment tokens for scanning.
     * @param RuleContext $ruleContext - Required by the rule interface; unused by this scanner.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - One finding per retained pattern match; empty when none survive.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Supported key shapes contain one of the screened tokens, so source without a token cannot match.
        if (preg_match('/sk_live_|ghp_|github_pat_|gh[ours]_|sk-proj-|sk-ant-|xox[baprs]-|hooks\.slack\.com\/services|npm_|AIza|[?&]sv=|glpat-/i', $analysisUnit->source) !== 1) {

            return [];
        }

        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);

        foreach ($this->patterns() as $providerPattern) {
            // Candidate offsets allow comment filtering and source-line reporting before a redacted finding is emitted.
            preg_match_all($providerPattern['pattern'], $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);


            foreach ($matches[0] as $match) {
                [$candidateSecret, $offset] = $match;

                if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                    continue;
                }


                if (SecretScannerHelper::isLikelyDummyValue($candidateSecret)) {
                    continue;
                }

                $displayMarker = SecretScannerHelper::categoryMarker(self::markerCategory($providerPattern['name']));
                $findings[] = SecretScannerHelper::finding(
                    analysisUnit: $analysisUnit,
                    ruleId:       self::ID,
                    message:      sprintf('Potential %s API key detected: %s.', $providerPattern['name'], $displayMarker),
                    line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                    confidence:   Confidence::High,
                    detector:     $providerPattern['name'],
                    displayMarker: $displayMarker,
                    remediation:  'Remove committed API keys and rotate the credential if it was real.',
                );
            }
        }

        return $findings;
    }

    /**
     * Maps one provider pattern name to its ratified family marker category.
     * An unratified provider returns null rather than inventing a marker category.
     *
     * @param string $providerName - Name of the pattern that matched, as patterns() declares it.
     *
     * @return string|null - the ratified category, or null when this provider has none
     */
    private static function markerCategory(string $providerName): ?string
    {
        return match ($providerName) {
            'stripe' => 'stripe-live-key',
            'github', 'github-fine-grained', 'github-oauth' => 'github-token',
            'anthropic' => 'anthropic-api-key',
            'slack', 'slack-webhook' => 'slack-token',
            'npm' => 'npm-token',
            'google-api-key' => 'google-api-key',
            'gitlab' => 'gitlab-token',
            default => null,
        };
    }
}
