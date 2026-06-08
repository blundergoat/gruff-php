<?php

declare(strict_types=1);

namespace GruffPhp\Hook;

use GruffPhp\Finding\Finding;
use JsonException;

/**
 * Serialises native findings into the gruff.hook.v1 finding shape.
 */
final readonly class HookFindingPresenter
{
    /**
     * Convert a finding to the hook-contract payload.
     *
     * @param Finding $finding        - Native finding.
     * @param string  $stableIdentity - Disambiguated hook identity for this finding, resolved across the full result set.
     *
     * @return array<string, mixed> - JSON-ready hook finding.
     * @throws JsonException When the fingerprint cannot be encoded.
     */
    public function toArray(Finding $finding, string $stableIdentity): array
    {
        $scope   = HookFindingScope::classify($finding);
        $payload = [
            'ruleId'         => $finding->ruleId,
            'pillar'         => $finding->pillar->value,
            'severity'       => $finding->severity->value,
            'scope'          => $scope,
            'file'           => $finding->filePath,
            'line'           => $finding->line,
            'endLine'        => $finding->endLine,
            'symbol'         => $finding->symbol,
            'message'        => $finding->message,
            'remediation'    => $finding->remediation ?? sprintf('Address the %s finding or configure the rule if this is intentional.', $finding->ruleId),
            'metadata'       => $this->metadata($finding),
            'stableIdentity' => $stableIdentity,
            'fingerprint'    => $finding->fingerprint(),
        ];

        if ($payload['metadata'] === []) {
            $payload['metadata'] = (object)[];
        }

        return $payload;
    }

    /**
     * Sort hook findings by severity descending, then file and line.
     *
     * @param list<array<string, mixed>> $findings - Presented findings.
     *
     * @return list<array<string, mixed>> - Sorted findings.
     */
    public function sort(array $findings): array
    {
        usort(
            $findings,
            static function (array $left, array $right): int {
                $leftSeverity  = is_string($left['severity'] ?? null) ? $left['severity'] : '';
                $rightSeverity = is_string($right['severity'] ?? null) ? $right['severity'] : '';
                $leftFile      = is_string($left['file'] ?? null) ? $left['file'] : '';
                $rightFile     = is_string($right['file'] ?? null) ? $right['file'] : '';
                $leftLine      = is_int($left['line'] ?? null) ? $left['line'] : PHP_INT_MAX;
                $rightLine     = is_int($right['line'] ?? null) ? $right['line'] : PHP_INT_MAX;

                return self::severityRank($rightSeverity) <=> self::severityRank($leftSeverity)
                    ?: strcmp($leftFile, $rightFile)
                        ?: $leftLine <=> $rightLine
                            ?: strcmp(is_string($left['ruleId'] ?? null) ? $left['ruleId'] : '', is_string($right['ruleId'] ?? null) ? $right['ruleId'] : '');
            },
        );

        return $findings;
    }

    /**
     * Normalize threshold metadata to the hook contract while preserving native keys.
     *
     * @param Finding $finding - Native finding.
     *
     * @return array<string, mixed> - Hook metadata.
     */
    private function metadata(Finding $finding): array
    {
        $metadata = $finding->metadata;

        if (!isset($metadata['threshold'])) {
            return $metadata;
        }

        $measured   = $this->measuredValue($finding);
        $normalized = [
            'measured'  => $measured,
            'threshold' => $metadata['threshold'],
            'unit'      => $this->unit($finding),
            'direction' => $this->direction($finding),
        ];

        return array_merge($normalized, $metadata);
    }

    /**
     * Pick the measured value from native threshold metadata.
     *
     * @param Finding $finding - Native finding.
     *
     * @return bool|float|int|string|null - Measured value, when available.
     */
    private function measuredValue(Finding $finding): bool|float|int|string|null
    {
        foreach ($this->measuredKeys($finding->ruleId) as $key) {
            $value = $finding->metadata[$key] ?? null;
            if (is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
                return $value;
            }
        }

        foreach ($finding->metadata as $key => $value) {
            if ($key === 'threshold' || $key === 'thresholdType') {
                continue;
            }

            if (is_float($value) || is_int($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Return likely measured-value metadata keys for a rule.
     *
     * @param string $ruleId - Rule identifier.
     *
     * @return list<string> - Candidate metadata keys in priority order.
     */
    private function measuredKeys(string $ruleId): array
    {
        return match ($ruleId) {
            'complexity.cognitive',
            'complexity.cyclomatic' => ['complexity'],
            'complexity.halstead-volume' => ['volume'],
            'complexity.maintainability-index' => ['maintainabilityIndex'],
            'complexity.nesting-depth' => ['depth'],
            'docs.todo-density' => ['count'],
            'size.average-method-length' => ['averageLength'],
            'size.parameter-count' => ['parameters'],
            'size.property-count' => ['properties'],
            'size.public-method-count' => ['publicMethods'],
            default => ['lines', 'count'],
        };
    }

    /**
     * Infer a human-stable unit for threshold metadata.
     *
     * @param Finding $finding - Native finding.
     *
     * @return string - Unit label.
     */
    private function unit(Finding $finding): string
    {
        return match ($finding->ruleId) {
            'complexity.cognitive',
            'complexity.cyclomatic',
            'complexity.maintainability-index' => 'score',
            'complexity.halstead-volume' => 'volume',
            'complexity.nesting-depth' => 'levels',
            'size.average-method-length',
            'size.class-length',
            'size.file-length',
            'size.method-length' => 'lines',
            default => 'count',
        };
    }

    /**
     * Infer whether a threshold is breached above or below the limit.
     *
     * @param Finding $finding - Native finding.
     *
     * @return string - above or below.
     */
    private function direction(Finding $finding): string
    {
        return $finding->ruleId === 'complexity.maintainability-index' ? 'below' : 'above';
    }

    /**
     * Severity rank used by hook output sorting.
     *
     * @param string $severity - Severity value.
     *
     * @return int - Higher means more severe.
     */
    private static function severityRank(string $severity): int
    {
        return match ($severity) {
            'error' => 3,
            'warning' => 2,
            'advisory' => 1,
            default => 0,
        };
    }
}
