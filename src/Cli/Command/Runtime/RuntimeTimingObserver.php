<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command\Runtime;

use GruffPhp\Rules\Shared\RuleRunnerObserver;

/**
 * Collects per-rule wall-clock totals reported by RuleRegistry::analyse().
 */
final class RuntimeTimingObserver implements RuleRunnerObserver
{
    /** @var array<string, array{totalNs: int, invocations: int}> */
    private array $totals = [];

    /**
     * Accumulate one rule invocation's duration into the per-rule totals.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string $ruleId - Rule identifier as declared in the rule's RuleDefinition.
     * @param int    $durationNs - Wall-clock nanoseconds the rule spent in analyse().
     *
     * @return void
     */
    public function onRuleExecuted(string $ruleId, int $durationNs): void
    {
        // User view: choose the terminal output branch for this case.
        if (!isset($this->totals[$ruleId])) {
            $this->totals[$ruleId] = ['totalNs' => 0, 'invocations' => 0];
        }

        $this->totals[$ruleId]['totalNs'] += $durationNs;
        $this->totals[$ruleId]['invocations']++;
    }

    /**
     * Emit the collected per-rule totals as a stable, sorted list.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @return list<array{ruleId: string, totalNs: int, invocations: int}> - Ordered by descending total time.
     */
    public function snapshot(): array
    {
        $rows = [];

        // User view: add each item that can appear in terminal output.
        foreach ($this->totals as $ruleId => $row) {
            $rows[] = ['ruleId' => $ruleId, 'totalNs' => $row['totalNs'], 'invocations' => $row['invocations']];
        }

        usort($rows, static fn (array $left, array $right): int => $right['totalNs'] <=> $left['totalNs']);

        return $rows;
    }
}
