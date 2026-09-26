<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

use GruffPhp\Results\Finding\Finding;

/**
 * What one run's configured sensitive exclusions did: the findings that survived, and one audit row
 * per configured entry.
 *
 * The surviving findings are what the rest of the run sees - they feed scoring, the exit-code gate,
 * and every reporter - while the audit rows are what keeps the removed ones accountable.
 */
final readonly class SensitiveExclusionResult
{
    /**
     * Pairs the surviving findings with the audit rows explaining what was removed.
     *
     * @param list<Finding>                    $findings - Findings no configured entry matched, in their original order.
     * @param list<SensitiveExclusionSummary>  $summaries - One row per configured entry, in configuration order; empty when nothing is configured.
     */
    public function __construct(
        public array $findings,
        public array $summaries,
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
