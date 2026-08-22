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
 * Flags a string shaped like an AWS access key ID (`AKIA...`/`ASIA...` plus 16 chars), so the user can pull
 * a committed credential out of source and rotate it.
 *
 * A source-text rule: it scans the raw file, skipping matches inside comments and obvious dummy values, and
 * redacts the reported value. Warning severity, high confidence - the AKIA/ASIA prefix with a fixed body is
 * a near-unique AWS shape.
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
        // High confidence: the AKIA/ASIA prefix plus a fixed 16-char body is a near-unique AWS shape, rarely noise.
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
     * Reports each string that resembles a live AWS access key ID, redacting the value.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for AWS keys; empty when matches are comments or dummy values.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!str_contains($analysisUnit->source, 'AKIA') && !str_contains($analysisUnit->source, 'ASIA')) {
            // Neither AWS key-id prefix is present, so the regex cannot match; skip it to keep the rule near-free.
            return [];
        }

        // Match every AWS access-key-id shape, capturing each one's byte offset.
        preg_match_all('/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        // Weigh each candidate the scan found.
        foreach ($matches[0] as $match) {
            [$candidateSecret, $offset] = $match;
            // A match inside a comment is documentation or an example, not a live secret.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            // An obvious placeholder or dummy value is not a real credential.
            if (SecretScannerHelper::isLikelyDummyValue($candidateSecret)) {
                continue;
            }

            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('Potential AWS access key detected: %s.', SecretScannerHelper::fixedSecretMarker()),
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::High,
                detector:     'aws-access-key',
                displayMarker: SecretScannerHelper::fixedSecretMarker(),
                remediation:  'Remove the key from source and rotate it if it was real.',
            );
        }

        return $findings;
    }
}
