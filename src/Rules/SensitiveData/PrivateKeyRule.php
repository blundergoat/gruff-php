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
 * Detects source text that appears to contain private key material.
 */
final readonly class PrivateKeyRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for private key findings.
     */
    public const ID = 'sensitive-data.private-key';

    /**
     * Describe the private key sensitive-data rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning, high confidence: a PEM private-key header is an unambiguous signal, rarely a false positive.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Private key material',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find string literals that appear to contain private key material.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - One finding per private-key header; the key body is not stored.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // User view: choose the findings list branch for this case.
        if (!str_contains($analysisUnit->source, '-----BEGIN ')) {
            // Without a PEM armor prefix the key regex cannot match, so skip the scan entirely.
            return [];
        }

        preg_match_all('/-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings = [];
        // User view: add each item that can appear in findings list.
        foreach ($matches[0] as $match) {
            [$header, $offset] = $match;
            $findings[]        = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      'Private key block header detected; key body is redacted.',
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::High,
                detector:     'private-key-header',
                preview:      $header,
                remediation:  'Remove private keys from source and rotate the key if it was real.',
            );
        }

        return $findings;
    }
}
