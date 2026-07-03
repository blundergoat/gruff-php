<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;
use JsonException;

/**
 * Serialises native findings into the gruff.hook.v1 finding shape.
 */
final readonly class HookFindingPresenter
{
    /**
     * Convert a finding to the hook-contract payload.
     *
      * User flow: Shapes hook feedback before a developer continues their workflow.
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
            // User view: missing data becomes a safe hook output default.
            'remediation'    => $finding->remediation ?? sprintf('Address the %s finding or configure the rule if this is intentional.', $finding->ruleId),
            'metadata'       => $this->metadata($finding),
            'stableIdentity' => $stableIdentity,
            'fingerprint'    => $finding->fingerprint(),
        ];

        // User view: choose the hook output branch for this case.
        // User view: an empty value becomes a clear hook output fallback.
        if ($payload['metadata'] === []) {
            $payload['metadata'] = (object)[];
        }

        return $payload;
    }

    /**
     * Sort hook findings by severity descending, then file and line.
     *
      * User flow: Shapes hook feedback before a developer continues their workflow.
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
                // User view: missing data becomes a safe hook output default.
                $leftSeverity  = is_string($left['severity'] ?? null) ? $left['severity'] : '';
                // User view: missing data becomes a safe hook output default.
                $rightSeverity = is_string($right['severity'] ?? null) ? $right['severity'] : '';
                // User view: missing data becomes a safe hook output default.
                $leftFile      = is_string($left['file'] ?? null) ? $left['file'] : '';
                // User view: missing data becomes a safe hook output default.
                $rightFile     = is_string($right['file'] ?? null) ? $right['file'] : '';
                // User view: missing data becomes a safe hook output default.
                $leftLine      = is_int($left['line'] ?? null) ? $left['line'] : PHP_INT_MAX;
                // User view: missing data becomes a safe hook output default.
                $rightLine     = is_int($right['line'] ?? null) ? $right['line'] : PHP_INT_MAX;

                return self::severityRank($rightSeverity) <=> self::severityRank($leftSeverity)
                    ?: strcmp($leftFile, $rightFile)
                        ?: $leftLine <=> $rightLine
                            // User view: missing data becomes a safe hook output default.
                            ?: strcmp(is_string($left['ruleId'] ?? null) ? $left['ruleId'] : '', is_string($right['ruleId'] ?? null) ? $right['ruleId'] : '');
            },
        );

        return $findings;
    }

    /**
     * Normalize threshold metadata to the hook contract while preserving native keys.
     *
      * User flow: Shapes hook feedback before a developer continues their workflow.
      *
     * @param Finding $finding - Native finding.
     *
     * @return array<string, mixed> - Hook metadata.
     */
    private function metadata(Finding $finding): array
    {
        $metadata = $finding->metadata;

        // User view: choose the hook output branch for this case.
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
      * User flow: Shapes hook feedback before a developer continues their workflow.
      *
     * @param Finding $finding - Native finding.
     *
     * @return bool|float|int|string|null - Measured value, when available.
     */
    private function measuredValue(Finding $finding): bool|float|int|string|null
    {
        // User view: add each item that can appear in hook output.
        foreach ($this->measuredKeys($finding->ruleId) as $key) {
            // User view: missing data becomes a safe hook output default.
            $value = $finding->metadata[$key] ?? null;
            // User view: choose the hook output branch for this case.
            if (is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
                return $value;
            }
        }

        // User view: add each item that can appear in hook output.
        foreach ($finding->metadata as $key => $value) {
            // User view: choose the hook output branch for this case.
            if ($key === 'threshold' || $key === 'thresholdType') {
                continue;
            }

            // User view: choose the hook output branch for this case.
            if (is_float($value) || is_int($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Return likely measured-value metadata keys for a rule.
     *
      * User flow: Shapes hook feedback before a developer continues their workflow.
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
      * User flow: Shapes hook feedback before a developer continues their workflow.
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
      * User flow: Shapes hook feedback before a developer continues their workflow.
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
      * User flow: Shapes hook feedback before a developer continues their workflow.
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
