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
 * Flags inline passwords in supported database URLs so callers can move credentials into runtime configuration.
 *
 * It scans raw source, excluding comment tokens and selected placeholder or development-default values.
 * Findings contain only a scheme marker, with no user, password, host, path or credential length.
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
     * @var list<string> - Whole words allowed for both user names and passwords in the development exemption.
     */
    private const STOCK_DEVELOPMENT_WORDS = [
        'admin', 'bar', 'dbpass', 'dbuser', 'default', 'demo', 'example', 'foo', 'guest', 'mysql', 'pass', 'passwd',
        'password', 'postgres', 'root', 'sa', 'scott', 'secret', 'test', 'tiger', 'user', 'username',
    ];

    /**
     * Hosts explicitly accepted by the stock-development-credential exemption.
     *
     * @var list<string> - Host values accepted directly by isStockDevelopmentUrl().
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /**
     * Describes the database-URL-password sensitive-data rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata with warning severity, high confidence and default enablement disabled.
     */
    public function definition(): RuleDefinition
    {



        return new RuleDefinition(
            id:                 self::ID,
            name:               'Database URL password',
            pillar:             Pillar::SensitiveData,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Warning,
            confidence:         Confidence::High,
            // Off unless a project sets enabled: true: the 0.6.0 precision measurement found all nine judged findings wrong (ADR-034).
            // Nine findings are too few to delete the rule on, so it stays available for projects that want it.
            isEnabledByDefault: false,
        );
    }

    /**
     * Reports source text shaped like a database URL with an inline password so users can move it to runtime configuration.
     * The finding carries only the scheme-derived marker; user, password, host, path, and length never reach output.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for credential-bearing database URLs.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Unsupported schemes cannot produce this rule's database-credential finding.
        if (preg_match('#(?:mysql|mariadb|mongodb|pgsql|postgres|postgresql|redis)://#i', $analysisUnit->source) !== 1) {
            return [];
        }

        // Named captures separate credentials and host for exemptions while retaining the scheme for the redacted marker.
        preg_match_all(
            '#\b(?<scheme>mysql|mariadb|mongodb|pgsql|postgres|postgresql|redis)://(?<user>[^:\s/@]+):(?<password>[^@\s"\']+)@(?<host>[^"\']+)#i',
            $analysisUnit->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        foreach ($matches[0] as $index => $match) {
            [, $offset] = $match;
            // Skip URLs inside comments as documentation examples; a real credential left in a comment is not reported.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            $password = $matches['password'][$index][0];
            // Passwords recognised by the dummy-value heuristic are exempted.
            if (SecretScannerHelper::isLikelyDummyValue($password)) {
                continue;
            }

            // Stock credentials on a loopback or single-label host are exempted as likely development defaults.
            if (self::isStockDevelopmentUrl($matches['user'][$index][0], $password, $matches['host'][$index][0])) {
                continue;
            }

            // Keep only the non-secret scheme in the marker; omit the captured credentials, host, path, and length.
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
     * Identifies stock credential pairs eligible for the development-default exemption.
     * Both words must be listed stock values and the host must be in LOOPBACK_HOSTS or have a single label.
     *
     * @param string $user - User name between the scheme and the colon, e.g. postgres.
     * @param string $password - Password between the colon and the @.
     * @param string $hostAndRest - Everything after the @ up to the closing quote, e.g. localhost:5432/app.
     *
     * @return bool - True for two stock words and a host in LOOPBACK_HOSTS or a non-empty single-label host.
     */
    private static function isStockDevelopmentUrl(string $user, string $password, string $hostAndRest): bool
    {

        if (!in_array(strtolower($user), self::STOCK_DEVELOPMENT_WORDS, true)
            || !in_array(strtolower($password), self::STOCK_DEVELOPMENT_WORDS, true)) {
            return false;
        }

        // Only the URL authority is relevant to the loopback and single-label host exemption.
        $authority = strtolower((string)preg_replace('#[/?\#].*$#s', '', $hostAndRest));
        // A bracketed IPv6 host such as [::1]:5432 keeps its colons inside the brackets.
        $host = preg_match('#^\[(?<address>[^\]]+)\]#', $authority, $bracketed) === 1
            ? $bracketed['address']
            : explode(':', $authority, 2)[0];

        return in_array($host, self::LOOPBACK_HOSTS, true) || ($host !== '' && !str_contains($host, '.') && !str_contains($host, ':'));
    }
}
