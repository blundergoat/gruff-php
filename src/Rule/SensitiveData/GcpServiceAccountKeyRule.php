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
 * Detects committed GCP service-account key JSON.
 *
 * A service-account key is the JSON object Google issues with
 * `"type": "service_account"` and an embedded RSA `private_key`. The finding is
 * anchored at the `"type": "service_account"` marker so it stays distinct from
 * the generic `sensitive-data.private-key` PEM-header finding (the two are
 * complementary, never the same line). Placeholder keys are skipped.
 */
final readonly class GcpServiceAccountKeyRule implements SourceTextRuleInterface
{
    /** Stable rule identifier for GCP service-account-key findings. */
    public const ID = 'sensitive-data.gcp-service-account-key';

    /**
     * Describe the GCP service-account-key rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Service-account keys are high-confidence secrets when the type marker and key body align.
        return new RuleDefinition(
            id:              self::ID,
            name:            'GCP service-account key',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
            description:     'Committed Google Cloud service-account key JSON (type: service_account with an embedded private key).',
        );
    }

    /**
     * Find service-account key JSON that embeds a private key.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> Findings for GCP service-account keys.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $source = $analysisUnit->source;

        // Fast bail: both the service-account marker and a private key must be present.
        if (!str_contains($source, 'service_account') || !$this->hasPrivateKeyBody($source)) {
            // Without both markers, the generic private-key rule owns any remaining PEM evidence.
            return [];
        }

        $privateKeyValue = $this->privateKeyValue($source);
        if ($this->looksLikePlaceholderKey($privateKeyValue, $source)) {
            // Placeholder fixtures intentionally carry key-shaped fields without real key material.
            return [];
        }

        // Match the service-account marker that anchors this rule's distinct finding.
        if (preg_match_all('/"type"\s*:\s*"service_account"/', $source, $matches, PREG_OFFSET_CAPTURE) < 1) {
            // The initial substring check was insufficient; no concrete JSON marker was found.
            return [];
        }

        $preview       = $privateKeyValue !== null
            ? SecretScannerHelper::redactedPreview($privateKeyValue)
            : '<redacted GCP service-account key>';
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);

        $findings = [];
        foreach ($matches[0] as $match) {
            [, $offset] = $match;
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('GCP service-account key detected (type: service_account with embedded private key): %s.', $preview),
                line:         SecretScannerHelper::lineNumberForOffset($source, $offset),
                confidence:   Confidence::High,
                detector:     'gcp-service-account-key',
                preview:      $preview,
                remediation:  'Remove the service-account key from source, rotate it in Google Cloud IAM, and load credentials from a secret manager or workload identity instead.',
            );
        }

        // Findings stay anchored at the service-account marker, not the private key body.
        return $findings;
    }

    /**
     * Decide whether the source carries a private-key body.
     *
     * @param string $source Raw source text.
     * @return bool True when a PEM private-key block or a JSON private_key field is present.
     */
    private function hasPrivateKeyBody(string $source): bool
    {
        // Match JSON private_key fields as key-body evidence alongside PEM armor.
        $hasJsonPrivateKeyField = preg_match('/"private_key"\s*:\s*"/', $source) === 1;

        // Accept either PEM armor or a JSON private_key field as key-body evidence.
        return str_contains($source, 'BEGIN PRIVATE KEY')
            || str_contains($source, 'BEGIN RSA PRIVATE KEY')
            || $hasJsonPrivateKeyField;
    }

    /**
     * Decide whether the embedded key is a placeholder rather than real material.
     *
     * Real private-key bodies are long base64 blobs; placeholders are short
     * marker phrases. A length test on the armor-stripped body separates them
     * without the substring false-matches that a generic dummy check hits on
     * base64.
     *
     * @param string|null $privateKeyValue Extracted JSON private_key value, if any.
     * @param string      $source          Raw source text (PEM fallback).
     * @return bool True when the key body is too short to be real key material.
     */
    private function looksLikePlaceholderKey(?string $privateKeyValue, string $source): bool
    {
        $keyText = $privateKeyValue ?? $this->pemBlock($source);
        if ($keyText === null) {
            // No extractable key body means there is no placeholder proof.
            return false;
        }

        $withoutArmor = preg_replace('/-----[^-]*-----/', '', $keyText) ?? $keyText;
        $body         = preg_replace('#[^A-Za-z0-9+/=]#', '', $withoutArmor) ?? '';

        // Real private keys have long armor-stripped bodies; short bodies are placeholders.
        return strlen($body) < 100;
    }

    /**
     * Extract the first PEM private-key block from source text.
     *
     * @param string $source Raw source text.
     * @return string|null The PEM block, or null when none is present.
     */
    private function pemBlock(string $source): ?string
    {
        // Match one PEM private-key block for placeholder-length inspection.
        if (preg_match('/-----BEGIN[^-]*PRIVATE KEY-----.*?-----END[^-]*PRIVATE KEY-----/s', $source, $matches) === 1) {
            // The full PEM block is needed so armor can be stripped consistently.
            return $matches[0];
        }

        // JSON-only service-account files do not carry standalone PEM blocks.
        return null;
    }

    /**
     * Extract the JSON private_key field value when present.
     *
     * @param string $source Raw source text.
     * @return string|null The (still-escaped) private_key value, or null when absent.
     */
    private function privateKeyValue(string $source): ?string
    {
        // Capture the JSON string body without terminating on escaped quote characters.
        if (preg_match('/"private_key"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $source, $matches) === 1) {
            // The escaped value is enough for redacted preview and placeholder checks.
            return $matches[1];
        }

        // PEM-only evidence has no JSON private_key field to preview.
        return null;
    }
}
