<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;

/**
 * Detects oversized test methods that hide intent behind setup and assertions.
 */
final readonly class TestMethodTooLongRule implements RuleInterface
{
    /**
     * Stable rule identifier for long test method findings.
     */
    public const ID = 'test-quality.test-method-too-long';

    /**
     * Describe the test method too long rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Test method too long',
            pillar:            Pillar::TestQuality,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::High,
            defaultThresholds: ['maxMeaningfulLines' => 25],
            defaultOptions:    ['pathOverrides' => []],
        );
    }

    /**
     * Find test methods whose line count exceeds configured thresholds.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for oversized test methods.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition  = $this->definition();
        $settings    = $context->settingsFor($definition);
        $threshold   = $this->thresholdForPath(
            $unit->file->displayPath,
            (int) $settings->numericThreshold('maxMeaningfulLines'),
            $settings->option('pathOverrides'),
        );
        $sourceLines = explode("\n", $unit->source);
        $findings    = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            if ($scope->endLine === null) {
                continue;
            }

            $count = $this->countMeaningfulLines($sourceLines, $scope->line, $scope->endLine);

            if ($count <= $threshold) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s spans %d meaningful lines, above the threshold of %d.',
                    $scope->symbol,
                    $count,
                    $threshold,
                ),
                filePath:    $unit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                endLine:     $scope->endLine,
                symbol:      $scope->symbol,
                remediation: 'Split the scenario into focused tests, extract setup helpers, or move shared arrangement into setUp().',
                metadata:    ['meaningfulLines' => $count, 'threshold' => $threshold],
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $sourceLines
     * @return int Count of non-empty, non-comment lines in the method range.
     */
    private function countMeaningfulLines(array $sourceLines, int $startLine, int $endLine): int
    {
        $count = 0;

        for ($lineNumber = $startLine; $lineNumber <= $endLine; $lineNumber++) {
            $index = $lineNumber - 1;
            if (!isset($sourceLines[$index])) {
                continue;
            }

            $line = trim($sourceLines[$index]);

            if ($line === '') {
                continue;
            }

            if (in_array($line, ['{', '}', '},', ');', '];', '),', ',', ');'], true)) {
                continue;
            }

            if (str_starts_with($line, '//') || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '*') || str_starts_with($line, '/*') || str_starts_with($line, '*/')) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Resolve a path-specific threshold override when configured.
     *
     * @param int|float|bool|string|array<array-key, int|float|bool|string> $pathOverrides Configured path override map.
     * @return int Effective max meaningful lines threshold.
     */
    private function thresholdForPath(string $displayPath, int $defaultThreshold, int|float|bool|string|array $pathOverrides): int
    {
        if (!is_array($pathOverrides)) {
            return $defaultThreshold;
        }

        $normalizedPath = str_replace('\\', '/', $displayPath);
        foreach ($pathOverrides as $pattern => $threshold) {
            if (is_int($pattern) && is_string($threshold)) {
                [$pattern, $threshold] = $this->parsePathOverride($threshold);
            }

            if (!is_string($pattern) || (!is_int($threshold) && !is_float($threshold))) {
                continue;
            }

            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                return max(1, (int) $threshold);
            }
        }

        return $defaultThreshold;
    }

    /**
     * Parse a compact `glob=threshold` override entry from config.
     *
     * @return array{0: string, 1: int|float|string}
     */
    private function parsePathOverride(string $pathOverride): array
    {
        $parts = explode('=', $pathOverride, 2);
        if (count($parts) !== 2 || !is_numeric($parts[1])) {
            return ['', ''];
        }

        $threshold = str_contains($parts[1], '.') ? (float) $parts[1] : (int) $parts[1];

        return [$parts[0], $threshold];
    }
}
