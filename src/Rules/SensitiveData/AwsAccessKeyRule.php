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
 * Flags a string shaped like an AWS access key ID (`AKIA...`/`ASIA...` plus 16 chars) so users can remove and rotate it.
 *
 * Scans raw source text rather than only PHP AST literals so credentials in config or fixture files are caught too:
 * - Skips matches inside comments and obvious dummy values, and redacts every reported value.
 * - Reports Warning severity with High confidence because the fixed `AKIA`/`ASIA` shape is near-unique to AWS.
 */
final readonly class AwsAccessKeyRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for AWS access key findings.
     */
    public const ID = 'sensitive-data.aws-access-key';

    /**
     * Describes the AWS-access-key sensitive-data rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, high confidence).
     */
    public function definition(): RuleDefinition
    {
        // High confidence: retained matches require an AWS prefix and fixed-length body, then pass dummy and mask filters.
        return new RuleDefinition(
            id:              self::ID,
            name:            'AWS access key',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports AWS access-key-ID matches that survive comment, sample, placeholder and mask exclusions.
     *
     * @param AnalysisUnit $analysisUnit - Supplies raw source and comment tokens for scanning.
     * @param RuleContext $ruleContext - Required by the rule interface; unused by this scanner.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - One finding per retained AWS key-ID match; empty when none survive.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast pre-screen: files without an AKIA or ASIA prefix cannot contain a supported AWS access-key ID.
        if (!str_contains($analysisUnit->source, 'AKIA') && !str_contains($analysisUnit->source, 'ASIA')) {
            return [];
        }

        // AWS access-key IDs require an AKIA or ASIA prefix and sixteen uppercase-alphanumeric characters; offsets locate findings.
        preg_match_all('/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);

        // Judge each match independently so one example key in a comment does not hide a live-shaped key on the next line.
        foreach ($matches[0] as $match) {
            [$candidateSecret, $offset] = $match;

            // Documentation examples inside PHP comments are skipped rather than reported as live credentials.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            // Skip vendor-documented samples and placeholder tokens such as AKIA...EXAMPLE before reporting.
            if (SecretScannerHelper::isLikelyDummyValue($candidateSecret, shouldSplitIdentifierWords: false)) {
                continue;
            }

            // A body that is entirely X shows where a key goes and names no credential (FAMILY-CONTRACT.md section 5).
            if (self::isMaskedKey($candidateSecret)) {
                continue;
            }

            $findings[] = SecretScannerHelper::findingAtOffset(
                analysisUnit:  $analysisUnit,
                ruleId:        self::ID,
                message:       sprintf('Potential AWS access key detected: %s.', SecretScannerHelper::categoryMarker('aws-access-key')),
                offset:        $offset,
                confidence:    Confidence::High,
                detector:      'aws-access-key',
                displayMarker: SecretScannerHelper::categoryMarker('aws-access-key'),
                remediation:   'Remove the key from source and rotate it if it was real.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether a key-shaped match is a masked value: the prefix followed by a body that is entirely X.
     *
     * Only the whole body counts, because a real key may contain a run of X and hiding it would hide a live credential.
     *
     * @param string $candidateSecret - The matched key-shaped literal.
     *
     * @return bool - true when the sixteen-character body is all X
     */
    private static function isMaskedKey(string $candidateSecret): bool
    {
        // Require the entire sixteen-character body to be X so a partial run cannot hide a credential.
        return preg_match('/^(?:AKIA|ASIA)X{16}$/', $candidateSecret) === 1;
    }
}
