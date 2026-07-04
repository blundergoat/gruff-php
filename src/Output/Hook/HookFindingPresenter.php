<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;
use JsonException;

/**
 * Shapes internal `Finding` objects into the `gruff.hook.v1` finding entries the `hook` command
 * emits to an editor or coding agent.
 *
 * This is the last pass before a finding leaves the tool: it copies the reportable fields, fills in
 * a default remediation line when the rule offered none, and rewrites raw threshold metadata into
 * the uniform `measured`/`threshold`/`unit`/`direction` shape a consumer can render as "you are at
 * N, the limit is M" without knowing which rule fired. It also sorts a batch worst-first so the
 * caller shows the most serious findings at the top of its list.
 */
final readonly class HookFindingPresenter
{
    /**
     * Builds the single JSON finding entry the `hook` command emits for one result, copying the
     * reportable fields and attaching the caller's stable identity plus a freshly recomputed fingerprint.
     *
     * @param Finding $finding        - The internal finding to reshape for the hook contract.
     * @param string  $stableIdentity - Disambiguated hook identity for this finding, resolved across the full result set so no two entries collide.
     *
     * @return array<string, mixed> - JSON-ready hook finding entry with `metadata` always an object, never an empty list.
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

        // With no metadata to report, swap the empty array for an object so the JSON shows `"metadata": {}` and a consumer can always treat it as a keyed map, never a list.
        if ($payload['metadata'] === []) {
            $payload['metadata'] = (object)[];
        }

        return $payload;
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
     * Rewrites a threshold rule's raw metadata into the uniform `measured`/`threshold`/`unit`/`direction`
     * shape the hook contract promises, so a consumer can render the measurement against its limit without
     * knowing the rule. Non-threshold findings pass straight through.
     *
     * @param Finding $finding - The finding whose metadata is being normalised for the hook payload.
     *
     * @return array<string, mixed> - The normalised shape for a threshold finding, or the rule's raw metadata untouched for a non-threshold one; empty when the rule attached none, which a consumer shows as a finding with no measured detail.
     */
    private function metadata(Finding $finding): array
    {
        $metadata = $finding->metadata;

        // A finding with no `threshold` key is not a limit breach - a naming or docs rule, say - so hand its metadata back untouched rather than inventing measured and limit fields.
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
     * @return bool|float|int|string|null - The measured value to display; null when none was found, so the consumer shows the limit with no measured figure beside it.
     */
    private function measuredValue(Finding $finding): bool|float|int|string|null
    {
        // First try the metadata keys this rule is known to store its measurement under, since those hold the measurement worth showing.
        foreach ($this->measuredKeys($finding->ruleId) as $key) {
            $value = $finding->metadata[$key] ?? null;
            // Take the first known key that actually holds a scalar; that is the measurement the consumer will render.
            if (is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
                return $value;
            }
        }

        // No known key matched, so scan the rest of the metadata for any numeric value we can report instead.
        foreach ($finding->metadata as $key => $value) {
            // Skip the limit and its type - those describe the boundary, not how far past it the code went.
            if ($key === 'threshold' || $key === 'thresholdType') {
                continue;
            }

            // Any remaining number is a fair stand-in for the measurement, so surface the first one found.
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
