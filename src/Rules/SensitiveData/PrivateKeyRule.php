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
 * Flags source text that carries a PEM private-key header (`-----BEGIN ... PRIVATE KEY-----`), so the user
 * can pull committed key material out of the repository and rotate it.
 *
 * A source-text rule: it scans the raw file rather than the AST, so keys in heredocs, comments, or fixtures
 * are all caught. Only the header is reported - the key body is never stored in a finding. Warning
 * severity, high confidence, since a PEM armor header is an unambiguous signal.
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
     * Reports each PEM private-key header so users can remove and rotate committed key material.
     * The finding stores only the fixed marker; neither the header nor body reaches report metadata.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - One finding per private-key header; the key body is not stored.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!str_contains($analysisUnit->source, '-----BEGIN ')) {
            // Without a PEM armor prefix the key regex cannot match, so skip the scan entirely.
            return [];
        }

        // Match every PEM private-key armor header, capturing each one's byte offset.
        preg_match_all('/-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings = [];
        // Report each private-key header the scan turned up.
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
