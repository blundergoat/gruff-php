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
 * Flags PEM private-key headers in raw source so callers can locate and rotate committed key material.
 *
 * Heredocs, comments and fixtures are scanned; no complete key body is required.
 * Findings carry a fixed marker, never the matched header or key body, with warning severity and high confidence.
 */
final readonly class PrivateKeyRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for private key findings.
     */
    public const ID = 'sensitive-data.private-key';

    /**
     * Describes the private-key sensitive-data rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, high confidence).
     */
    public function definition(): RuleDefinition
    {

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
     * Reports each supported private-key armor header so users can review possible key material.
     * The finding stores only the fixed marker; neither the header nor body reaches report metadata.
     *
     * @param AnalysisUnit $analysisUnit - Supplies raw source for the header scan.
     * @param RuleContext $ruleContext - Required by the rule interface; unused by this scanner.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - One finding per supported header, including in comments; empty when none match.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!str_contains($analysisUnit->source, '-----BEGIN ')) {

            return [];
        }

        // Supported private-key armor headers identify report locations without capturing any key body.
        preg_match_all('/-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings = [];

        foreach ($matches[0] as $match) {
            [, $offset] = $match;
            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      'Private key block header detected; key body is redacted.',
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::High,
                detector:     'private-key-header',
                displayMarker: SecretScannerHelper::categoryMarker('private-key'),
                remediation:  'Remove private keys from source and rotate the key if it was real.',
            );
        }

        return $findings;
    }
}
