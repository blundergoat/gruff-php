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
     * @return list<array{name: string, pattern: string}> - each entry pairs a provider label with its detection regex; ordering is the scan order
     *                          applied per source unit
     */
    private function patterns(): array
    {
        // Each pattern anchors on a provider's published key prefix and minimum length, keeping false positives low.
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
     * @return RuleDefinition - identity, pillar, tier, and the Warning/High defaults the engine applies unless a config override raises or silences
     *                        this rule
     */
    public function definition(): RuleDefinition
    {
        // High confidence: provider prefixes are distinctive enough that a non-dummy match is very likely a real key.
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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - one finding per non-dummy key-like literal outside comments; empty means clean (no prefix hit, or
     *                                         every match was a comment or dummy value), not an error
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Fast bail: real API keys for the supported providers all contain one
        // of these distinctive prefixes. Skipping the per-pattern regex when
        // none are present makes this rule near-free for the common case.
        if (preg_match('/sk_live_|ghp_|github_pat_|gh[ours]_|sk-proj-|sk-ant-|xox[baprs]-|hooks\.slack\.com\/services|npm_|AIza|[?&]sv=|glpat-/i', $analysisUnit->source) !== 1) {
            // No supported prefix in the source means no provider pattern can match, so skip the per-pattern scan.
            return [];
        }

        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);

        // Run each provider's key pattern over the source.
        foreach ($this->patterns() as $definition) {
            // Match every occurrence of this provider's key shape, capturing each offset.
            preg_match_all($definition['pattern'], $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

            // Weigh each candidate this pattern found.
            foreach ($matches[0] as $match) {
                [$candidateSecret, $offset] = $match;
                // A key inside a comment is an example, not a live secret.
                if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                    continue;
                }

                // An obvious placeholder key is not a real secret.
                if (SecretScannerHelper::isLikelyDummyValue($candidateSecret)) {
                    continue;
                }

                $preview    = SecretScannerHelper::redactedPreview($candidateSecret);
                $findings[] = SecretScannerHelper::finding(
                    analysisUnit: $analysisUnit,
                    ruleId:       self::ID,
                    message:      sprintf('Potential %s API key detected: %s.', $definition['name'], $preview),
                    line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                    confidence:   Confidence::High,
                    detector:     $definition['name'],
                    preview:      $preview,
                    remediation:  'Remove committed API keys and rotate the credential if it was real.',
                );
            }
        }

        return $findings;
    }
}
