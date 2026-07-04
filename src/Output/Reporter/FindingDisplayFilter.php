<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;

/**
 * The last gate findings pass through before they reach the user's screen or saved report.
 *
 * A scan usually turns up far more findings than someone wants to read at once, so `analyse` lets
 * the user narrow the view with `--min-severity`, `--include-pillar` / `--exclude-pillar`, and
 * `--include-rule` / `--exclude-rule` (the `report` command forwards the same flags). This object
 * captures that chosen view and, handed the full finding list, returns only the ones that clear
 * every active filter. When the user sets no display flags it is inert and passes everything
 * straight through, so `analyse` can always run it without checking first.
 */
final readonly class FindingDisplayFilter
{
    /**
     * Captures the display flags exactly as the user set them; every argument defaults to "unset",
     * which is what a bare `analyse` with no filter flags passes.
     *
     * @param Severity|null $minSeverity - Severity floor from `--min-severity`; null means no floor, so even advisory findings show.
     * @param list<Pillar>  $includePillars - Allowlist of pillars from `--include-pillar`; empty means every pillar is shown.
     * @param list<Pillar>  $excludePillars - Pillars to hide from `--exclude-pillar`; empty means no pillar is hidden.
     * @param list<string>  $includeRules - Allowlist of rule ids from `--include-rule`; empty means every rule's findings are shown.
     * @param list<string>  $excludeRules - Rule ids to hide from `--exclude-rule`; empty means no rule is hidden.
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
     * Winnows the full finding list down to just what the active filters allow - `analyse` applies this
     * before it builds the report, so the user sees only the findings their flags asked for.
     *
     * @param list<Finding> $findings - Every finding the run produced, before display filtering; an empty list simply yields an empty result.
     *
     * @return list<Finding> - Findings that clear every active filter, re-keyed to a 0-indexed list; empty when the filters (or an empty input) leave nothing to show.
     */
    public function apply(array $findings): array
    {
        return array_values(array_filter($findings, fn(Finding $finding): bool => $this->allows($finding)));
    }

    /**
     * Reports whether the user narrowed the view at all, so a reporter can note that the score and exit
     * code still reflect the full finding set even though the displayed list is filtered.
     *
     * @return bool - True when any one filter dimension is set; false on a bare run where nothing was hidden.
     */
    public function isActive(): bool
    {
        // Any single dimension being set counts as active - one `--exclude-rule` is enough to filter the view.
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
     * @return array{
     *     active: bool,
     *     minSeverity: string|null,
     *     includePillars: list<string>,
     *     excludePillars: list<string>,
     *     includeRules: list<string>,
     *     excludeRules: list<string>
     * } - JSON-serialisable snapshot of the filter; enums flattened to string values, null `minSeverity` means no floor, and an empty list means that
     * dimension is unfiltered.
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
     * Decides a single finding's fate against every filter in turn - the per-finding test behind
     * `apply()`, returning true only when the finding earns a place in what the user sees.
     *
     * @param Finding $finding - The finding being tested against the severity floor and the pillar and rule include/exclude lists.
     *
     * @return bool - True when the finding clears the severity floor and every pillar/rule gate; false quietly drops it from the output.
     */
    private function allows(Finding $finding): bool
    {
        // The finding sits below the `--min-severity` floor, so it is muted before any pillar or rule check runs.
        if ($this->minSeverity !== null && $this->severityRank($finding->severity) < $this->severityRank($this->minSeverity)) {
            return false;
        }

        // The user pinned the view to certain pillars with `--include-pillar`, and this finding's pillar is not among them.
        if ($this->includePillars !== [] && !in_array($finding->pillar, $this->includePillars, true)) {
            return false;
        }

        // The user hid this pillar with `--exclude-pillar`, which wins even though it cleared the include check above.
        if (in_array($finding->pillar, $this->excludePillars, true)) {
            return false;
        }

        // The user narrowed the view to specific rule ids with `--include-rule`, and this finding came from a different rule.
        if ($this->includeRules !== [] && !in_array($finding->ruleId, $this->includeRules, true)) {
            return false;
        }

        // It cleared every earlier gate, so show it unless the user muted this exact rule with `--exclude-rule`.
        return !in_array($finding->ruleId, $this->excludeRules, true);
    }

    /**
     * Turns a severity into a plain number so the `--min-severity` floor can be applied with a simple
     * comparison; ranks rise with seriousness, advisory lowest and error highest.
     *
     * @param Severity $severity - The finding severity being ranked for the minimum-severity comparison.
     *
     * @return int - Ordering rank where a larger value is more severe, letting the severity floor compare with a numeric `>=`.
     */
    private function severityRank(Severity $severity): int
    {
        // Severities carry no built-in order, so map each to an explicit rank the `--min-severity` floor can compare numerically.
        return match ($severity) {
            Severity::Advisory => 1,
            Severity::Warning => 2,
            Severity::Error => 3,
        };
    }
}
