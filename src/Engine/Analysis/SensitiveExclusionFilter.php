<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

use GruffPhp\Engine\Config\SensitiveExclusion;
use GruffPhp\Results\Finding\Finding;

/**
 * Hides the sensitive-data findings a project has accepted, and publishes an audit row for everything it hides.
 * `analyse`, `summary` and a branch review's base scan run it after the rules and before scoring, the exit-code gate and any report.
 *
 * - A `sensitiveExclusions` entry the user wrote in config hides the findings matching its rule id, path and optional symbol.
 * - The built-in test-path skip hides sensitive-data findings in test, fixture and example files, e.g. a sample key in `tests/keys.php`.
 * - Matching never reads a finding's message or matched value, so no entry can be written against the secret itself.
 */
final readonly class SensitiveExclusionFilter
{
    /**
     * Reason a user reads on each `builtInTestPath[...]` audit row; every port publishes these exact words (FAMILY-CONTRACT.md section 13a).
     */
    public const BUILT_IN_TEST_PATH_REASON = 'Test, fixture and example files hold sample credentials, so sensitive-data rules skip them by path.';

    /**
     * The one sensitive-data rule that still reads test paths, because finding realistic personal data in fixtures is its job.
     */
    private const BUILT_IN_TEST_PATH_EXEMPT_RULE = 'sensitive-data.pii-test-fixture';

    /**
     * Directory names, compared case-insensitively, that make a path test code, e.g. `Tests/Unit/` or `examples/`.
     *
     * @var list<string>
     */
    private const BUILT_IN_TEST_PATH_DIRECTORIES = ['test', 'tests', '__tests__', 'spec', 'testdata', 'fixtures', 'examples'];

    /**
     * Matches a whole base name that marks a test file in any family language, e.g. `LoginTest.php` or `login.spec.ts`.
     */
    private const BUILT_IN_TEST_FILE_NAME_PATTERN = '/^(?:.*_test\.go|test_.*\.py|.*_test\.py|.*Test\.php|.*\.(?:test|spec)\.(?:js|jsx|ts|tsx|mjs|cjs))$/D';

    /**
     * Hides one run's accepted findings and returns the rest with the audit rows a reviewer reads in the report.
     * The user's own entries claim findings first, then the built-in test-path skip takes what is left.
     *
     * @param list<Finding>            $findings - Findings produced by the run, in report order.
     * @param list<SensitiveExclusion> $exclusions - Validated exclusions in configuration order, so a position is its audit index.
     *
     * @return SensitiveExclusionResult - findings still reported, then audit rows; no rows means nothing is configured and no test file had one
     */
    public function apply(array $findings, array $exclusions): SensitiveExclusionResult
    {
        $counts    = array_fill(0, count($exclusions), 0);
        $survivors = [];

        // Offer each finding to the entries in configuration order; the first matching entry owns it.
        foreach ($findings as $finding) {
            $matchedIndex = $this->matchingEntryIndex($finding, $exclusions);

            // No entry claimed this finding, so it keeps reporting exactly as it would with no config at all.
            if ($matchedIndex === null) {
                $survivors[] = $finding;
                continue;
            }

            $counts[$matchedIndex]++;
        }

        // A configured entry claims its findings first, so its count stays what the user wrote it for.
        return $this->applyBuiltInTestPathSkip(new SensitiveExclusionResult($survivors, $this->summaries($exclusions, $counts)));
    }

    /**
     * Hides sensitive-data findings in test, fixture and example files, and publishes one audit row per hidden file and rule.
     *
     * A user scanning a project with sample keys in `tests/fixtures/` sees `builtInTestPath[...]` rows instead of findings.
     * The skip is never silent, and the fixture-PII rule keeps reading these files (FAMILY-CONTRACT.md section 13a).
     *
     * @param SensitiveExclusionResult $result - Findings and audit rows left after the user's exclusions.
     *
     * @return SensitiveExclusionResult - Survivors, then the earlier rows followed by one row per file and rule skipped here.
     */
    private function applyBuiltInTestPathSkip(SensitiveExclusionResult $result): SensitiveExclusionResult
    {
        $skippedCountByFileAndRule = [];
        $survivors                 = [];

        // Each finding either stays in the report or is folded into its file's audit row.
        foreach ($result->findings as $finding) {
            // Only the pillar's findings in a test, fixture or example file are skipped, and never the fixture-PII rule.
            if (str_starts_with($finding->ruleId, 'sensitive-data.')
                && $finding->ruleId !== self::BUILT_IN_TEST_PATH_EXEMPT_RULE
                && self::isBuiltInTestPath($finding->filePath)) {
                $fileAndRule                             = $finding->filePath . "\0" . $finding->ruleId;
                $skippedCountByFileAndRule[$fileAndRule] = ($skippedCountByFileAndRule[$fileAndRule] ?? 0) + 1;
                continue;
            }

            $survivors[] = $finding;
        }

        // Byte order, path first and then rule id, is the order every port publishes these rows in.
        uksort($skippedCountByFileAndRule, strcmp(...));
        $summaries = $result->summaries;
        // Built-in rows count from 0 on their own, so row 0 means the same in every port however many entries the user configured.
        // A consumer tells built-in rows from configured ones by `source: "built-in"`, never by index.
        $nextIndex = 0;

        // One row per file and rule, which text output shows as `builtInTestPath[tests/keys.php] sensitive-data.aws-access-key: 2`.
        foreach ($skippedCountByFileAndRule as $fileAndRule => $count) {
            [$filePath, $ruleId] = explode("\0", (string)$fileAndRule, 2);
            $summaries[]         = new SensitiveExclusionSummary(
                index: $nextIndex++,
                rule: $ruleId,
                path: $filePath,
                symbol: null,
                reason: self::BUILT_IN_TEST_PATH_REASON,
                suppressed: $count,
                source: 'built-in',
            );
        }

        return new SensitiveExclusionResult($survivors, $summaries);
    }

    /**
     * Reports whether a finding's file is test, fixture or example code, e.g. `tests/Unit/KeysTest.php` or `examples/demo.php`.
     *
     * @param string $filePath - Project-relative display path of the finding's file, as the report prints it.
     *
     * @return bool - true for a directory named in the family list, compared case-insensitively, or a test-file base name
     */
    public static function isBuiltInTestPath(string $filePath): bool
    {
        $segments = explode('/', str_replace('\\', '/', $filePath));
        $baseName = (string)array_pop($segments);

        // Any directory on the path, compared case-insensitively, can make it a test, fixture or example path.
        foreach ($segments as $directory) {
            if (in_array(strtolower($directory), self::BUILT_IN_TEST_PATH_DIRECTORIES, true)) {
                return true;
            }
        }

        // Otherwise the file name alone must mark a test, e.g. `KeysTest.php`, `keys_test.go` or `keys.spec.ts`.
        return preg_match(self::BUILT_IN_TEST_FILE_NAME_PATTERN, $baseName) === 1;
    }

    /**
     * Finds the first configured entry whose declared scope covers a finding.
     *
     * @param Finding                  $finding - Finding to place.
     * @param list<SensitiveExclusion> $exclusions - Validated exclusions in configuration order.
     *
     * @return int|null - index of the owning entry, or null when the finding falls outside every declared scope and must keep reporting.
     */
    private function matchingEntryIndex(Finding $finding, array $exclusions): ?int
    {
        // Configuration order decides ownership, so a finding is counted once even if two entries could cover it.
        foreach ($exclusions as $index => $exclusion) {
            if ($exclusion->matches($finding)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Builds the audit rows, one per configured entry, whether or not the entry matched anything.
     *
     * @param list<SensitiveExclusion> $exclusions - Validated exclusions in configuration order.
     * @param array<int, int>          $counts - Per-entry suppressed counts, keyed by the entry's position.
     *
     * @return list<SensitiveExclusionSummary> - one row per configured entry, so an entry that matched nothing still reports `suppressed: 0`.
     */
    private function summaries(array $exclusions, array $counts): array
    {
        $summaries = [];

        // Publish every entry, including the ones that matched nothing, so no configured suppression is invisible.
        foreach ($exclusions as $index => $exclusion) {
            $summaries[] = new SensitiveExclusionSummary(
                index:      $index,
                rule:       $exclusion->ruleId,
                path:       $exclusion->path,
                symbol:     $exclusion->symbol,
                reason:     $exclusion->reason,
                suppressed: $counts[$index],
            );
        }

        return $summaries;
    }
}
