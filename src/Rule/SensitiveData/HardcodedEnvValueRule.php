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
            if (SecretScannerHelper::isLikelyDummyValue($value) || !$this->hasSecretValueEvidence($key, $value)) {
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

    private function hasSecretValueEvidence(string $key, string $value): bool
    {
        $normalizedValue = trim($value, "\"' \t\r\n");
        $upperKey = strtoupper($key);

        if ($this->isConservativeKeySuffix($upperKey) && !$this->hasStrongSecretShape($normalizedValue)) {
            return false;
        }

        if ($this->isCommonNonSecretValue($normalizedValue)) {
            return false;
        }

        return $this->hasStrongSecretShape($normalizedValue);
    }

    private function hasStrongSecretShape(string $value): bool
    {
        return strlen($value) >= 20 && SecretScannerHelper::entropy($value) >= 3.5;
    }

    private function isConservativeKeySuffix(string $key): bool
    {
        foreach (['_NAME', '_PREFIX', '_ID', '_MODE'] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isCommonNonSecretValue(string $value): bool
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,24}$/', $value) === 1) {
            return true;
        }

        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $value) === 1) {
            return true;
        }

        if (preg_match('/^[a-z][a-z0-9_.-]+[._-]$/', $value) === 1) {
            return true;
        }

        return false;
    }
}
