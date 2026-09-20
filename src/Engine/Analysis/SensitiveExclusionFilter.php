<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

use GruffPhp\Engine\Config\SensitiveExclusion;
use GruffPhp\Results\Finding\Finding;

/**
 * Applies the configured `sensitiveExclusions` entries to a run's findings and counts what each one
 * hid.
 *
 * This runs once the rules have produced their findings and before scoring, the exit-code gate, and
 * any reporter sees them, so an accepted synthetic fixture stops grading against the project while
 * still appearing in the report's audit rows. Matching reads only the finding's rule id, its
 * project-relative display path, and its symbol; the message and the matched value take no part, so
 * no suppression can ever be written against the secret itself.
 */
final readonly class SensitiveExclusionFilter
{
    /**
     * The one rule the family's built-in lockfile skip covers; every other sensitive-data rule still reads a lockfile.
     */
    private const BUILT_IN_LOCKFILE_RULE = 'sensitive-data.high-entropy-string';

    /**
     * The rationale every port publishes on a built-in lockfile audit row.
     */
    private const BUILT_IN_LOCKFILE_REASON = 'Lockfile digests are published integrity hashes, so the entropy rule skips package-manager lockfiles by name.';

    /**
     * The ratified package-manager lockfile names, matched by exact base name at any depth.
     *
     * @var list<string>
     */
    private const BUILT_IN_LOCKFILE_NAMES = [
        'package-lock.json',
        'npm-shrinkwrap.json',
        'yarn.lock',
        'pnpm-lock.yaml',
        'composer.lock',
        'Cargo.lock',
        'go.sum',
        'uv.lock',
        'poetry.lock',
    ];

    /**
     * Partitions findings into those no entry claimed and one audit row per configured entry.
     *
     * @param list<Finding>            $findings - Findings produced by the run, in report order.
     * @param list<SensitiveExclusion> $exclusions - Validated exclusions in configuration order, so a position is its audit index.
     *
     * @return SensitiveExclusionResult - the surviving findings plus one audit row per configured entry; with nothing configured the findings pass
     *                                  through untouched and no rows are published.
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
        return $this->applyBuiltInLockfileSkip($survivors, $this->summaries($exclusions, $counts));
    }

    /**
     * Removes the entropy rule's findings from package-manager lockfiles and appends one audit row per lockfile
     * that had any, after the configured rows.
     *
     * A lockfile digest is a published integrity hash and a real project carries thousands of them, so the family
     * skips that one rule by file name. It is counted on every surface rather than applied in silence, and a
     * lockfile with nothing to skip publishes no row (FAMILY-CONTRACT.md section 13a). Every other sensitive-data
     * rule still reads the lockfile, because a credential pasted into one is as live as anywhere else.
     *
     * @param list<Finding>                    $findings - Findings that survived the configured entries.
     * @param list<SensitiveExclusionSummary>  $summaries - The configured entries' audit rows, which built-in rows follow.
     *
     * @return SensitiveExclusionResult - Survivors, then the configured rows followed by one row per lockfile.
     */
    private function applyBuiltInLockfileSkip(array $findings, array $summaries): SensitiveExclusionResult
    {
        $skipped   = [];
        $survivors = [];

        foreach ($findings as $finding) {
            if ($finding->ruleId === self::BUILT_IN_LOCKFILE_RULE && $this->isBuiltInLockfile($finding->filePath)) {
                $skipped[$finding->filePath] = ($skipped[$finding->filePath] ?? 0) + 1;
                continue;
            }

            $survivors[] = $finding;
        }

        ksort($skipped);
        // Built-in rows are numbered among themselves, so the index means the same thing in every port however
        // many entries the user configured. `source` is what tells a consumer which channel a row came from.
        $builtInIndex = 0;
        foreach ($skipped as $lockfile => $count) {
            $summaries[] = new SensitiveExclusionSummary(
                index: $builtInIndex++,
                rule: self::BUILT_IN_LOCKFILE_RULE,
                path: (string)$lockfile,
                symbol: null,
                reason: self::BUILT_IN_LOCKFILE_REASON,
                suppressed: $count,
                source: 'built-in',
            );
        }

        return new SensitiveExclusionResult($survivors, $summaries);
    }

    /**
     * Reports whether a path's base name is one of the ratified package-manager lockfiles.
     *
     * @param string $filePath - Project-relative display path of the finding's file.
     *
     * @return bool - true when the entropy rule's findings in this file are skipped and counted
     */
    private function isBuiltInLockfile(string $filePath): bool
    {
        $normalized = str_replace('\\', '/', $filePath);
        $fileName   = substr((string)strrchr('/' . $normalized, '/'), 1);

        return in_array($fileName, self::BUILT_IN_LOCKFILE_NAMES, true);
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
