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
 * Detects database URLs that embed password credentials.
 */
final readonly class DatabaseUrlPasswordRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for database URL password findings.
     */
    public const ID = 'sensitive-data.database-url-password';

    /**
     * Describe the database URL password sensitive-data rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
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
     * Find database connection URLs that embed passwords.
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

        preg_match_all(
            '#\b(?<scheme>mysql|mariadb|mongodb|pgsql|postgres|postgresql|redis)://(?<user>[^:\s/@]+):(?<password>[^@\s"\']+)@(?<host>[^"\']+)#i',
            $analysisUnit->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        foreach ($matches[0] as $index => $match) {
            [$databaseUrl, $offset] = $match;
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            $password = $matches['password'][$index][0];
            if (SecretScannerHelper::isLikelyDummyValue($password)) {
                continue;
            }

            $preview = preg_replace('#:' . preg_quote($password, '#') . '@#', ':<redacted:' . strlen($password) . ' chars>@', $databaseUrl);
            if (!is_string($preview)) {
                $preview = '<redacted database URL>';
            }

            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('Database connection string contains an inline password: %s.', $preview),
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::High,
                detector:     'database-url-password',
                preview:      $preview,
                remediation:  'Move database passwords into a secret store or runtime environment variable.',
            );
        }

        return $findings;
    }
}
