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
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning at medium confidence: the three-segment shape is distinctive,
        // but test fixtures legitimately embed sample tokens.
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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for JWT-like literals.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!str_contains($analysisUnit->source, 'eyJ')) {
            // Every JWT header segment begins "eyJ"; without it no token can match, so skip the scan.
            return [];
        }

        preg_match_all('/\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        foreach ($matches[0] as $match) {
            [$candidateSecret, $offset] = $match;
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            if (SecretScannerHelper::isLikelyDummyValue($candidateSecret)) {
                continue;
            }

            $preview    = SecretScannerHelper::redactedPreview($candidateSecret);
            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('JWT-like token literal detected: %s.', $preview),
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::Medium,
                detector:     'jwt-token',
                preview:      $preview,
                remediation:  'Move tokens out of source fixtures/config and generate them at runtime.',
            );
        }

        return $findings;
    }
}
