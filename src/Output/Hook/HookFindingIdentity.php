<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\RemediationAction;
use JsonException;

/**
 * Builds identities for comparing hook findings with a baseline or base scan.
 * Base fingerprints omit line and column numbers, known measurement keys, and remediation metadata.
 * Batch identities add occurrence numbers so repeated findings can be compared by count.
 */
final readonly class HookFindingIdentity
{
    /**
     * Excludes measurement, limit, and unit metadata from the qualifier so those changes preserve the base identity.
     *
     * @var array<string, true> - Metadata keys omitted from hook qualifiers.
     */
    private const VALUE_KEYS = [
        'averageLength' => true,
        'complexity' => true,
        'count' => true,
        'depth' => true,
        'groupCoverageLimit' => true,
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
     * Agent-facing remediation transport describes what to do with a finding, not which finding it is.
     * These keys are additive output metadata and must not resurface accepted hook debt when a rule
     * gains or changes its action classification.
     *
     * @var array<string, true> - Remediation metadata keys omitted from hook qualifiers.
     */
    private const REMEDIATION_KEYS = [
        RemediationAction::CONFIGURATION_KEY => true,
        RemediationAction::METADATA_KEY => true,
    ];

    /**
     * Hashes a finding's rule, scope, file, symbol, and qualifier for hook comparison.
     * Line and column numbers are excluded; qualifier() selects the remaining identifying details.
     *
     * @param Finding $finding - Finding whose identifying fields are hashed.
     * @param string $scope - Hook scope: line, symbol, file, or project.
     *
     * @return string - A 16-character SHA-256 prefix; forFindings() adds occurrence numbers for batch comparison.
     * @throws JsonException - If the identity payload cannot be encoded as JSON.
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
     * Assigns occurrence-suffixed identities for hook comparison, grouping findings by their base fingerprint.
     * Occurrences follow line, column, and input order; the result does not track persistent physical occurrences.
     *
     * @param list<Finding> $findings - Findings from one current or comparison scan.
     *
     * @return array<int, string> - Occurrence-suffixed identity per distinct object ID; empty for no findings.
     * @throws JsonException - If a finding's identity payload cannot be encoded as JSON.
     */
    public static function forFindings(array $findings): array
    {
        /** @var array<string, list<int>> $groups - Input positions grouped by their base fingerprint. */
        $groups = [];


        foreach ($findings as $index => $finding) {
            $groups[self::forFinding($finding, HookFindingScope::classify($finding))][] = $index;
        }

        $identities = [];


        foreach ($groups as $baseIdentity => $indices) {
            // Relative location order preserves ordinals when a line shift leaves the colliding findings in the same order.


            usort(
                $indices,
                static fn(int $left, int $right): int => [$findings[$left]->line ?? PHP_INT_MAX, $findings[$left]->column ?? PHP_INT_MAX, $left]
                    <=> [$findings[$right]->line ?? PHP_INT_MAX, $findings[$right]->column ?? PHP_INT_MAX, $right],
            );




            foreach ($indices as $ordinal => $index) {
                $identities[spl_object_id($findings[$index])] = $baseIdentity . ':' . $ordinal;
            }
        }

        return $identities;
    }

    /**
     * Selects scalar or null metadata after excluding known measurement and remediation keys.
     * Without such metadata, masks numeric substrings in the message; file and project scopes omit the qualifier.
     *
     * @param Finding $finding - Finding whose metadata or message supplies the qualifier.
     * @param string $scope - Hook scope deciding whether a qualifier is included.
     *
     * @return array<string, bool|float|int|string|null>|string|null - Scalar/null metadata, masked text, or no qualifier.
     */
    private static function qualifier(Finding $finding, string $scope): array|string|null
    {


        if ($scope === HookFindingScope::FILE || $scope === HookFindingScope::PROJECT) {
            return null;
        }

        $qualitativeMetadata = [];


        foreach ($finding->metadata as $key => $value) {
            // Skip measured values and agent-facing action hints; neither changes the underlying problem.
            if (isset(self::VALUE_KEYS[$key]) || isset(self::REMEDIATION_KEYS[$key])) {
                continue;
            }



            if (is_scalar($value) || $value === null) {
                $qualitativeMetadata[$key] = $value;
            }
        }

        // Sorting keys makes the qualifier independent of metadata insertion order.

        if ($qualitativeMetadata !== []) {
            ksort($qualitativeMetadata, SORT_STRING);

            return $qualitativeMetadata;
        }

        // Without scalar or null qualitative metadata, mask numeric substrings so changed numbers may retain the qualifier.
        return preg_replace('/\d+(?:\.\d+)?/', '{n}', $finding->message);
    }
}
