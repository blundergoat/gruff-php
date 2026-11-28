<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;

/**
 * Filters rendered findings by severity, pillar and rule ID; callers apply it after scoring and exit-gate evaluation.
 * Empty include lists impose no allowlist restriction; matching exclusions still reject findings.
 * With a null severity floor and empty lists, every input finding is retained.
 */
final readonly class FindingDisplayFilter
{
    /**
     * Stores the display predicates; default arguments leave every filter inactive.
     *
     * @param Severity|null $minSeverity - Minimum visible severity; null disables the severity filter.
     * @param list<Pillar> $includePillars - Allowed pillars; an empty list adds no pillar allowlist.
     * @param list<Pillar> $excludePillars - Hidden pillars; exclusions take precedence over the pillar allowlist.
     * @param list<string> $includeRules - Allowed rule IDs; an empty list adds no rule allowlist.
     * @param list<string> $excludeRules - Hidden rule IDs; exclusions take precedence over the rule allowlist.
     */
    public function __construct(
        public ?Severity $minSeverity = null,
        public array     $includePillars = [],
        public array     $excludePillars = [],
        public array     $includeRules = [],
        public array     $excludeRules = [],
    ) {
    }

    /**
     * Uses the configured severity floor only when this filter has no floor.
     * An existing floor takes precedence; a null configured floor leaves this instance unchanged.
     *
     * @param Severity|null $configuredFloor - Project display floor; null leaves this filter unchanged.
     *
     * @return self - This instance if a floor is already set or none is configured; otherwise a copy with the configured floor.
     */
    public function withConfiguredFloor(?Severity $configuredFloor): self
    {

        if ($this->minSeverity !== null || $configuredFloor === null) {
            return $this;
        }

        return new self(
            minSeverity:    $configuredFloor,
            includePillars: $this->includePillars,
            excludePillars: $this->excludePillars,
            includeRules:   $this->includeRules,
            excludeRules:   $this->excludeRules,
        );
    }

    /**
     * Returns findings that satisfy every active display filter, preserving their input order.
     *
     * @param list<Finding> $findings - Findings to filter; an empty input returns an empty list.
     *
     * @return list<Finding> - Matching findings with consecutive zero-based keys; empty when no finding matches.
     */
    public function apply(array $findings): array
    {
        return array_values(array_filter($findings, fn(Finding $finding): bool => $this->allows($finding)));
    }

    /**
     * Reports whether any filter is configured, even if it excludes no findings in the current report.
     *
     * @return bool - True for a non-null severity floor or any non-empty include or exclude list.
     */
    public function isActive(): bool
    {

        return $this->minSeverity !== null
               || $this->includePillars !== []
               || $this->excludePillars !== []
               || $this->includeRules !== []
               || $this->excludeRules !== [];
    }

    /**
     * Flattens the filter into a plain array for the report's `run.filters` block, so a saved JSON or
     * Markdown report records exactly which flags shaped the findings it lists.
     *
     * @return array{active: bool, minSeverity: string|null, includePillars: list<string>, excludePillars: list<string>, includeRules: list<string>, excludeRules: list<string>} - Snapshot for saved reports.
     *     Null minSeverity means no floor; each empty list adds no restriction of its own.
     */
    public function toArray(): array
    {
        return [
            'active'         => $this->isActive(),
            'minSeverity'    => $this->minSeverity?->value,
            'includePillars' => array_map(static fn(Pillar $pillar): string => $pillar->value, $this->includePillars),
            'excludePillars' => array_map(static fn(Pillar $pillar): string => $pillar->value, $this->excludePillars),
            'includeRules'   => $this->includeRules,
            'excludeRules'   => $this->excludeRules,
        ];
    }

    /**
     * Tests one finding against the severity floor and the pillar and rule include and exclude lists.
     * The apply method uses this result to retain only visible findings.
     *
     * @param Finding $finding - The finding being tested against the severity floor and the pillar and rule include/exclude lists.
     *
     * @return bool - True when the finding passes every active filter; false when apply should omit it.
     */
    private function allows(Finding $finding): bool
    {

        if ($this->minSeverity !== null && $this->severityRank($finding->severity) < $this->severityRank($this->minSeverity)) {
            return false;
        }


        if ($this->includePillars !== [] && !in_array($finding->pillar, $this->includePillars, true)) {
            return false;
        }

        // Pillar exclusions take precedence over the allowlist.
        if (in_array($finding->pillar, $this->excludePillars, true)) {
            return false;
        }


        if ($this->includeRules !== [] && !in_array($finding->ruleId, $this->includeRules, true)) {
            return false;
        }


        return !in_array($finding->ruleId, $this->excludeRules, true);
    }

    /**
     * Defines the severity order used by the minimum-severity filter, with advisory lowest and error highest.
     *
     * @param Severity $severity - The finding severity being ranked for the minimum-severity comparison.
     *
     * @return int - Ordering rank where a larger value represents a more severe finding.
     */
    private function severityRank(Severity $severity): int
    {
        return match ($severity) {
            Severity::Advisory => 1,
            Severity::Warning => 2,
            Severity::Error => 3,
        };
    }
}
