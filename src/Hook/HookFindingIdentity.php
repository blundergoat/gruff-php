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
     * Return a value-independent qualifier that distinguishes repeated same-rule findings where possible.
     *
     * @param Finding $finding - Native finding.
     * @param string  $scope   - Hook scope for the finding.
     *
     * @return array<string, mixed>|string|null - qualitative identity detail.
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
