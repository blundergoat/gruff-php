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
 * Detects hardcoded values assigned to environment-style keys.
 */
final readonly class HardcodedEnvValueRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for hardcoded environment value findings.
     */
    public const ID = 'sensitive-data.hardcoded-env-value';

    /**
     * Describe the hardcoded environment value rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Hardcoded environment value',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find env-style assignments that look like committed secrets.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> Findings for suspicious env-style values.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (SecretScannerHelper::isEnvFile($analysisUnit->file->displayPath)) {
            return [];
        }

        // Fast bail: the regex only matches keys containing one of these
        // tokens. Skipping the expensive alternation when no token appears
        // makes the rule near-free for the common case.
        if (preg_match('/(?:API_KEY|PASSWORD|PASS|SECRET|TOKEN|PRIVATE_KEY)/', $analysisUnit->source) !== 1) {
            return [];
        }

        preg_match_all(
            '/\b(?<key>[A-Z0-9_]*(?:API_KEY|PASSWORD|PASS|SECRET|TOKEN|PRIVATE_KEY)[A-Z0-9_]*)\s*=\s*["\']?(?<value>[A-Za-z0-9_+\/=.-]{8,})["\']?/',
            $analysisUnit->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        foreach ($matches[0] as $index => $match) {
            $key        = $matches['key'][$index][0];
            $secretValue = $matches['value'][$index][0];
            $offset     = $match[1];
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            if (SecretScannerHelper::isLikelyDummyValue($secretValue) || !$this->hasSecretValueEvidence($key, $secretValue)) {
                continue;
            }

            $preview    = SecretScannerHelper::redactedKeyValue($key, $secretValue);
            $findings[] = SecretScannerHelper::finding(
                analysisUnit:        $analysisUnit,
                ruleId:      self::ID,
                message:     sprintf('Hardcoded env-style secret value detected: %s.', $preview),
                line:        SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:  Confidence::Medium,
                detector:    'env-style-secret',
                preview:     $preview,
                remediation: 'Move env-style secret values out of committed source files.',
            );
        }

        return $findings;
    }

    /**
     * Check whether a key/value pair has enough evidence to be treated as secret-like.
     *
     * @return bool True when the value shape is strong enough for the key.
     */
    private function hasSecretValueEvidence(string $key, string $secretValue): bool
    {
        $normalizedValue = trim($secretValue, "\"' \t\r\n");
        $upperKey        = strtoupper($key);
        $strongShape     = strlen($normalizedValue) >= 20 && SecretScannerHelper::entropy($normalizedValue) >= 3.5;

        if ($this->isConservativeKeySuffix($upperKey) && !$strongShape) {
            return false;
        }

        if ($this->isCommonNonSecretValue($normalizedValue)) {
            return false;
        }

        return $strongShape;
    }

    /**
     * Detect key suffixes that need stronger value evidence.
     *
     * @return bool True when the key suffix is commonly non-secret.
     */
    private function isConservativeKeySuffix(string $key): bool
    {
        foreach (['_NAME', '_PREFIX', '_ID', '_MODE'] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect short identifier-like values that are usually not secrets.
     *
     * @return bool True when the value looks like a common non-secret token.
     */
    private function isCommonNonSecretValue(string $secretValue): bool
    {
        // Treat short lowercase kebab-case literals as ordinary labels, not secrets.
        if (preg_match('/^[a-z][a-z0-9-]{1,24}$/', $secretValue) === 1) {
            return true;
        }

        // Treat short lowercase snake-case literals as ordinary labels, not secrets.
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $secretValue) === 1) {
            return true;
        }

        // Treat dotted or dashed values ending in punctuation as path-ish or prefix-ish tokens.
        if (preg_match('/^[a-z][a-z0-9_.-]+[._-]$/', $secretValue) === 1) {
            return true;
        }

        return false;
    }
}
