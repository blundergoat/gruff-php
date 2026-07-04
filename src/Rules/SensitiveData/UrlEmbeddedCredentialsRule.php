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
 * Flags an HTTP(S) URL that embeds `user:password@host` credentials, so the user can move authentication
 * into headers or a secret store instead of leaving it in source.
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
     * Describes the URL-embedded-credentials rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, high confidence).
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
     * Reports each http(s) URL that embeds a password credential, redacting the password.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for credential-bearing URLs with only redacted previews.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: needs https?://user:pass@ before scanning.
        if (preg_match('#https?://[^:\s/@]+:[^@\s"\']+@#i', $analysisUnit->source) !== 1) {
            // Without the credential delimiter shape, no URL credential can be present.
            return [];
        }

        // Match every http(s) URL that carries an inline credential, capturing user, password, and host.
        preg_match_all(
            '#\bhttps?://(?<user>[^:\s/@]+):(?<password>[^@\s"\']+)@(?<host>[^\s"\']+)#i',
            $analysisUnit->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        // Weigh each URL the scan found.
        foreach ($matches[0] as $index => $match) {
            [$credentialUrl, $offset] = $match;
            // A URL inside a comment is an example, not a live credential.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            $password = $matches['password'][$index][0];
            // An obvious placeholder password is not a real secret.
            if (SecretScannerHelper::isLikelyDummyValue($password)) {
                continue;
            }

            // Redact the password in the preview so the finding never carries the real value.
            $preview = preg_replace('#:' . preg_quote($password, '#') . '@#', ':<redacted:' . strlen($password) . ' chars>@', $credentialUrl);
            // Fall back to a fully redacted URL if the replace could not run.
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

        return $findings;
    }
}
