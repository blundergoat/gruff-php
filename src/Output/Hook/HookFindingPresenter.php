<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;
use JsonException;

/**
 * Converts findings to `gruff.hook.v2` entries for editor and agent consumers.
 * Supplies fallback remediation and threshold metadata defaults while preserving rule-provided metadata.
 * Orders presented batches by severity, then location and rule ID.
 */
final readonly class HookFindingPresenter
{
    /**
     * Builds the single JSON finding entry the `hook` command emits for one result, copying the
     * reportable fields and attaching the ratified family identity plus a freshly recomputed fingerprint.
     *
     * @param Finding     $finding        - The internal finding to reshape for the hook contract.
     * @param string|null $stableIdentity - The ratified family identity, or null for a sensitive finding, which the family never names.
     * @param int         $symbolOrdinal  - 1-based declaration ordinal of the finding's symbol in its file; 0 when it names no symbol.
     * @param string|null $baselineStatus - What an applied baseline made of this finding; null when no baseline was applied.
     *
     * @return array<string, mixed> - JSON-ready hook finding entry with `metadata` always an object, never an empty list.
     * @throws JsonException - When the fingerprint cannot be encoded.
     */
    public function toArray(Finding $finding, ?string $stableIdentity, int $symbolOrdinal = 0, ?string $baselineStatus = null): array
    {
        $scope   = HookFindingScope::classify($finding);
        $payload = [
            'ruleId'         => $finding->ruleId,
            'pillar'         => $finding->pillar->value,
            'severity'       => $finding->severity->value,
            'confidence'     => $finding->confidence->value,
            'scope'          => $scope,
            'file'           => $finding->filePath,
            'line'           => $finding->line,
            'endLine'        => $this->endLine($finding),
            'symbol'         => $finding->symbol,
            'symbolOrdinal'  => $symbolOrdinal,
            'message'        => $finding->message,
            'remediation'    => $finding->remediation ?? sprintf('Address the %s finding or configure the rule if this is intentional.', $finding->ruleId),
            'baselineStatus' => $baselineStatus,
            'metadata'       => $this->metadata($finding),
            'stableIdentity' => $stableIdentity,
            'fingerprint'    => $finding->fingerprint(),
        ];

        // Encode empty metadata as a JSON object so consumers receive a keyed map.
        if ($payload['metadata'] === []) {
            $payload['metadata'] = (object)[];
        }

        return $payload;
    }

    /**
     * Reports a valid span end for the hook payload, falling back to the start line.
     * A missing start line yields null even if an end line was supplied.
     *
     * @param Finding $finding - The finding whose span end is being reported.
     *
     * @return int|null - End line, or the start for an absent or reversed end; null without a start line.
     */
    private function endLine(Finding $finding): ?int
    {

        if ($finding->line === null) {
            return null;
        }


        return $finding->endLine !== null && $finding->endLine >= $finding->line ? $finding->endLine : $finding->line;
    }

    /**
     * Orders an already-presented batch worst-first - severity descending, then file, line, and rule
     * id - so an editor or agent surfaces the most serious findings at the top of its list.
     *
     * @param list<array<string, mixed>> $findings - Presented hook findings to order; an empty list comes back unchanged.
     *
     * @return list<array<string, mixed>> - The same findings ordered worst-first for display.
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
     * Adds measured, threshold, unit, and direction defaults when metadata has a non-null threshold.
     * Rule-provided values override these defaults; missing or null thresholds leave metadata unchanged.
     *
     * @param Finding $finding - Finding whose metadata is prepared for the hook payload.
     *
     * @return array<string, mixed> - Original metadata, enriched with defaults only for a non-null threshold.
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
     * Digs the finding's measured value out of its metadata - the "N" in "you are at N, limit M", though
     * the value may be any scalar - checking the keys this rule is known to use before falling back to any leftover number.
     *
     * @param Finding $finding - The finding whose threshold measurement is being extracted.
     *
     * @return bool|float|int|string|null - First preferred scalar or fallback number; null when neither is present.
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
            // Skip the limit and its type; they describe the boundary, not the observed value.
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
     * Names the metadata keys each rule stores its measurement under, giving `measuredValue()` a short
     * priority list to check before it resorts to a blind numeric scan.
     *
     * @param string $ruleId - The rule whose finding is being presented.
     *
     * @return list<string> - Candidate metadata keys in priority order; a generic `lines`/`count` pair for rules with no specific mapping.
     */
    private function measuredKeys(string $ruleId): array
    {
        return match ($ruleId) {
            'complexity.cognitive' => ['complexity'],
            'complexity.halstead-volume' => ['volume'],
            'complexity.maintainability-index' => ['maintainabilityIndex'],
            'complexity.nesting-depth' => ['depth'],
            'docs.todo-density' => ['count'],
            'size.parameter-count' => ['parameters'],
            'size.property-count' => ['properties'],
            'size.public-method-count' => ['publicMethods'],
            default => ['lines', 'count'],
        };
    }

    /**
     * Labels the measurement's unit - `lines`, `levels`, `score`, and so on - so a consumer can print
     * "42 lines" or "5 levels" beside the threshold instead of a bare number.
     *
     * @param Finding $finding - The finding whose measurement needs a unit label.
     *
     * @return string - The unit shown beside the measured value; `count` for any rule with no more specific unit.
     */
    private function unit(Finding $finding): string
    {
        return match ($finding->ruleId) {
            'complexity.cognitive',
            'complexity.maintainability-index' => 'score',
            'complexity.halstead-volume' => 'volume',
            'complexity.nesting-depth' => 'levels',
            'size.class-length',
            'size.file-length',
            'size.method-length' => 'lines',
            default => 'count',
        };
    }

    /**
     * Says which way the limit was crossed so a consumer words the breach correctly: the maintainability
     * index fails by dropping too low, every other threshold fails by climbing too high.
     *
     * @param Finding $finding - The finding whose breach direction is being inferred.
     *
     * @return string - `below` for the maintainability index, `above` for every other threshold rule.
     */
    private function direction(Finding $finding): string
    {
        return $finding->ruleId === 'complexity.maintainability-index' ? 'below' : 'above';
    }

    /**
     * Turns a severity word into a sortable rank so `sort()` can push errors above warnings above
     * advisories when ordering a batch for display.
     *
     * @param string $severity - The finding's severity word, such as `error`, `warning`, or `advisory`.
     *
     * @return int - Sort rank where higher means more severe; an unrecognised severity ranks 0 and sinks to the bottom.
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
