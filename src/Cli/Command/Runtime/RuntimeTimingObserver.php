<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command\Runtime;

use GruffPhp\Rules\Shared\RuleRunnerObserver;

/**
 * Collects per-rule wall-clock timing while an analysis run executes.
 *
 * Plugged into `RuleRegistry::analyse()` so that, when a run is measuring performance, each rule's
 * time is accumulated here. The tallied totals let the tool report which rules cost the most of a
 * scan's wall-clock, slowest first - the numbers behind any performance breakdown shown to the user.
 */
final class RuntimeTimingObserver implements RuleRunnerObserver
{
    /** @var array<string, array{totalNs: int, invocations: int}> - Cumulative nanoseconds and callback counts keyed by rule ID. */
    private array $totals = [];

    /**
     * Records one timed rule operation in the per-rule totals.
     *
     * @param string $ruleId     - Rule identifier as declared in the rule's RuleDefinition.
     * @param int    $durationNs - Elapsed nanoseconds for the observed rule operation.
     *
     * @return void - Adds the duration and increments the rule's invocation count.
     */
    public function onRuleExecuted(string $ruleId, int $durationNs): void
    {

        if (!isset($this->totals[$ruleId])) {
            $this->totals[$ruleId] = ['totalNs' => 0, 'invocations' => 0];
        }

        $this->totals[$ruleId]['totalNs'] += $durationNs;
        $this->totals[$ruleId]['invocations']++;
    }

    /**
     * Returns the accumulated per-rule timing rows, sorted by descending total duration.
     *
     * @return list<array{ruleId: string, totalNs: int, invocations: int}> - One row per observed rule; empty before any callbacks.
     */
    public function snapshot(): array
    {
        $rows = [];


        foreach ($this->totals as $ruleId => $row) {
            $rows[] = ['ruleId' => $ruleId, 'totalNs' => $row['totalNs'], 'invocations' => $row['invocations']];
        }

        usort($rows, static fn (array $left, array $right): int => $right['totalNs'] <=> $left['totalNs']);

        return $rows;
    }
}
