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
 * Flags committed GCP service-account key JSON, so the user can remove it, rotate the key in IAM, and load
 * credentials from a secret manager instead.
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
     * Describes the GCP service-account-key rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, high confidence).
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
     * Reports committed GCP service-account key JSON, anchored at the service-account marker.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - GCP service-account findings anchored at the service-account marker.
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

        $displayMarker = SecretScannerHelper::categoryMarker('gcp-service-account');
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);

        $findings = [];
        // Report each service-account marker found outside a comment.
        foreach ($matches[0] as $match) {
            [, $offset] = $match;
            // A marker inside a comment is an example, not a committed key.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('GCP service-account key detected (type: service_account with embedded private key): %s.', $displayMarker),
                line:         SecretScannerHelper::lineNumberForOffset($source, $offset),
                confidence:   Confidence::High,
                detector:     'gcp-service-account-key',
                displayMarker: $displayMarker,
                remediation:  'Remove the service-account key from source, rotate it in Google Cloud IAM, and load credentials from a secret manager or workload identity instead.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether the source carries a private-key body (PEM armor or a JSON private_key field).
     *
     * @param string $source - Raw source text.
     *
     * @return bool - True when a PEM private-key block or a JSON private_key field is present.
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
     * Reports whether the embedded key is a short placeholder rather than material the user must remove.
     * Armor-stripped length distinguishes marker phrases from real base64 bodies without substring false matches.
     *
     * @param string|null $privateKeyValue - Extracted JSON private_key value; null means use the PEM block from source instead.
     * @param string      $source - Raw source text (PEM fallback).
     *
     * @return bool - True for a short placeholder; false when material looks real or neither JSON nor PEM yields a body
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
     * Extracts the first PEM private-key block from the source, or null when none is present.
     *
     * @param string $source - Raw source text.
     *
     * @return string|null - PEM block used for the placeholder check; null means the user supplied no standalone PEM body
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
     * Extracts the JSON private_key field value when present, or null when absent.
     *
     * @param string $source - Raw source text.
     *
     * @return string|null - Still-escaped value used only for placeholder checks; null means callers fall back to PEM evidence
     */
    private function privateKeyValue(string $source): ?string
    {
        // Capture the JSON string body without terminating on escaped quote characters.
        if (preg_match('/"private_key"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $source, $matches) === 1) {
            // The escaped value is used only for placeholder checks and never reaches the user's finding.
            return $matches[1];
        }

        // PEM-only evidence has no JSON private_key value to inspect, so the placeholder check receives null.
        return null;
    }
}
