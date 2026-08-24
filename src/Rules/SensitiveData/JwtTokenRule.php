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
 * Flags a string that matches the three-segment JWT shape (`eyJ....eyJ....sig`), so the user can move
 * embedded tokens out of source fixtures and config and mint them at runtime instead.
 *
 * A source-text rule that skips matches inside comments and obvious dummy values and redacts the reported
 * token. It shares its shape check with the high-entropy rule so each dotted secret reports exactly once.
 * Warning severity, medium confidence - test fixtures do legitimately embed sample tokens.
 */
final readonly class JwtTokenRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for JWT token findings.
     */
    public const ID = 'sensitive-data.jwt-token';

    /**
     * Three-segment JWT shape: two base64url `eyJ` JSON segments plus a signature segment.
     */
    private const JWT_SHAPE_PATTERN = 'eyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}';

    /**
     * Reports whether a whole literal is JWT-shaped.
     *
     * Shared with the high-entropy rule so each dotted secret reports exactly once:
     * real JWTs under this rule, opaque dotted tokens under high-entropy.
     *
     * @param string $literal - Candidate string literal.
     *
     * @return bool - true when the entire literal matches the three-segment `eyJ` JWT shape this rule scans for
     */
    public static function matchesJwtShape(string $literal): bool
    {
        // Anchors the shared three-segment JWT pattern so the ENTIRE literal must be JWT-shaped, not a substring.
        return preg_match('/^' . self::JWT_SHAPE_PATTERN . '$/', $literal) === 1;
    }

    /**
     * Describes the JWT-token sensitive-data rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, medium confidence).
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A test fixture or documentation sample embedding a real-shaped but expired or synthetic JWT outside a comment.',
                    'mitigation' => 'Only obvious dummy values and matches inside comments are skipped, so mint the token at runtime or move the sample into a comment.',
                ],
            ],
        );
    }

    /**
     * Reports each string that resembles an embedded JWT token, redacting the value.
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

        // Match every JWT-shaped token in the source, capturing each one's byte offset.
        preg_match_all('/\b' . self::JWT_SHAPE_PATTERN . '\b/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        // Weigh each candidate the scan found.
        foreach ($matches[0] as $match) {
            [$candidateSecret, $offset] = $match;
            // A token inside a comment is an example, not a live secret.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            // An obvious sample or dummy token is not a real secret.
            if (SecretScannerHelper::isLikelyDummyValue($candidateSecret)) {
                continue;
            }

            $displayMarker = SecretScannerHelper::fixedSecretMarker();
            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('JWT-like token literal detected: %s.', $displayMarker),
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::Medium,
                detector:     'jwt-token',
                displayMarker: $displayMarker,
                remediation:  'Move tokens out of source fixtures/config and generate them at runtime.',
            );
        }

        return $findings;
    }
}
