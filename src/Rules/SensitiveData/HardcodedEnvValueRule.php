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
 * Flags a hardcoded value assigned to an environment-style key (`API_KEY`, `PASSWORD`, `SECRET`, `TOKEN`,
 * `PRIVATE_KEY`, ...), so the user can move committed secrets into real environment configuration.
 *
 * A source-text rule that skips `.env` files (their sanctioned home) and comments, and applies several
 * value-shape heuristics - length, entropy, and label/identifier exclusions - so config labels and cache
 * keys do not read as secrets. The reported value is redacted. Warning severity, medium confidence.
 */
final readonly class HardcodedEnvValueRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for hardcoded environment value findings.
     */
    public const ID = 'sensitive-data.hardcoded-env-value';

    /**
     * Describes the hardcoded-environment-value rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, medium confidence).
     */
    public function definition(): RuleDefinition
    {
        // Registry reads this to wire the rule in; Medium confidence reflects the heuristic key/value matching below.
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
     * Reports each env-style assignment whose value looks like a committed secret, redacting it.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for suspicious env-style values.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (SecretScannerHelper::isEnvFile($analysisUnit->file->displayPath)) {
            // A .env file is the sanctioned home for these values, so assignments there are not committed-secret leaks.
            return [];
        }

        // Fast bail: the regex only matches keys containing one of these
        // tokens. Skipping the expensive alternation when no token appears
        // makes the rule near-free for the common case.
        if (preg_match('/(?:API_KEY|PASSWORD|PASS|SECRET|TOKEN|PRIVATE_KEY)/', $analysisUnit->source) !== 1) {
            // Without any secret-like key token in the source, the expensive assignment scan cannot match.
            return [];
        }

        // Match every secret-like key=value assignment, capturing the key, value, and offset.
        preg_match_all(
            '/\b(?<key>[A-Z0-9_]*(?:API_KEY|PASSWORD|PASS|SECRET|TOKEN|PRIVATE_KEY)[A-Z0-9_]*)\s*=\s*["\']?(?<value>[A-Za-z0-9_+\/=.-]{8,})["\']?/',
            $analysisUnit->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        // Weigh each assignment the scan found.
        foreach ($matches[0] as $index => $match) {
            $key         = $matches['key'][$index][0];
            $secretValue = $matches['value'][$index][0];
            $offset      = $match[1];
            // An assignment inside a comment is an example, not a live secret.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            // Skip obvious dummy values and values too weak in shape to be a real secret.
            if (SecretScannerHelper::isLikelyDummyValue($secretValue) || !$this->hasSecretValueEvidence($key, $secretValue)) {
                continue;
            }

            $preview    = SecretScannerHelper::redactedKeyValue($key, $secretValue);
            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('Hardcoded env-style secret value detected: %s.', $preview),
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::Medium,
                detector:     'env-style-secret',
                preview:      $preview,
                remediation:  'Move env-style secret values out of committed source files.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether a key/value pair has enough evidence to be treated as secret-like.
     *
     * @param string $key - Matched env-style key, e.g. DB_PASSWORD; suffix sets the value-evidence bar.
     * @param string $secretValue - Raw matched value, quotes and whitespace included; trimmed and entropy-scored here.
     *
     * @return bool - True when the value shape is strong enough for the key.
     */
    private function hasSecretValueEvidence(string $key, string $secretValue): bool
    {
        $normalizedValue = trim($secretValue, "\"' \t\r\n");
        $upperKey        = strtoupper($key);
        $strongShape     = strlen($normalizedValue) >= 20 && SecretScannerHelper::entropy($normalizedValue) >= 3.5;

        if ($this->isConservativeKeySuffix($upperKey) && !$strongShape) {
            // Suffixes like _NAME alone are weak signals, so a non-strong value under them is not secret-worthy.
            return false;
        }

        if ($this->isCommonNonSecretValue($normalizedValue)) {
            // Plain kebab/snake labels are config values, not credentials, even under a secret-sounding key.
            return false;
        }

        if ($this->isIdentifierLikeNonSecretValue($upperKey, $normalizedValue)) {
            // Cache keys and external field identifiers borrow secret words but carry no secret material.
            return false;
        }

        // Survives every exclusion only when length and entropy together cross the secret threshold.
        return $strongShape;
    }

    /**
     * Reports whether a key suffix (_NAME/_PREFIX/_ID/_MODE) needs stronger value evidence.
     *
     * @param string $key - Upper-cased env-style key to test against the conservative suffix list.
     *
     * @return bool - True when the key suffix is commonly non-secret.
     */
    private function isConservativeKeySuffix(string $key): bool
    {
        // Check the key against the conservative, usually-label suffixes.
        foreach (['_NAME', '_PREFIX', '_ID', '_MODE'] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                // A descriptive suffix like _NAME means the value is usually a label, so flag it as conservative.
                return true;
            }
        }

        // No conservative suffix matched, so the key carries no extra burden of proof.
        return false;
    }

    /**
     * Reports whether a value is a plain label (kebab/snake/path fragment) rather than a secret.
     *
     * @param string $secretValue - Already-normalized value to classify as a plain label rather than a credential.
     *
     * @return bool - True when the value looks like a common non-secret token.
     */
    private function isCommonNonSecretValue(string $secretValue): bool
    {
        // Treat short lowercase kebab-case literals as ordinary labels, not secrets.
        if (preg_match('/^[a-z][a-z0-9-]{1,24}$/', $secretValue) === 1) {
            // A bare kebab-case word is a config label, so exclude it from secret detection.
            return true;
        }

        // Treat short lowercase snake-case literals as ordinary labels, not secrets.
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $secretValue) === 1) {
            // A bare snake-case word is a config label, so exclude it from secret detection.
            return true;
        }

        // Treat dotted or dashed values ending in punctuation as path-ish or prefix-ish tokens.
        if (preg_match('/^[a-z][a-z0-9_.-]+[._-]$/', $secretValue) === 1) {
            // A trailing separator marks a path or prefix fragment, not a complete secret.
            return true;
        }

        // Nothing matched the label shapes, so the value may still be a real secret.
        return false;
    }

    /**
     * Reports whether a key/value is an identifier (cache key, field name, duration), not a credential.
     *
     * @param string $key - Upper-cased env-style key whose suffix steers which identifier shapes are allowed.
     * @param string $secretValue - Already-normalized value; tested for identifier shape rather than secret shape.
     *
     * @return bool - True when the key/value shape is identifier-like instead of credential-like.
     */
    private function isIdentifierLikeNonSecretValue(string $key, string $secretValue): bool
    {
        // Match digits or secret-token punctuation that indicate value material, not a label.
        if (preg_match('/\\d|[+\\/=]/', $secretValue) === 1) {
            // Value material disqualifies the identifier exemption; let the secret check proceed.
            return false;
        }

        if (str_ends_with($key, '_EXPIRES_AT') || str_ends_with($key, '_VALID_PERIOD')) {
            // Expiry and validity keys name durations, so their values are never the secret itself.
            return true;
        }

        // Match common identifier characters used by cache keys and external field names.
        if (str_ends_with($key, '_KEY') && preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{1,80}$/', $secretValue) === 1) {
            // A _KEY holding an identifier string is a cache or lookup key, not credential material.
            return true;
        }

        // Match camelCase identifiers such as accessTokenExpiresAt.
        // A camelCase field name is an identifier, so its truthiness decides the identifier-like verdict.
        return preg_match('/^[a-z][A-Za-z]{7,40}$/', $secretValue) === 1;
    }
}
