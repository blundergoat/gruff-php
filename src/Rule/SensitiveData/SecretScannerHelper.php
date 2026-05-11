<?php

declare(strict_types=1);

namespace GruffPhp\Rule\SensitiveData;

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

    /**
     * Compute the 1-based line number for a byte offset within a source string.
     *
     * @return int
     */
    public static function lineNumberForOffset(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * Build a redacted preview of a sensitive value (first 4 + last 4 chars for values longer than 8 chars).
     *
     * @return string The redacted preview safe for inclusion in findings and reports.
     */
    public static function redactedPreview(string $value): string
    {
        $length = strlen($value);
        if ($length <= 8) {
            return sprintf('<redacted:%d chars>', $length);
        }

        return sprintf('%s...%s (redacted, %d chars)', substr($value, 0, 4), substr($value, -4), $length);
    }

    /**
     * Build a `KEY=<redacted:N chars>` string for env-style secret findings.
     *
     * @return string
     */
    public static function redactedKeyValue(string $key, string $value): string
    {
        return sprintf('%s=<redacted:%d chars>', $key, strlen($value));
    }

    /**
     * Detect whether the value looks like a placeholder rather than a real secret (changeme / dummy / example / etc.).
     *
     * @return bool True when the value is empty, low-cardinality, or matches a known placeholder marker.
     */
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

    /**
     * Detect whether the file's basename is `.env` or `.env.*`.
     *
     * @return bool
     */
    public static function isEnvFile(string $displayPath): bool
    {
        $basename = basename($displayPath);

        return $basename === '.env' || str_starts_with($basename, '.env.');
    }

    /**
     * Detect whether the path lives under a test or fixtures directory (test, tests, fixture, fixtures).
     *
     * @return bool
     */
    public static function isTestPath(string $displayPath): bool
    {
        $normalized = '/' . str_replace('\\', '/', $displayPath);

        return str_contains($normalized, '/test/')
            || str_contains($normalized, '/tests/')
            || str_contains($normalized, '/fixture/')
            || str_contains($normalized, '/fixtures/');
    }

    /**
     * Detect whether the line contains an upper-cased secret-context fragment (API_KEY, PASSWORD, etc.).
     *
     * @return bool
     */
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

    /**
     * Compute the Shannon entropy of a string in bits-per-character.
     *
     * @return float The entropy; higher values indicate more random / secret-shaped content.
     */
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

    /**
     * Build a sensitive-data Finding with redacted preview / detector metadata.
     *
     * @return Finding
     */
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
            pillar: Pillar::SensitiveData,
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
