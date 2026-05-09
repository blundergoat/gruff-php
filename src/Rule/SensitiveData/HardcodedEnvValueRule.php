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

final readonly class HardcodedEnvValueRule implements SourceTextRuleInterface
{
    public const ID = 'sensitive-data.hardcoded-env-value';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Hardcoded environment value',
            pillar: Pillar::SensitiveData,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if (SecretScannerHelper::isEnvFile($unit->file->displayPath)) {
            return [];
        }

        preg_match_all(
            '/\b(?<key>[A-Z0-9_]*(?:API_KEY|PASSWORD|PASS|SECRET|TOKEN|PRIVATE_KEY)[A-Z0-9_]*)\s*=\s*["\']?(?<value>[A-Za-z0-9_+\/=.-]{8,})["\']?/',
            $unit->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $findings = [];
        foreach ($matches[0] as $index => $match) {
            $key = $matches['key'][$index][0];
            $value = $matches['value'][$index][0];
            $offset = $match[1];
            if (SecretScannerHelper::isLikelyDummyValue($value)) {
                continue;
            }

            $preview = SecretScannerHelper::redactedKeyValue($key, $value);
            $findings[] = SecretScannerHelper::finding(
                unit: $unit,
                ruleId: self::ID,
                message: sprintf('Hardcoded env-style secret value detected: %s.', $preview),
                line: SecretScannerHelper::lineNumberForOffset($unit->source, $offset),
                confidence: Confidence::Medium,
                detector: 'env-style-secret',
                preview: $preview,
                remediation: 'Move env-style secret values out of committed source files.',
            );
        }

        return $findings;
    }
}
