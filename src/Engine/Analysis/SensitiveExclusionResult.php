<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

use GruffPhp\Results\Finding\Finding;

/**
 * Findings that survived configured exclusions, the test-path skip and section 13c, with their audit rows.
 *
 * The surviving findings are what the rest of the run sees - they feed scoring, the exit-code gate,
 * and every reporter - while the audit rows are what keeps the removed ones accountable.
 */
final readonly class SensitiveExclusionResult
{
    /**
     * Pairs the surviving findings with the audit rows explaining what was removed.
     *
     * @param list<Finding> $findings - Remaining findings, preserving report order.
     * @param list<SensitiveExclusionSummary> $summaries - Configuration order, then built-in, inline, duplicate; empty if no entries or removals.
     * @param list<array{finding: Finding, justification: string}> $suppressedFindings - Section 13c removals and reasons for SARIF.
     */
    public function __construct(
        public array $findings,
        public array $summaries,
        public array $suppressedFindings = [],
    ) {
    }

    /**
     * Totals findings suppressed across all audit rows, including built-in and section 13c filters.
     *
     * @return int - Sum of the rows' suppressed counts; zero when there are no rows or no findings were removed.
     */
    public function suppressedCount(): int
    {
        $total = 0;


        foreach ($this->summaries as $summary) {
            $total += $summary->suppressed;
        }

        return $total;
    }
}
