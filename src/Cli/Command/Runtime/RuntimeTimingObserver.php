<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command\Runtime;

use GruffPhp\Rules\Shared\RuleRunnerObserver;

/**
 * Collects per-rule wall-clock timing while an analysis run executes.
 *
 * Plugged into `RuleRegistry::analyse()` so that, when a run is measuring performance, each rule's
 * time is accumulated here. The tallied totals let the tool report which rules cost the most of a
 * scan's wall-clock, slowest first — the numbers behind any performance breakdown shown to the user.
 */
final class RuntimeTimingObserver implements RuleRunnerObserver
{
    /** @var array<string, array{totalNs: int, invocations: int}> */
    private array $totals = [];

    /**
     * Records one rule invocation's duration, so per-rule totals build up as the scan runs.
     *
     * @param string $ruleId     - Rule identifier as declared in the rule's RuleDefinition.
     * @param int    $durationNs - Wall-clock nanoseconds the rule spent in analyse().
     *
     * @return void
     */
    public function onRuleExecuted(string $ruleId, int $durationNs): void
    {
        // First time this rule reports in: open its running total before adding to it.
        if (!isset($this->totals[$ruleId])) {
            $this->totals[$ruleId] = ['totalNs' => 0, 'invocations' => 0];
        }

        $this->totals[$ruleId]['totalNs'] += $durationNs;
        $this->totals[$ruleId]['invocations']++;
    }

    /**
     * Produces the final per-rule timing table, slowest first — the order the user reads it in.
     *
     * @return list<array{ruleId: string, totalNs: int, invocations: int}> - Ordered by descending total time.
     */
    public function snapshot(): array
    {
        $rows = [];

        // Flatten the keyed totals into a plain list so it can be sorted and rendered.
        foreach ($this->totals as $ruleId => $row) {
            $rows[] = ['ruleId' => $ruleId, 'totalNs' => $row['totalNs'], 'invocations' => $row['invocations']];
        }

        usort($rows, static fn (array $left, array $right): int => $right['totalNs'] <=> $left['totalNs']);

        return $rows;
    }
}
