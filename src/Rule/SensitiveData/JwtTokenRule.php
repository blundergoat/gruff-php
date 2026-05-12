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
 * Detects string literals that match JWT token structure.
 */
final readonly class JwtTokenRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for JWT token findings.
     */
    public const ID = 'sensitive-data.jwt-token';

    /**
     * Describe the JWT token sensitive-data rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'JWT token literal',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find string literals that resemble embedded JWT tokens.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> Findings for JWT-like literals.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        preg_match_all('/\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', $unit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings = [];
        foreach ($matches[0] as $match) {
            [$value, $offset] = $match;
            if (SecretScannerHelper::isLikelyDummyValue($value)) {
                continue;
            }

            $preview    = SecretScannerHelper::redactedPreview($value);
            $findings[] = SecretScannerHelper::finding(
                unit:        $unit,
                ruleId:      self::ID,
                message:     sprintf('JWT-like token literal detected: %s.', $preview),
                line:        SecretScannerHelper::lineNumberForOffset($unit->source, $offset),
                confidence:  Confidence::Medium,
                detector:    'jwt-token',
                preview:     $preview,
                remediation: 'Move tokens out of source fixtures/config and generate them at runtime.',
            );
        }

        return $findings;
    }
}
