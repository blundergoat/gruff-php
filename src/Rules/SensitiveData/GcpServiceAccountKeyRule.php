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
 * Flags potential GCP service-account key JSON so callers can remove and rotate committed credentials.
 *
 * Findings are anchored at `"type": "service_account"` and complement the generic private-key rule.
 * Both rules can report on the same line when the JSON is compact.
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
     * Reports source matching the GCP service-account-key heuristic, anchored at the service-account marker.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - GCP service-account findings anchored at the service-account marker.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $source = $analysisUnit->source;

        // This specialised rule needs service-account text plus private-key field or armor evidence.
        if (!str_contains($source, 'service_account') || !$this->hasPrivateKeyBody($source)) {
            // Without both signals, this specialised rule leaves PEM detection to the generic private-key rule.
            return [];
        }

        $privateKeyValue = $this->privateKeyValue($source);
        // A short extracted body is treated as placeholder text rather than reportable key material.
        if ($this->looksLikePlaceholderKey($privateKeyValue, $source)) {
            return [];
        }

        // Match the service-account marker that anchors this rule's distinct finding.
        if (preg_match_all('/"type"\s*:\s*"service_account"/', $source, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $displayMarker = SecretScannerHelper::categoryMarker('gcp-service-account');
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);

        $findings = [];
        // Emit one finding per un-commented service-account marker, with column coordinates so compact JSON files keep every match.
        foreach ($matches[0] as $match) {
            [, $offset] = $match;
            // Skip markers inside comments as documentation examples; a real key left in a comment is not reported.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            $findings[] = SecretScannerHelper::findingAtOffset(
                analysisUnit:  $analysisUnit,
                ruleId:        self::ID,
                message:       sprintf('GCP service-account key detected (type: service_account with embedded private key): %s.', $displayMarker),
                offset:        $offset,
                confidence:    Confidence::High,
                detector:      'gcp-service-account-key',
                displayMarker: $displayMarker,
                remediation:   'Remove the service-account key from source, rotate it in Google Cloud IAM, and load credentials from a secret manager or workload identity instead.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether source contains private-key body evidence: PEM armor text or a JSON private_key field opener.
     *
     * @param string $source - Raw source text searched for private-key field or armor evidence.
     *
     * @return bool - True when PEM armor text or a JSON private_key field opener is present.
     */
    private function hasPrivateKeyBody(string $source): bool
    {
        // Match JSON private_key fields as key-body evidence alongside PEM armor.
        $hasJsonPrivateKeyField = preg_match('/"private_key"\s*:\s*"/', $source) === 1;

        return str_contains($source, 'BEGIN PRIVATE KEY')
            || str_contains($source, 'BEGIN RSA PRIVATE KEY')
            || $hasJsonPrivateKeyField;
    }

    /**
     * Reports whether the embedded key body is short enough for this rule to treat as a placeholder.
     * Armor-stripped length separates short marker phrases from bodies long enough to retain for reporting.
     *
     * @param string|null $privateKeyValue - Extracted JSON private_key value; null means use the PEM block from source instead.
     * @param string      $source - Raw source text (PEM fallback).
     *
     * @return bool - True for a short placeholder; false when material looks real or neither JSON nor PEM yields a body
     */
    private function looksLikePlaceholderKey(?string $privateKeyValue, string $source): bool
    {
        $keyText = $privateKeyValue ?? $this->pemBlock($source);
        // No extractable key body means this heuristic has no placeholder evidence and keeps the match reportable.
        if ($keyText === null) {
            return false;
        }

        // Normalize armor-stripped text to base64-alphabet characters for the placeholder length check.
        $withoutArmor = preg_replace('/-----[^-]*-----/', '', $keyText) ?? $keyText;
        $body         = preg_replace('#[^A-Za-z0-9+/=]#', '', $withoutArmor) ?? '';

        // The rule treats normalized bodies shorter than 100 characters as placeholders.
        return strlen($body) < 100;
    }

    /**
     * Extracts the first PEM private-key block from the source, or null when none is present.
     *
     * @param string $source - Raw source text.
     *
     * @return string|null - First complete matching PEM block; null when no complete block matches.
     */
    private function pemBlock(string $source): ?string
    {
        // Match one PEM private-key block for placeholder-length inspection.
        if (preg_match('/-----BEGIN[^-]*PRIVATE KEY-----.*?-----END[^-]*PRIVATE KEY-----/s', $source, $matches) === 1) {
            // The full PEM block is needed so armor can be stripped consistently.
            return $matches[0];
        }


        return null;
    }

    /**
     * Extracts the first complete JSON-style private_key string without decoding escapes.
     *
     * @param string $source - Raw source text searched for the first complete private-key value.
     *
     * @return string|null - Captured contents, possibly empty and still escaped; null when no complete value matches.
     */
    private function privateKeyValue(string $source): ?string
    {
        // Capture the JSON string body without terminating on escaped quote characters.
        if (preg_match('/"private_key"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $source, $matches) === 1) {
            // The escaped value is used only for placeholder checks and never reaches the user's finding.
            return $matches[1];
        }


        return null;
    }
}
