<?php

declare(strict_types=1);

namespace GruffPhp\Rule\SensitiveData;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\SourceTextRuleInterface;

/**
 * Detects common API key assignment patterns in source text.
 */
final readonly class ApiKeyPatternRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for API key pattern findings.
     */
    public const ID = 'sensitive-data.api-key-pattern';

    /**
     * List the regex patterns enforced by this rule.
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
     * Describe the API key pattern sensitive-data rule.
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
     * Find string literals that resemble hardcoded API keys.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> - one finding per non-dummy key-like literal outside comments; empty means clean (no prefix hit, or
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

        foreach ($this->patterns() as $definition) {
            preg_match_all($definition['pattern'], $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                [$candidateSecret, $offset] = $match;
                if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                    continue;
                }

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

        // Empty when every match sat in a comment or looked like a dummy value; the caller treats that as a clean file.
        return $findings;
    }
}
