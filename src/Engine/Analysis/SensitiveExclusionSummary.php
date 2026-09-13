<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

/**
 * One audit row describing what a configured `sensitiveExclusions` entry actually hid.
 *
 * Every configured entry publishes exactly one of these on every run, whether or not it matched
 * anything, so a suppression is never silently invisible: a reviewer reading the report sees the
 * scope, the rationale its author wrote, and the number of findings it removed. An entry that
 * matched nothing reports `suppressed: 0` rather than failing, so fixing the underlying problem
 * never breaks a build. Nothing here is derived from a matched value - the rule id, path, symbol,
 * and reason all come from configuration.
 *
 * @phpstan-type SensitiveExclusionSummaryArray array{index: int, rule: string, paths: list<string>, symbol: string|null, reason: string,
 *               suppressed: int}
 */
final readonly class SensitiveExclusionSummary
{
    /**
     * Holds one entry's declared scope alongside the count it suppressed on this run.
     *
     * @param int         $index - Entry position in the configured list, matching the `sensitiveExclusions[N]` used by config diagnostics.
     * @param string      $rule - Exact rule id the entry suppresses.
     * @param string      $path - Project-relative path the entry is scoped to.
     * @param string|null $symbol - Symbol narrowing the scope; null when the entry covers the whole file.
     * @param string      $reason - Rationale supplied by whoever accepted the finding.
     * @param int         $suppressed - Findings this entry removed from the run; zero is a valid, non-failing result.
     */
    public function __construct(
        public int     $index,
        public string  $rule,
        public string  $path,
        public ?string $symbol,
        public string  $reason,
        public int     $suppressed,
    ) {
    }

    /**
     * Flattens the row into the family audit shape carried by the report's `suppressions` array.
     * `paths` is a list because the family shape is shared with ports whose ordinary exclusions
     * accept several paths; a sensitive exclusion always names exactly one.
     *
     * @return SensitiveExclusionSummaryArray - the audit row: entry index, rule, single-element path list, symbol, reason, and suppressed count.
     */
    public function toArray(): array
    {
        return [
            'index'      => $this->index,
            'rule'       => $this->rule,
            'paths'      => [$this->path],
            'symbol'     => $this->symbol,
            'reason'     => $this->reason,
            'suppressed' => $this->suppressed,
        ];
    }

    /**
     * Renders the one-line detail the text report lists after its suppression total, so a terminal
     * reader sees which entry hid how much and why without opening the JSON report.
     *
     * @return string - `sensitiveExclusions[N] <rule>: <count> (<reason>)`, carrying only configured material.
     */
    public function describe(): string
    {
        return sprintf('sensitiveExclusions[%d] %s: %d (%s)', $this->index, $this->rule, $this->suppressed, $this->reason);
    }

    /**
     * Renders the one line every human-readable surface prints when a run suppressed something, so
     * `analyse` and `summary` state the same total in the same words rather than each wording its
     * own. A surface that applies these exclusions calls this and prints what it returns; that is
     * how filtering stays accountable instead of silently shrinking a count.
     *
     * @param list<SensitiveExclusionSummary> $summaries - Audit rows for one run, in configuration order.
     *
     * @return string|null - `Suppressed findings: N via <entry>; <entry>`, or null when nothing was suppressed and there is no absence to explain.
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
