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
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
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

            $entropy = SecretScannerHelper::entropy($candidateSecret);
            if ($entropy < $entropyThreshold && !(strlen($candidateSecret) >= 64 && ctype_xdigit($candidateSecret))) {
                continue;
            }

            $preview    = SecretScannerHelper::redactedPreview($candidateSecret);
            $findings[] = SecretScannerHelper::finding(
                analysisUnit:        $analysisUnit,
                ruleId:      self::ID,
                message:     sprintf('High-entropy string literal detected: %s.', $preview),
                line:        SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
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
    private function shouldSkipKnownSecretPattern(string $candidateSecret): bool
    {
        return str_starts_with($candidateSecret, 'AKIA')
            || str_starts_with($candidateSecret, 'ASIA')
            || str_starts_with($candidateSecret, 'sk_live_')
            || str_starts_with($candidateSecret, 'sk-proj-')
            || str_starts_with($candidateSecret, 'sk-ant-')
            || str_starts_with($candidateSecret, 'ghp_')
            || str_starts_with($candidateSecret, 'xox')
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

        // Recognize common source/config/documentation file extensions in path-like literals.
        return preg_match('/\\.(?:php|inc|json|xml|neon|ya?ml|txt|md|stub)$/i', $candidateSecret) === 1;
    }
}
