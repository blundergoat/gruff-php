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

final readonly class AwsAccessKeyRule implements SourceTextRuleInterface
{
    public const ID = 'sensitive-data.aws-access-key';

    /**
     * Describe the AWS access key sensitive-data rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'AWS access key',
            pillar: Pillar::SensitiveData,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    /**
     * Find string literals that resemble AWS access key IDs.
     *
     * @return list<\GruffPhp\Finding\Finding> Findings for AWS key-like literals.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        preg_match_all('/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/', $unit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings = [];
        foreach ($matches[0] as $match) {
            [$value, $offset] = $match;
            if (SecretScannerHelper::isLikelyDummyValue($value)) {
                continue;
            }

            $findings[] = SecretScannerHelper::finding(
                unit: $unit,
                ruleId: self::ID,
                message: sprintf('Potential AWS access key detected: %s.', SecretScannerHelper::redactedPreview($value)),
                line: SecretScannerHelper::lineNumberForOffset($unit->source, $offset),
                confidence: Confidence::High,
                detector: 'aws-access-key',
                preview: SecretScannerHelper::redactedPreview($value),
                remediation: 'Remove the key from source and rotate it if it was real.',
            );
        }

        return $findings;
    }
}
