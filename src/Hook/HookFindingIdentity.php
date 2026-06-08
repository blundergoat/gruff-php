<?php

declare(strict_types=1);

namespace GruffPhp\Hook;

use GruffPhp\Finding\Finding;
use JsonException;

/**
 * Builds hook-specific identities that survive line shifts and measured-value changes.
 */
final readonly class HookFindingIdentity
{
    /**
     * Metadata keys that hold measured values or thresholds and must not participate in stable identities.
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
     * Build a hook-contract stable identity for a finding.
     *
     * @param Finding $finding - Native finding.
     * @param string  $scope   - Hook scope for the finding.
     *
     * @return string - 16-hex-char SHA-256 prefix.
     * @throws JsonException When identity encoding fails.
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
     * Build disambiguated hook identities for a set of findings analysed together.
     *
     * forFinding() omits line/endLine/column so an identity survives line shifts, but that also makes
     * repeated same-rule findings with no symbol and no distinguishing metadata (e.g. two
     * security.error-suppression hits in one file) collapse to one identity - hiding a newly added
     * duplicate in --baseline/--diff new-only mode. Append an occurrence ordinal within each colliding
     * group, ordered by line then column, so duplicates stay distinct while a uniform line shift keeps
     * the ordinals (and identities) stable. Reordering one duplicate above another only swaps ordinals,
     * which surfaces a pre-existing finding rather than hiding a new one - the safe direction.
     *
     * @param list<Finding> $findings - Findings identified together (current run or base snapshot).
     *
     * @return array<int, string> - Disambiguated identity keyed by spl_object_id($finding).
     * @throws JsonException When identity encoding fails.
     */
    public static function forFindings(array $findings): array
    {
        /** @var array<string, list<int>> $groups Finding indices grouped by value-independent base identity. */
        $groups = [];
        foreach ($findings as $index => $finding) {
            $groups[self::forFinding($finding, HookFindingScope::classify($finding))][] = $index;
        }

        $identities = [];
        foreach ($groups as $baseIdentity => $indices) {
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
     * Return a value-independent qualifier that distinguishes repeated same-rule findings where possible.
     *
     * @param Finding $finding - Native finding.
     * @param string  $scope   - Hook scope for the finding.
     *
     * @return array<string, bool|float|int|string|null>|string|null - qualitative identity detail.
     */
    private static function qualifier(Finding $finding, string $scope): array|string|null
    {
        if ($scope === HookFindingScope::FILE || $scope === HookFindingScope::PROJECT) {
            return null;
        }

        $qualitativeMetadata = [];
        foreach ($finding->metadata as $key => $value) {
            if (isset(self::VALUE_KEYS[$key])) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $qualitativeMetadata[$key] = $value;
            }
        }

        if ($qualitativeMetadata !== []) {
            ksort($qualitativeMetadata, SORT_STRING);

            return $qualitativeMetadata;
        }

        return preg_replace('/\d+(?:\.\d+)?/', '{n}', $finding->message);
    }
}
