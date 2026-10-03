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
 * Flags a database connection URL that embeds a password inline (`mysql://user:pass@host`), so the user can
 * move the credential into a secret store or environment variable.
 *
 * A source-text rule covering the common DB schemes (mysql, mariadb, mongodb, pgsql/postgres, redis). It
 * skips matches inside comments and obvious dummy passwords, and the reported URL has its password
 * redacted. Warning severity, high confidence - a literal `:password@` is an unambiguous credential.
 */
final readonly class DatabaseUrlPasswordRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for database URL password findings.
     */
    public const ID = 'sensitive-data.database-url-password';

    /**
     * Describes the database-URL-password sensitive-data rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, high confidence).
     */
    public function definition(): RuleDefinition
    {
        // High confidence is justified because a literal `:password@` inside a recognised DB scheme is an
        // unambiguous credential, not a heuristic guess; the dummy-value and comment filters below cut the
        // residual false positives, so callers can gate on these findings without manual triage.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Database URL password',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports each database URL that embeds a password so users can move it to runtime configuration.
     * The finding carries only the fixed marker; user, password, host, path, and length never reach output.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for credential-bearing database URLs.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: a credential-bearing DB URL needs scheme://...:...@...
        // Skip the alternation when no supported scheme prefix appears.
        if (preg_match('#(?:mysql|mariadb|mongodb|pgsql|postgres|postgresql|redis)://#i', $analysisUnit->source) !== 1) {
            // No supported DB scheme in the source means there can be no inline credential to report.
            return [];
        }

        // Match every credential-bearing DB URL, capturing the scheme, user, password, and host.
        preg_match_all(
            '#\b(?<scheme>mysql|mariadb|mongodb|pgsql|postgres|postgresql|redis)://(?<user>[^:\s/@]+):(?<password>[^@\s"\']+)@(?<host>[^"\']+)#i',
            $analysisUnit->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        // Weigh each URL the scan found.
        foreach ($matches[0] as $index => $match) {
            [, $offset] = $match;
            // A URL inside a comment is an example, not a live credential.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            $password = $matches['password'][$index][0];
            // An obvious placeholder password is not a real secret.
            if (SecretScannerHelper::isLikelyDummyValue($password)) {
                continue;
            }

            // The scheme is the one part of a connection string the URL already publishes in plain text.
            $displayMarker = SecretScannerHelper::connectionStringMarker($matches['scheme'][$index][0]);

            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('Database connection string contains an inline password: %s.', $displayMarker),
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::High,
                detector:     'database-url-password',
                displayMarker: $displayMarker,
                remediation:  'Move database passwords into a secret store or runtime environment variable.',
            );
        }

        return $findings;
    }
}
