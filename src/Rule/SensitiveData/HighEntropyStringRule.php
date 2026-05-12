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
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> Findings for suspicious high-entropy literals.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $settings         = $context->settingsFor($this->definition());
        $minLength        = (int) $settings->numericThreshold('minLength');
        $entropyThreshold = (float) $settings->numericThreshold('entropy');

        preg_match_all('/["\'](?<value>[A-Za-z0-9_+\/=.-]{32,})["\']/', $unit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings = [];
        foreach ($matches['value'] as $match) {
            [$value, $offset] = $match;
            if (strlen($value) < $minLength) {
                continue;
            }

            if ($this->shouldSkipKnownSecretPattern($value) || $this->isPathLikeLiteral($value) || SecretScannerHelper::isLikelyDummyValue($value)) {
                continue;
            }

            $entropy = SecretScannerHelper::entropy($value);
            if ($entropy < $entropyThreshold && !(strlen($value) >= 64 && ctype_xdigit($value))) {
                continue;
            }

            $preview    = SecretScannerHelper::redactedPreview($value);
            $findings[] = SecretScannerHelper::finding(
                unit:        $unit,
                ruleId:      self::ID,
                message:     sprintf('High-entropy string literal detected: %s.', $preview),
                line:        SecretScannerHelper::lineNumberForOffset($unit->source, $offset),
                confidence:  Confidence::Medium,
                detector:    'high-entropy-string',
                preview:     $preview,
                remediation: 'Confirm this is not a credential; move real secrets out of source.',
            );
        }

        return $findings;
    }

    /**
     * Defer known secret formats to more specific detectors.
     *
     * @return bool True when another rule should handle the literal.
     */
    private function shouldSkipKnownSecretPattern(string $value): bool
    {
        return str_starts_with($value, 'AKIA')
            || str_starts_with($value, 'ASIA')
            || str_starts_with($value, 'sk_live_')
            || str_starts_with($value, 'sk-proj-')
            || str_starts_with($value, 'sk-ant-')
            || str_starts_with($value, 'ghp_')
            || str_starts_with($value, 'xox')
            || substr_count($value, '.') === 2
            || (strlen($value) <= 48 && ctype_alpha($value));
    }

    /**
     * Detect path-like literals that should not be treated as secrets.
     *
     * @return bool True when the literal looks like a file path.
     */
    private function isPathLikeLiteral(string $value): bool
    {
        if (!str_contains($value, '/') && !str_contains($value, '\\')) {
            return false;
        }

        return preg_match('/\\.(?:php|inc|json|xml|neon|ya?ml|txt|md|stub)$/i', $value) === 1;
    }
}
