<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Secrets;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;

final class SecretScannerHelper
{
    /**
     * @return list<string>
     */
    public static function sensitiveKeyFragments(): array
    {
        return ['API_KEY', 'KEY', 'PASSWORD', 'PASS', 'SECRET', 'TOKEN', 'PRIVATE'];
    }

    public static function lineNumberForOffset(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    public static function redactedPreview(string $value): string
    {
        $length = strlen($value);
        if ($length <= 8) {
            return sprintf('<redacted:%d chars>', $length);
        }

        return sprintf('%s...%s (redacted, %d chars)', substr($value, 0, 4), substr($value, -4), $length);
    }

    public static function redactedKeyValue(string $key, string $value): string
    {
        return sprintf('%s=<redacted:%d chars>', $key, strlen($value));
    }

    public static function isLikelyDummyValue(string $value): bool
    {
        $normalized = strtolower(trim($value, "\"' \t\r\n"));
        if ($normalized === '') {
            return true;
        }

        foreach (['changeme', 'dummy', 'example', 'fake', 'placeholder', 'redacted', 'sample', 'test'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return count(array_unique(str_split($normalized))) <= 2;
    }

    public static function isEnvFile(string $displayPath): bool
    {
        $basename = basename($displayPath);

        return $basename === '.env' || str_starts_with($basename, '.env.');
    }

    public static function isTestPath(string $displayPath): bool
    {
        $normalized = '/' . str_replace('\\', '/', $displayPath);

        return str_contains($normalized, '/test/')
            || str_contains($normalized, '/tests/')
            || str_contains($normalized, '/fixture/')
            || str_contains($normalized, '/fixtures/');
    }

    public static function hasSensitiveContext(string $line): bool
    {
        $upperLine = strtoupper($line);

        foreach (self::sensitiveKeyFragments() as $fragment) {
            if (str_contains($upperLine, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function entropy(string $value): float
    {
        $length = strlen($value);
        if ($length === 0) {
            return 0.0;
        }

        $counts = count_chars($value, 1);
        $entropy = 0.0;

        foreach ($counts as $count) {
            $probability = $count / $length;
            $entropy -= $probability * log($probability, 2);
        }

        return $entropy;
    }

    public static function finding(
        AnalysisUnit $unit,
        string $ruleId,
        string $message,
        int $line,
        Confidence $confidence,
        string $detector,
        string $preview,
        string $remediation,
    ): Finding {
        return new Finding(
            ruleId: $ruleId,
            message: $message,
            filePath: $unit->file->displayPath,
            line: $line,
            severity: Severity::Warning,
            pillar: Pillar::Secrets,
            tier: RuleTier::V01,
            confidence: $confidence,
            remediation: $remediation,
            metadata: [
                'detector' => $detector,
                'preview' => $preview,
            ],
        );
    }
}
