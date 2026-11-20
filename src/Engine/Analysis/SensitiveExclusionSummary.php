<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

/**
 * One audit row for a configured exclusion, the built-in test-path skip, or a section 13c filter.
 * Configured entries each produce a row, including `suppressed: 0` when nothing matched.
 *
 * Configured rows carry their declared scope and rationale; other rows use the finding's rule and path.
 * Built-in and section 13c reasons come from the contract, never from a matched value.
 *
 * @phpstan-type SensitiveExclusionSummaryArray array{index: int, rule: string, paths: list<string>, symbol: string|null, source?: string, reason: string,
 *               suppressed: int}
 */
final readonly class SensitiveExclusionSummary
{
    /**
     * Holds one audit row's scope, suppressed count and optional source.
     *
     * @param int $index - Configured entry position, or index in the separate built-in/section 13c sequence.
     * @param string $rule - Rule id whose findings this row counts.
     * @param string $path - Configured display path, or the path reported by a skipped finding.
     * @param string|null $symbol - Configured symbol scope; null for whole-file, built-in and section 13c rows.
     * @param string $reason - Configured rationale or the contract's fixed reason for this source.
     * @param int $suppressed - Findings removed for this row; zero also records an unmatched configured entry.
     * @param string|null $source - `built-in`, `inline` or `duplicate`; null identifies a configured exclusion.
     */
    public function __construct(
        public int     $index,
        public string  $rule,
        public string  $path,
        public ?string $symbol,
        public string  $reason,
        public int     $suppressed,
        public ?string $source = null,
    ) {
    }

    /**
     * Flattens the row into the family audit shape carried by the report's `suppressions` array.
     * `paths` is a list because the family shape is shared with ports whose ordinary exclusions
     * accept several paths; a sensitive exclusion always names exactly one.
     *
     * @return SensitiveExclusionSummaryArray - Audit fields with one path; includes source only for a non-configured row.
     */
    public function toArray(): array
    {
        $auditRow = [
            'index' => $this->index,
            'rule' => $this->rule,
            'paths' => [$this->path],
            'symbol' => $this->symbol,
            'reason' => $this->reason,
            'suppressed' => $this->suppressed,
        ];

        // Only a built-in or section 13c row names its source; a configured row is recognised by carrying none.
        if ($this->source !== null) {
            $auditRow['source'] = $this->source;
        }

        return $auditRow;
    }

    /**
     * Renders the audit detail used after the suppression total, including the row's rule, count and reason.
     *
     * @return string - Rule, count and reason labelled by configured index, or by source and file when no configured index exists.
     */
    public function describe(): string
    {
        // Non-configured rows use a source-specific label and file because they have no configuration index.
        if ($this->source !== null) {

            $label = in_array($this->source, ['inline', 'duplicate'], true) ? $this->source : 'builtInTestPath';

            return sprintf('%s[%s] %s: %d (%s)', $label, $this->path, $this->rule, $this->suppressed, $this->reason);
        }

        return sprintf('sensitiveExclusions[%d] %s: %d (%s)', $this->index, $this->rule, $this->suppressed, $this->reason);
    }

    /**
     * Formats the suppression total and details of rows with positive counts for human-readable reports.
     * Returns null when the total is zero, leaving the report without a suppression notice.
     *
     * @param list<SensitiveExclusionSummary> $summaries - Audit rows for one run, in display order.
     *
     * @return string|null - `Suppressed findings: N via <entry>; <entry>`, or null when the total is zero.
     */
    public static function describeTotal(array $summaries): ?string
    {
        $suppressed = 0;
        $details    = [];

        // Detail only the entries that actually hid something; a configured entry matching nothing is normal
        // and its `suppressed: 0` row stays in the machine report where an auditor can read it.
        foreach ($summaries as $summary) {
            $suppressed += $summary->suppressed;
            if ($summary->suppressed > 0) {
                $details[] = $summary->describe();
            }
        }

        // With nothing suppressed there is no absence to explain, so the surface stays as it was.
        if ($suppressed === 0) {
            return null;
        }

        return sprintf('Suppressed findings: %d via %s', $suppressed, implode('; ', $details));
    }
}
