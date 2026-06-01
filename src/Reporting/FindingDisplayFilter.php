<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\Severity;

/**
 * Applies display filters before findings are rendered.
 */
final readonly class FindingDisplayFilter
{
    /**
     * @param Severity|null $minSeverity    Minimum severity that should be displayed.
     * @param list<Pillar>  $includePillars Pillars explicitly included in output.
     * @param list<Pillar>  $excludePillars Pillars explicitly excluded from output.
     * @param list<string>  $includeRules   Rule ids explicitly included in output.
     * @param list<string>  $excludeRules   Rule ids explicitly excluded from output.
     */
    public function __construct(
        public ?Severity $minSeverity = null,
        public array $includePillars = [],
        public array $excludePillars = [],
        public array $includeRules = [],
        public array $excludeRules = [],
    ) {
    }

    /**
     * Keep only findings visible under the selected display filter.
     *
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function apply(array $findings): array
    {
        // Re-key to a list so the result stays a 0-indexed list<Finding> after filtered slots are removed.
        return array_values(array_filter($findings, fn (Finding $finding): bool => $this->allows($finding)));
    }

    /**
     * Check whether any display filter is configured.
     *
     * @return bool True when at least one filter is active.
     */
    public function isActive(): bool
    {
        // Any single configured dimension counts as active; callers use this to decide whether to annotate output.
        return $this->minSeverity !== null
            || $this->includePillars !== []
            || $this->excludePillars !== []
            || $this->includeRules !== []
            || $this->excludeRules !== [];
    }

    /**
     * @return array{
     *     active: bool,
     *     minSeverity: string|null,
     *     includePillars: list<string>,
     *     excludePillars: list<string>,
     *     includeRules: list<string>,
     *     excludeRules: list<string>
     * }
     */
    public function toArray(): array
    {
        // Enums are flattened to their string values so the filter survives JSON serialisation in reports.
        return [
            'active' => $this->isActive(),
            'minSeverity' => $this->minSeverity?->value,
            'includePillars' => array_map(static fn (Pillar $pillar): string => $pillar->value, $this->includePillars),
            'excludePillars' => array_map(static fn (Pillar $pillar): string => $pillar->value, $this->excludePillars),
            'includeRules' => $this->includeRules,
            'excludeRules' => $this->excludeRules,
        ];
    }

    /**
     * Determine whether one finding passes all configured filters.
     *
     * @param Finding $finding Finding under test against severity floor and pillar/rule include-exclude sets.
     * @return bool True when the finding should be displayed.
     */
    private function allows(Finding $finding): bool
    {
        if ($this->minSeverity !== null && $this->severityRank($finding->severity) < $this->severityRank($this->minSeverity)) {
            // Below the severity floor: drop it before any pillar or rule check runs.
            return false;
        }

        if ($this->includePillars !== [] && !in_array($finding->pillar, $this->includePillars, true)) {
            // An include set is an allowlist; a pillar outside it is excluded.
            return false;
        }

        if (in_array($finding->pillar, $this->excludePillars, true)) {
            // Exclude wins over include for pillars already passed above.
            return false;
        }

        if ($this->includeRules !== [] && !in_array($finding->ruleId, $this->includeRules, true)) {
            // Same allowlist semantics at the rule-id granularity.
            return false;
        }

        // Survived every gate; display unless the rule id is explicitly excluded.
        return !in_array($finding->ruleId, $this->excludeRules, true);
    }

    /**
     * Convert severity to a comparable rank.
     *
     * @param Severity $severity Severity whose ordering position is needed for the minimum-severity comparison.
     * @return int Severity rank where larger is more severe.
     */
    private function severityRank(Severity $severity): int
    {
        // Explicit ranks let severities be compared as integers since the enum itself has no inherent ordering.
        return match ($severity) {
            Severity::Advisory => 1,
            Severity::Warning => 2,
            Severity::Error => 3,
        };
    }
}
