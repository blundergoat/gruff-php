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
 * Detects HTTP(S) URLs that embed `user:password@host` credentials.
 *
 * Complements `sensitive-data.database-url-password` (which covers DB schemes);
 * this rule scopes to `http`/`https` only, so the two never match the same URL.
 * The password is redacted in the reported preview.
 */
final readonly class UrlEmbeddedCredentialsRule implements SourceTextRuleInterface
{
    /** Stable rule identifier for URL-embedded-credential findings. */
    public const ID = 'sensitive-data.url-credentials';

    /**
     * Describe the URL-embedded-credentials rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Inline HTTP credentials are high-confidence secret material in source text.
        return new RuleDefinition(
            id:              self::ID,
            name:            'URL embedded credentials',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
            description:     'HTTP(S) URL with an inline user:password@host credential.',
        );
    }

    /**
     * Find http(s) URLs that embed a password credential.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> - Findings for credential-bearing URLs.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: needs https?://user:pass@ before scanning.
        if (preg_match('#https?://[^:\s/@]+:[^@\s"\']+@#i', $analysisUnit->source) !== 1) {
            // Without the credential delimiter shape, no URL credential can be present.
            return [];
        }

        preg_match_all(
            '#\bhttps?://(?<user>[^:\s/@]+):(?<password>[^@\s"\']+)@(?<host>[^\s"\']+)#i',
            $analysisUnit->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        foreach ($matches[0] as $index => $match) {
            [$credentialUrl, $offset] = $match;
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            $password = $matches['password'][$index][0];
            if (SecretScannerHelper::isLikelyDummyValue($password)) {
                continue;
            }

            $preview = preg_replace('#:' . preg_quote($password, '#') . '@#', ':<redacted:' . strlen($password) . ' chars>@', $credentialUrl);
            if (!is_string($preview)) {
                $preview = '<redacted URL credential>';
            }

            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('URL embeds an inline credential: %s.', $preview),
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::High,
                detector:     'url-embedded-credentials',
                preview:      $preview,
                remediation:  'Remove inline URL credentials; pass authentication via headers or a secret store instead.',
            );
        }

        // Every finding carries only a redacted URL preview.
        return $findings;
    }
}
