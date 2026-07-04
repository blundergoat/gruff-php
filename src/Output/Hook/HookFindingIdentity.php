<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;
use JsonException;

/**
 * Fingerprints findings so the hook command's "new problems only" mode can tell a freshly
 * introduced violation apart from one that was already there on the last run. The identity is
 * deliberately blind to line and column numbers and to measured values (complexity, line counts,
 * thresholds), so editing a file and pushing everything down a few lines, or a metric ticking
 * from 12 to 13, will not make an existing finding look brand new. Reach for this whenever the
 * hook runs against a baseline or diff (`--baseline` / `--diff`) and has to decide which findings
 * the user just added versus which are pre-existing debt it should stay quiet about.
 */
final readonly class HookFindingIdentity
{
    /**
     * Metadata keys that hold a measured value or a threshold rather than a stable trait of the
     * finding. They are stripped out before fingerprinting so that when a metric drifts - a method
     * creeping from 12 lines to 13, say - the user still sees it as the same known finding instead
     * of a spurious "new" one on their next hook run.
     *
     * @var array<string, true>
     */
    private const VALUE_KEYS = [
        'averageLength' => true,
        'complexity' => true,
        'count' => true,
        'depth' => true,
        'lines' => true,
        'maintainabilityIndex' => true,
        'measured' => true,
        'methodCount' => true,
        'parameters' => true,
        'properties' => true,
        'publicMethods' => true,
        'threshold' => true,
        'thresholdType' => true,
        'totalLines' => true,
        'unit' => true,
        'volume' => true,
    ];

    /**
     * Builds the stable fingerprint for a single finding, hashing only the traits that outlive an
     * edit - rule, scope, file, symbol, and a value-independent qualifier. This is the identity the
     * hook matches against the baseline to decide whether the user is seeing this problem for the first time.
     *
     * @param Finding $finding - The finding to fingerprint, straight from a rule.
     * @param string  $scope   - Which slice of the code the finding is pinned to: one of line, symbol, file, or project.
     *
     * @return string - The finding's fingerprint as a 16-character SHA-256 prefix; two findings that hash to the same string are treated as the same finding across runs.
     * @throws JsonException When the finding's payload cannot be encoded for hashing, which aborts the hook run.
     */
    public static function forFinding(Finding $finding, string $scope): string
    {
        $payload = [
            'ruleId' => $finding->ruleId,
            'scope' => $scope,
            'file' => $finding->filePath,
            'symbol' => $finding->symbol,
            'qualifier' => self::qualifier($finding, $scope),
        ];

        return substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * Fingerprints an entire run of findings together and gives each one a distinct identity, so that
     * two otherwise-identical hits in the same file - like a second error-suppression the user just
     * added - stay separable and the newer one cannot hide behind the older one in baseline or diff mode.
     *
     * @param list<Finding> $findings - The findings from one run to fingerprint together, either the current scan or the baseline snapshot it is being compared against.
     *
     * @return array<int, string> - Each finding's disambiguated identity, keyed by its `spl_object_id()` so a caller can look the value up per finding object; empty when no findings were supplied.
     * @throws JsonException When a finding's payload cannot be encoded for hashing, which aborts the hook run.
     */
    public static function forFindings(array $findings): array
    {
        /** @var array<string, list<int>> $groups Positions of the findings, bucketed by their bare fingerprint. */
        $groups = [];
        // First pass: drop every finding into a bucket keyed by its plain fingerprint, so any that would
        // collide - same rule and file, nothing to tell them apart - gather in the same bucket.
        foreach ($findings as $index => $finding) {
            $groups[self::forFinding($finding, HookFindingScope::classify($finding))][] = $index;
        }

        $identities = [];
        // Walk each bucket and, wherever more than one finding shares a fingerprint, hand out occurrence
        // numbers so those collisions turn into distinct identities.
        foreach ($groups as $baseIdentity => $indices) {
            // Order the colliding findings by line, then column, then original position, so the earliest
            // always takes number 0. Keying off position rather than the measured numbers is what lets a
            // whole-file line shift move every finding yet leave the numbering - and so the identities - intact.
            usort(
                $indices,
                static fn(int $left, int $right): int => [$findings[$left]->line ?? PHP_INT_MAX, $findings[$left]->column ?? PHP_INT_MAX, $left]
                    <=> [$findings[$right]->line ?? PHP_INT_MAX, $findings[$right]->column ?? PHP_INT_MAX, $right],
            );

            // Stamp each finding with its bucket fingerprint plus its occurrence number, filed under the
            // finding object's id for the hook to look up. Should the user reshuffle two identical findings,
            // only their numbers swap - resurfacing an old finding, never quietly concealing a new one.
            foreach ($indices as $ordinal => $index) {
                $identities[spl_object_id($findings[$index])] = $baseIdentity . ':' . $ordinal;
            }
        }

        return $identities;
    }

    /**
     * Extracts a value-independent detail that can tell two findings of the same rule apart and feeds it
     * into the fingerprint. Kept blind to measured numbers on purpose, so a finding whose only change is a
     * metric still reads as the same one on the user's next hook run.
     *
     * @param Finding $finding - The finding whose distinguishing detail is being extracted.
     * @param string  $scope   - Which slice of code the finding is pinned to: line, symbol, file, or project.
     *
     * @return array<string, bool|float|int|string|null>|string|null - The finding's qualitative metadata when it carries any; otherwise its message with every number blanked to `{n}`; or null for file- and project-scoped findings, which are already unique per file or per project and need no extra detail.
     */
    private static function qualifier(Finding $finding, string $scope): array|string|null
    {
        // File- and project-wide findings only ever surface once per file or per project, so there is
        // nothing to disambiguate here and no qualifier is needed.
        if ($scope === HookFindingScope::FILE || $scope === HookFindingScope::PROJECT) {
            return null;
        }

        $qualitativeMetadata = [];
        // Sift the finding's metadata for stable descriptive details - the entries that name what the
        // finding is about rather than measure how big it is.
        foreach ($finding->metadata as $key => $value) {
            // Skip anything that is a measured value or threshold; folding those in would make the identity
            // lurch every time the underlying number moves, which is exactly what we are avoiding.
            if (isset(self::VALUE_KEYS[$key])) {
                continue;
            }

            // Keep only plain scalar or null details, since the fingerprint has to hash cleanly and a
            // nested structure has no stable textual form to hash against.
            if (is_scalar($value) || $value === null) {
                $qualitativeMetadata[$key] = $value;
            }
        }

        // When the finding carried genuine descriptive metadata, sort it to a fixed order and use that as
        // the qualifier, so two same-rule findings with different details never collapse into one identity.
        if ($qualitativeMetadata !== []) {
            ksort($qualitativeMetadata, SORT_STRING);

            return $qualitativeMetadata;
        }

        // Nothing descriptive to lean on, so fall back to the finding's own message with every number
        // blanked to `{n}`; two hits that differ only in their figures then share a qualifier, sparing the
        // user a "new" finding when a value merely shifted.
        return preg_replace('/\d+(?:\.\d+)?/', '{n}', $finding->message);
    }
}
