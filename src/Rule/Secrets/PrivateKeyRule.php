<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Secrets;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\SourceTextRuleInterface;

final readonly class PrivateKeyRule implements SourceTextRuleInterface
{
    public const ID = 'secrets.private-key';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Private key material',
            pillar: Pillar::Secrets,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        preg_match_all('/-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/', $unit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings = [];
        foreach ($matches[0] as $match) {
            [$header, $offset] = $match;
            $findings[] = SecretScannerHelper::finding(
                unit: $unit,
                ruleId: self::ID,
                message: 'Private key block header detected; key body is redacted.',
                line: SecretScannerHelper::lineNumberForOffset($unit->source, $offset),
                confidence: Confidence::High,
                detector: 'private-key-header',
                preview: $header,
                remediation: 'Remove private keys from source and rotate the key if it was real.',
            );
        }

        return $findings;
    }
}
