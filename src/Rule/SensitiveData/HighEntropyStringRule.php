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
 * Detects high-entropy string literals that may be embedded secrets.
 */
final readonly class HighEntropyStringRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for high-entropy string findings.
     */
    public const ID = 'sensitive-data.high-entropy-string';

    /**
     * Describe the high entropy string rule.
     *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        // Warning at medium confidence: entropy catches real secrets but also rule-path noise,
        // so this advises rather than blocks.
        return new RuleDefinition(
            id:                self::ID,
            name:              'High entropy string',
            pillar:            Pillar::SensitiveData,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Warning,
            confidence:        Confidence::Medium,
            defaultThresholds: [
                'minLength' => 32,
                'entropy' => 4.2,
            ],
        );
    }

    /**
     * Find long high-entropy string literals that may be secrets.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> - Findings for suspicious high-entropy literals.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $settings         = $ruleContext->settingsFor($this->definition());
        $minLength        = (int) $settings->numericThreshold('minLength');
        $entropyThreshold = (float) $settings->numericThreshold('entropy');

        preg_match_all('/["\'](?<value>[A-Za-z0-9_+\/=.-]{32,})["\']/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        foreach ($matches['value'] as $match) {
            [$candidateSecret, $offset] = $match;
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            if (strlen($candidateSecret) < $minLength) {
                continue;
            }

            if (
                $this->shouldSkipKnownSecretPattern($candidateSecret)
                || $this->isPathLikeLiteral($candidateSecret)
                || $this->isGruffConfigPathLiteral($candidateSecret)
                || SecretScannerHelper::isLikelyDummyValue($candidateSecret)
            ) {
                continue;
            }

            $line = $this->lineText($analysisUnit->source, SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset));
            if ($this->isMedicalStandardsMetadata($candidateSecret, $line)) {
                continue;
            }

            $entropy = SecretScannerHelper::entropy($candidateSecret);
            if ($entropy < $entropyThreshold && !(strlen($candidateSecret) >= 64 && ctype_xdigit($candidateSecret))) {
                continue;
            }

            $preview    = SecretScannerHelper::redactedPreview($candidateSecret);
            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('High-entropy string literal detected: %s.', $preview),
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::Medium,
                detector:     'high-entropy-string',
                preview:      $preview,
                remediation:  'Confirm this is not a credential; move real secrets out of source.',
            );
        }

        return $findings;
    }

    /**
     * Defer known secret formats to more specific detectors.
     *
     * @param string $candidateSecret - Literal under test; a known vendor prefix or token shape means a dedicated
     *                                rule owns it.
     *
     * @return bool - True when another rule should handle the literal.
     */
    private function shouldSkipKnownSecretPattern(string $candidateSecret): bool
    {
        // Skip literals a more specific detector already covers, so the same secret is not double-reported here.
        return str_starts_with($candidateSecret, 'AKIA')
            || str_starts_with($candidateSecret, 'ASIA')
            || str_starts_with($candidateSecret, 'sk_live_')
            || str_starts_with($candidateSecret, 'sk-proj-')
            || str_starts_with($candidateSecret, 'sk-ant-')
            || str_starts_with($candidateSecret, 'ghp_')
            || str_starts_with($candidateSecret, 'gho_')
            || str_starts_with($candidateSecret, 'ghr_')
            || str_starts_with($candidateSecret, 'ghs_')
            || str_starts_with($candidateSecret, 'ghu_')
            || str_starts_with($candidateSecret, 'github_pat_')
            || str_starts_with($candidateSecret, 'glpat-')
            || str_starts_with($candidateSecret, 'npm_')
            || str_starts_with($candidateSecret, 'AIza')
            || str_starts_with($candidateSecret, 'xox')
            || str_starts_with($candidateSecret, 'https://hooks.slack.com/services/')
            || substr_count($candidateSecret, '.') === 2
            || (strlen($candidateSecret) <= 48 && ctype_alpha($candidateSecret));
    }

    /**
     * Detect path-like literals that should not be treated as secrets.
     *
     * @param string $candidateSecret - Literal under test; file paths and route URLs trip the length heuristic but
     *                                hold no secret.
     *
     * @return bool - True when the literal looks like a file path.
     */
    private function isPathLikeLiteral(string $candidateSecret): bool
    {
        if (!str_contains($candidateSecret, '/') && !str_contains($candidateSecret, '\\')) {
            // No directory separator at all means it cannot be a path, so it stays eligible as a secret.
            return false;
        }

        if ($this->isUrlOrRoutePathLiteral($candidateSecret)) {
            // A URL or route path is benign even without a file extension, so exempt it before the extension check.
            return true;
        }

        // Recognize common source/config/documentation file extensions in path-like literals.
        return preg_match('/\\.(?:php|inc|json|xml|neon|ya?ml|txt|md|stub)$/i', $candidateSecret) === 1;
    }

    /**
     * Detect URL and route literals that are long because of slugs or numeric IDs, not secret material.
     *
     * @param string $candidateSecret - Literal under test; a long public URL or route path otherwise reads as entropy.
     *
     * @return bool - True when the literal is shaped like a public URL path.
     */
    private function isUrlOrRoutePathLiteral(string $candidateSecret): bool
    {
        if (str_starts_with($candidateSecret, 'https://hooks.slack.com/services/')) {
            // Slack webhook URLs are genuine secrets despite their URL shape, so never exempt them as routes.
            return false;
        }

        // Match URI schemes so absolute URLs can be normalized before path checks.
        $hasScheme = preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidateSecret) === 1;
        if (!$hasScheme && !str_starts_with($candidateSecret, '/') && !str_starts_with($candidateSecret, './') && !str_starts_with($candidateSecret, '../')) {
            // Without a scheme or a leading path marker there is no route to inspect, so treat it as a possible secret.
            return false;
        }

        $withoutScheme = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $candidateSecret);
        if (!is_string($withoutScheme)) {
            // A regex engine error yields null; fail closed so a malformed strip is not mistaken for a clean route.
            return false;
        }

        $slashOffset = strpos($withoutScheme, '/');
        $path        = $slashOffset === false ? $withoutScheme : substr($withoutScheme, $slashOffset);
        if ($path === '' || $path[0] !== '/') {
            // No rooted path component means there is nothing route-shaped to whitelist.
            return false;
        }

        if (str_contains($path, '?') || str_contains($path, '#')) {
            // Query or fragment markers signal an opaque token tail, not a clean route, so do not exempt it.
            return false;
        }

        // Match public route/path characters.
        $hasPublicPathShape = preg_match('#^/[A-Za-z0-9._~/%:-]+$#', $path) === 1;
        // Match natural-language path segments rather than opaque tokens.
        $hasAlphabeticSegment = preg_match('/[A-Za-z]{3,}/', $path) === 1;
        // Match token separators that are common in credentials but not route paths.
        $hasTokenSeparator = preg_match('/[+=]/', $path) === 1;

        // Treat as a route only with a real path shape and word-like segments and no credential separators.
        return $hasPublicPathShape && $hasAlphabeticSegment && !$hasTokenSeparator;
    }

    /**
     * Detect long gruff config-path strings such as `rules.<id>.excludeFromScore`.
     *
     * @param string $candidateSecret - Literal under test; dotted config keys can look high entropy but are public metadata.
     *
     * @return bool - true when the literal is a gruff configuration path rather than secret material
     */
    private function isGruffConfigPathLiteral(string $candidateSecret): bool
    {
        if (
            !str_starts_with($candidateSecret, 'rules.')
            && !str_starts_with($candidateSecret, 'paths.')
            && !str_starts_with($candidateSecret, 'allowlists.')
            && !str_starts_with($candidateSecret, 'selection.')
        ) {
            // Without a known config root, the literal is not a gruff config path and stays eligible for scanning.
            return false;
        }

        // Match known config roots followed by dotted path segments; values, URLs, and credentials do not use this shape.
        return preg_match('/^(?:rules|paths|allowlists|selection)\.[A-Za-z0-9_.-]+$/', $candidateSecret) === 1;
    }

    /**
     * Detect public clinical-code metadata whose long tokens are standard identifiers.
     *
     * @param string $candidateSecret - Long token under test; clinical code systems use IDs that mimic secret entropy.
     * @param string $line - Source line of the literal; the surrounding field name is what marks it
     *                                as metadata.
     *
     * @return bool - True when the candidate is medical terminology metadata.
     */
    private function isMedicalStandardsMetadata(string $candidateSecret, string $line): bool
    {
        // Match clinical terminology field names that carry public standards metadata.
        if (!preg_match('/(?:CodeSystem|ConceptCode|HL7|OID|ValueSet)/i', $line)) {
            // Without a clinical field name nearby the token is not standards metadata, so leave it for entropy checks.
            return false;
        }

        // Match HL7 value-set codes such as PHVS_ObservationInterpretation_HL7_V3.
        if (preg_match('/^(?:PH|PHVS)_[A-Za-z0-9_]+_HL7_V\d+$/', $candidateSecret) === 1) {
            // A recognised HL7 value-set code is public metadata, never a credential.
            return true;
        }

        // Match dotted OID identifiers used by medical terminology systems.
        if (preg_match('/^\d+(?:\.\d+){3,}$/', $candidateSecret) === 1) {
            // A dotted OID is a public terminology identifier, never a credential.
            return true;
        }

        // Match field names that explicitly identify HL7 code metadata.
        $hasHl7MetadataField = preg_match('/(?:CodeSystemCode|HL7Table|ValueSetCode)/i', $line) === 1;

        // Remaining HL7-bearing tokens count as metadata only when an explicit HL7 code field names them.
        return str_contains($candidateSecret, 'HL7') && $hasHl7MetadataField;
    }

    /**
     * Return source text for a 1-based line number.
     *
     * @param string $source - Full file source the literal was matched in.
     * @param int    $lineNumber - 1-based line number of the literal, as reported by the offset-to-line helper.
     *
     * @return string - Line text, or an empty string when unavailable.
     */
    private function lineText(string $source, int $lineNumber): string
    {
        $lines = explode("\n", $source);

        // Hand back the literal's own line for the metadata field-name check;
        // empty when the number is past the end of source.
        return $lines[$lineNumber - 1] ?? '';
    }
}
