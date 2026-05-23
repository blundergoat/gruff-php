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
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> Findings for suspicious high-entropy literals.
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

            if ($this->shouldSkipKnownSecretPattern($candidateSecret) || $this->isPathLikeLiteral($candidateSecret) || SecretScannerHelper::isLikelyDummyValue($candidateSecret)) {
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
     * @return bool True when another rule should handle the literal.
     */
    private function shouldSkipKnownSecretPattern(string $candidateSecret): bool
    {
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
     * @return bool True when the literal looks like a file path.
     */
    private function isPathLikeLiteral(string $candidateSecret): bool
    {
        if (!str_contains($candidateSecret, '/') && !str_contains($candidateSecret, '\\')) {
            return false;
        }

        if ($this->isUrlOrRoutePathLiteral($candidateSecret)) {
            return true;
        }

        // Recognize common source/config/documentation file extensions in path-like literals.
        return preg_match('/\\.(?:php|inc|json|xml|neon|ya?ml|txt|md|stub)$/i', $candidateSecret) === 1;
    }

    /**
     * Detect URL and route literals that are long because of slugs or numeric IDs, not secret material.
     *
     * @return bool True when the literal is shaped like a public URL path.
     */
    private function isUrlOrRoutePathLiteral(string $candidateSecret): bool
    {
        if (str_starts_with($candidateSecret, 'https://hooks.slack.com/services/')) {
            return false;
        }

        // Match URI schemes so absolute URLs can be normalized before path checks.
        $hasScheme = preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidateSecret) === 1;
        if (!$hasScheme && !str_starts_with($candidateSecret, '/') && !str_starts_with($candidateSecret, './') && !str_starts_with($candidateSecret, '../')) {
            return false;
        }

        $withoutScheme = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $candidateSecret);
        if (!is_string($withoutScheme)) {
            return false;
        }

        $slashOffset = strpos($withoutScheme, '/');
        $path        = $slashOffset === false ? $withoutScheme : substr($withoutScheme, $slashOffset);
        if ($path === '' || $path[0] !== '/') {
            return false;
        }

        if (str_contains($path, '?') || str_contains($path, '#')) {
            return false;
        }

        // Match public route/path characters.
        $hasPublicPathShape = preg_match('#^/[A-Za-z0-9._~/%:-]+$#', $path) === 1;
        // Match natural-language path segments rather than opaque tokens.
        $hasAlphabeticSegment = preg_match('/[A-Za-z]{3,}/', $path) === 1;
        // Match token separators that are common in credentials but not route paths.
        $hasTokenSeparator = preg_match('/[+=]/', $path) === 1;

        return $hasPublicPathShape && $hasAlphabeticSegment && !$hasTokenSeparator;
    }

    /**
     * Detect public clinical-code metadata whose long tokens are standard identifiers.
     *
     * @return bool True when the candidate is medical terminology metadata.
     */
    private function isMedicalStandardsMetadata(string $candidateSecret, string $line): bool
    {
        // Match clinical terminology field names that carry public standards metadata.
        if (!preg_match('/(?:CodeSystem|ConceptCode|HL7|OID|ValueSet)/i', $line)) {
            return false;
        }

        // Match HL7 value-set codes such as PHVS_ObservationInterpretation_HL7_V3.
        if (preg_match('/^(?:PH|PHVS)_[A-Za-z0-9_]+_HL7_V\d+$/', $candidateSecret) === 1) {
            return true;
        }

        // Match dotted OID identifiers used by medical terminology systems.
        if (preg_match('/^\d+(?:\.\d+){3,}$/', $candidateSecret) === 1) {
            return true;
        }

        // Match field names that explicitly identify HL7 code metadata.
        $hasHl7MetadataField = preg_match('/(?:CodeSystemCode|HL7Table|ValueSetCode)/i', $line) === 1;

        return str_contains($candidateSecret, 'HL7') && $hasHl7MetadataField;
    }

    /**
     * Return source text for a 1-based line number.
     *
     * @return string Line text, or an empty string when unavailable.
     */
    private function lineText(string $source, int $lineNumber): string
    {
        $lines = explode("\n", $source);

        return $lines[$lineNumber - 1] ?? '';
    }
}
