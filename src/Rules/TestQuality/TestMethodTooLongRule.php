<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;

/**
 * Flags a test method whose meaningful body (blanks, comments, and lone brackets excluded) runs past the
 * line budget - a long test usually buries its intent in setup and assertions and could be split. Runs over
 * every test; the budget is tunable and can be raised per path. Advisory, high confidence.
 */
final readonly class TestMethodTooLongRule implements RuleInterface
{
    /**
     * Stable rule identifier for long test method findings.
     */
    public const ID = 'test-quality.test-method-too-long';

    /**
     * Describes the test-method-too-long rule for the registry and reports.
     *
     * @return RuleDefinition - immutable metadata, default Advisory severity, and the maxMeaningfulLines threshold callers tune via config
     */
    public function definition(): RuleDefinition
    {
        // Advisory by default: an oversized test is a smell, not a failure, so teams opt in to gating on it.
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
     * Reports test methods whose meaningful line count exceeds the threshold.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one Advisory finding per test scope exceeding its threshold; empty when every scope is within budget
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition  = $this->definition();
        $settings    = $ruleContext->settingsFor($definition);
        $threshold   = $this->thresholdForPath(
            $analysisUnit->file->displayPath,
            (int)$settings->numericThreshold('maxMeaningfulLines'),
            $settings->option('pathOverrides'),
        );
        $sourceLines = explode("\n", $analysisUnit->source);
        $findings    = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // A scope with no known end line cannot be measured.
            if ($scope->endLine === null) {
                continue;
            }

            $count = $this->countMeaningfulLines($sourceLines, $scope->line, $scope->endLine);

            // Stay quiet while the test is within its line budget.
            if ($count <= $threshold) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf(
                                 '%s spans %d meaningful lines, above the threshold of %d.',
                                 $scope->symbol,
                                 $count,
                                 $threshold,
                             ),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->anchorLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                endLine:     $scope->endLine,
                symbol:      $scope->symbol,
                remediation: 'Split the scenario into focused tests, extract setup helpers, or move shared arrangement into setUp(). If a path consistently needs a higher threshold (e.g. integration suites), add an entry to `rules.test-quality.test-method-too-long.options.pathOverrides` in `.gruff-php.yaml`.',
                metadata:    ['meaningfulLines' => $count, 'threshold' => $threshold],
            );
        }

        return $findings;
    }

    /**
     * Counts the meaningful body lines of a test method, skipping blanks, comments, and lone brackets.
     *
     * @param list<string> $sourceLines - All source lines of the unit, indexed from zero (line N is index N-1).
     * @param int          $startLine - First source line of the test scope, inclusive (1-based).
     * @param int          $endLine - Last source line of the test scope, inclusive (1-based).
     *
     * @return int - meaningful line tally compared against the threshold; blanks, comments, and lone brackets are excluded
     */
    private function countMeaningfulLines(array $sourceLines, int $startLine, int $endLine): int
    {
        $count = 0;

        // Walk every source line the test spans.
        for ($lineNumber = $startLine; $lineNumber <= $endLine; $lineNumber++) {
            $index = $lineNumber - 1;
            // Guard against a line index the source does not have.
            if (!isset($sourceLines[$index])) {
                continue;
            }

            $line = trim($sourceLines[$index]);

            // Blank lines carry no meaning.
            if ($line === '') {
                continue;
            }

            // A lone bracket or separator is scaffolding, not a real line.
            if (in_array($line, ['{', '}', '},', ');', '];', '),', ',', ');'], true)) {
                continue;
            }

            // Line comments do not count toward the body.
            if (str_starts_with($line, '//') || str_starts_with($line, '#')) {
                continue;
            }

            // Docblock and block-comment lines do not count either.
            if (str_starts_with($line, '*') || str_starts_with($line, '/*') || str_starts_with($line, '*/')) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Resolves the effective line threshold for a path, honouring configured overrides.
     *
     * @param string                                                        $displayPath - File path matched against each override glob;
     *                                                                                        backslashes normalised to slashes first.
     * @param int                                                           $defaultThreshold - Threshold applied when no override pattern matches this
     *                                                                                        path.
     * @param int|float|bool|string|array<array-key, int|float|bool|string> $pathOverrides - Override map; else default.
     *
     * @return int - effective max-meaningful-lines budget: the first matching override (floored at 1) or the default when none match
     */
    private function thresholdForPath(string $displayPath, int $defaultThreshold, int|float|bool|string|array $pathOverrides): int
    {
        if (!is_array($pathOverrides)) {
            // Malformed config (not a map): ignore it and apply the default rather than throwing.
            return $defaultThreshold;
        }

        $normalizedPath = str_replace('\\', '/', $displayPath);
        // Weigh the path against each configured override.
        foreach ($pathOverrides as $pattern => $threshold) {
            // Accept both the map form and a compact glob=threshold string.
            if (is_int($pattern) && is_string($threshold)) {
                [$pattern, $threshold] = $this->parsePathOverride($threshold);
            }

            // Skip entries that are not a usable pattern and numeric threshold.
            if (!is_string($pattern) || (!is_int($threshold) && !is_float($threshold))) {
                continue;
            }

            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                // First matching glob wins; floor at 1 so a zero or negative override can never disable the rule.
                return max(1, (int)$threshold);
            }
        }

        return $defaultThreshold;
    }

    /**
     * Parses a compact `glob=threshold` override entry from config.
     *
     * @param string $pathOverride - Single override in `glob=threshold` form, e.g. `tests/Integration/*=60`.
     *
     * @return array{0: string, 1: int|float|string} - glob pattern and its parsed numeric threshold; both empty strings when the entry is not a
     *                  valid `glob=number`
     */
    private function parsePathOverride(string $pathOverride): array
    {
        $parts = explode('=', $pathOverride, 2);
        if (count($parts) !== 2 || !is_numeric($parts[1])) {
            // Not a parseable `glob=number` entry: return empty parts so the caller skips it.
            return ['', ''];
        }

        $threshold = str_contains($parts[1], '.') ? (float)$parts[1] : (int)$parts[1];

        // Preserve the numeric kind: a dotted value stays a float, otherwise it is an int threshold.
        return [$parts[0], $threshold];
    }
}
