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
     * Stock development user names and passwords, compared case-insensitively and whole (FAMILY-CONTRACT.md section 5).
     * They are documented defaults such as `postgres:postgres` or `scott:tiger`, not credentials, so they are held as plain text.
     *
     * @var list<string>
     */
    private const STOCK_DEVELOPMENT_WORDS = [
        'admin', 'bar', 'dbpass', 'dbuser', 'default', 'demo', 'example', 'foo', 'guest', 'mysql', 'pass', 'passwd',
        'password', 'postgres', 'root', 'sa', 'scott', 'secret', 'test', 'tiger', 'user', 'username',
    ];

    /**
     * Loopback hosts on which a stock credential is a development default rather than a live weakness.
     *
     * @var list<string>
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /**
     * Describes the database-URL-password sensitive-data rule for the registry and reports.
     *
     * @return RuleDefinition - warning severity, high confidence; ships off by default, so only a config can turn it on
     */
    public function definition(): RuleDefinition
    {
        // High confidence is justified because a literal `:password@` inside a recognised DB scheme is an
        // unambiguous credential, not a heuristic guess; the dummy-value and comment filters below cut the
        // residual false positives, so callers can gate on these findings without manual triage.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Database URL password',
            pillar:             Pillar::SensitiveData,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Warning,
            confidence:         Confidence::High,
            // Off unless a project sets `enabled: true`: the 0.6.0 precision measurement found all nine judged findings wrong (ADR-034).
            // Nine findings are too few to delete the rule on, so it stays available for projects that want it.
            isEnabledByDefault: false,
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

            // A stock pair such as `postgres:postgres` on a local or Compose host is a documented development default.
            if (self::isStockDevelopmentUrl($matches['user'][$index][0], $password, $matches['host'][$index][0])) {
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

    /**
     * Reports whether a URL's credential is a stock development pair on a host only a developer's machine reaches.
     * The same pair on any other host still reports, because a default credential on a reachable host is a live weakness.
     *
     * @param string $user - User name between the scheme and the colon, e.g. `postgres`.
     * @param string $password - Password between the colon and the `@`.
     * @param string $hostAndRest - Everything after the `@` up to the closing quote, e.g. `localhost:5432/app`.
     *
     * @return bool - true when both words are stock and the host is loopback or a single-label service name such as `db`
     */
    private static function isStockDevelopmentUrl(string $user, string $password, string $hostAndRest): bool
    {
        // Both halves must be stock words; one real word keeps the finding.
        if (!in_array(strtolower($user), self::STOCK_DEVELOPMENT_WORDS, true)
            || !in_array(strtolower($password), self::STOCK_DEVELOPMENT_WORDS, true)) {
            return false;
        }

        $authority = strtolower((string)preg_replace('#[/?\#].*$#s', '', $hostAndRest));
        // A bracketed IPv6 host such as `[::1]:5432` keeps its colons inside the brackets.
        $host = preg_match('#^\[(?<address>[^\]]+)\]#', $authority, $bracketed) === 1
            ? $bracketed['address']
            : explode(':', $authority, 2)[0];

        return in_array($host, self::LOOPBACK_HOSTS, true) || ($host !== '' && !str_contains($host, '.') && !str_contains($host, ':'));
    }
}
