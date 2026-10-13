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
     * @param list<Finding>                                        $findings           - Findings no configured entry, test-path skip or section 13c filter removed, in their original order.
     * @param list<SensitiveExclusionSummary>                      $summaries          - Configured rows in declaration order, then built-in test-path, inline and duplicate rows; empty when nothing was configured or removed.
     * @param list<array{finding: Finding, justification: string}> $suppressedFindings - Findings another tool's comment or a repeated claim removed, each with its audit reason, which SARIF keeps as suppressed results (FAMILY-CONTRACT.md section 13c).
     */
    public function __construct(
        public array $findings,
        public array $summaries,
        public array $suppressedFindings = [],
    ) {
    }

    /**
     * Totals what every entry suppressed, which is the number the text report and the agent hook
     * surface so removed findings stay visible as a count.
     *
     * @return int - findings removed across all configured entries; zero when nothing is configured or nothing matched.
     */
    public function suppressedCount(): int
    {
        $total = 0;

        // Sum each entry's own count so the total always agrees with the published audit rows.
        foreach ($this->summaries as $summary) {
            $total += $summary->suppressed;
        }

        return $total;
    }
}
