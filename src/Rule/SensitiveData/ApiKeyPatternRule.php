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
     * @return list<array{name: string, pattern: string}>
     */
    private function patterns(): array
    {
        return [
            ['name' => 'stripe', 'pattern' => '/\bsk_live_[A-Za-z0-9]{16,}\b/'],
            ['name' => 'github', 'pattern' => '/\bghp_[A-Za-z0-9]{36}\b/'],
            ['name' => 'openai', 'pattern' => '/\bsk-proj-[A-Za-z0-9_-]{20,}\b/'],
            ['name' => 'anthropic', 'pattern' => '/\bsk-ant-[A-Za-z0-9_-]{20,}\b/'],
            ['name' => 'slack', 'pattern' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/'],
        ];
    }

    /**
     * Describe the API key pattern sensitive-data rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> Findings for API key-like literals.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Fast bail: real API keys for the supported providers all contain one
        // of these distinctive prefixes. Skipping the per-pattern regex when
        // none are present makes this rule near-free for the common case.
        if (preg_match('/sk_live_|ghp_|sk-proj-|sk-ant-|xox[baprs]-/i', $analysisUnit->source) !== 1) {
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
                    analysisUnit:        $analysisUnit,
                    ruleId:      self::ID,
                    message:     sprintf('Potential %s API key detected: %s.', $definition['name'], $preview),
                    line:        SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                    confidence:  Confidence::High,
                    detector:    $definition['name'],
                    preview:     $preview,
                    remediation: 'Remove committed API keys and rotate the credential if it was real.',
                );
            }
        }

        return $findings;
    }
}
