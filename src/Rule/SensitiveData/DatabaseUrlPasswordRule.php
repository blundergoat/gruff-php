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

final readonly class DatabaseUrlPasswordRule implements SourceTextRuleInterface
{
    public const ID = 'sensitive-data.database-url-password';

    /**
     * Describe the database URL password sensitive-data rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Database URL password',
            pillar: Pillar::SensitiveData,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    /**
     * Find database connection URLs that embed passwords.
     *
     * @return list<\GruffPhp\Finding\Finding> Findings for credential-bearing database URLs.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        preg_match_all(
            '#\b(?<scheme>mysql|mariadb|mongodb|pgsql|postgres|postgresql|redis)://(?<user>[^:\s/@]+):(?<password>[^@\s"\']+)@(?<host>[^"\']+)#i',
            $unit->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $findings = [];
        foreach ($matches[0] as $index => $match) {
            [$value, $offset] = $match;
            $password = $matches['password'][$index][0];
            if (SecretScannerHelper::isLikelyDummyValue($password)) {
                continue;
            }

            $preview = preg_replace('#:' . preg_quote($password, '#') . '@#', ':<redacted:' . strlen($password) . ' chars>@', $value);
            if (!is_string($preview)) {
                $preview = '<redacted database URL>';
            }

            $findings[] = SecretScannerHelper::finding(
                unit: $unit,
                ruleId: self::ID,
                message: sprintf('Database connection string contains an inline password: %s.', $preview),
                line: SecretScannerHelper::lineNumberForOffset($unit->source, $offset),
                confidence: Confidence::High,
                detector: 'database-url-password',
                preview: $preview,
                remediation: 'Move database passwords into a secret store or runtime environment variable.',
            );
        }

        return $findings;
    }
}
