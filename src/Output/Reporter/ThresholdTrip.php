<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

/**
 * Captures why a scan failed its finding-count gate: which cap was breached, by how much, and
 * over which set of findings. When the user runs `gruff-php analyse --fail-on error` (or sets a
 * `total` / `severityThresholds` cap in `.gruff-php.yaml`) and the scan turns up more findings
 * than the gate allows, the fail-gate mints one of these to record the reason. It then feeds the
 * surfaces the user sees: the one-line failure message on the terminal, the matching `**Failed:**`
 * line in the Markdown report, and the `failureReason` block in the JSON report. Its `scope` tells
 * them whether the whole codebase overflowed the cap or only the findings their latest change introduced.
 */
final readonly class ThresholdTrip
{
    /**
     * `thresholdKind` value meaning the aggregate finding count overflowed, rather than any single severity.
     */
    public const KIND_TOTAL = 'total';

    /**
     * Default `scope`: the cap was weighed against every finding the run produced.
     */
    public const SCOPE_TOTAL = 'total';

    /**
     * `scope` used when the cap was weighed only against findings the user's change newly introduced.
     */
    public const SCOPE_NEW = 'new';

    /**
     * Records one breached gate threshold. Built by the fail-gate the instant a run's findings go
     * over a cap, so the overflow can be explained to the user and written into the report.
     *
     * @param string $thresholdKind - What overflowed: `"total"` for the aggregate cap, or a severity value (`advisory`, `warning`, `error`) when a single severity's cap was breached.
     * @param int    $count - How many findings were actually counted for that threshold; the number the user has to get back under the cap.
     * @param int    $cap - The limit the user configured (through `--fail-on` or `.gruff-php.yaml`) that the count went over.
     * @param string $scope - Which findings were weighed: `"total"` for the whole run or `"new"` for only newly introduced findings; defaults to `"total"`.
     */
    public function __construct(
        public string $thresholdKind,
        public int    $count,
        public int    $cap,
        public string $scope = self::SCOPE_TOTAL,
    ) {
    }

    /**
     * Re-labels this trip for a different finding set without recomputing it. Used when the
     * new-findings sub-gate catches a breach and needs the trip marked `"new"`, so the report can
     * point the user at the findings their change introduced rather than the whole existing backlog.
     *
     * @param string $scope - Finding set to record: `"total"` for the whole run or `"new"` for newly introduced findings only.
     *
     * @return self - A fresh trip with the same kind, count, and cap but the given scope; the original trip is left untouched.
     */
    public function withScope(string $scope): self
    {
        // Readonly value object, so re-scoping yields a fresh copy rather than mutating the original trip.
        return new self($this->thresholdKind, $this->count, $this->cap, $scope);
    }

    /**
     * Renders the trip as the one sentence the user reads when a gate fails, for example
     * `3 error finding(s) exceed the cap of 0`. Called for the terminal failure line, the Markdown
     * report's `**Failed:**` line, and when assembling the JSON report payload.
     *
     * @return string - The complete gate-failure sentence, always populated; it names the overflowing severity (or the total), the observed count, and the cap that was breached.
     */
    public function message(): string
    {
        // Slip the word `new` into the sentence only when the cap was measured against newly introduced findings.
        $scopeWord = $this->scope === self::SCOPE_NEW ? 'new ' : '';

        // The total cap reads as a bare count; a per-severity cap names which severity overflowed.
        return $this->thresholdKind === self::KIND_TOTAL
            ? sprintf('%d %sfindings exceed the total cap of %d', $this->count, $scopeWord, $this->cap)
            : sprintf('%d %s%s finding(s) exceed the cap of %d', $this->count, $scopeWord, $this->thresholdKind, $this->cap);
    }

    /**
     * Flattens the trip into the JSON shape the report writes under `failureReason`, so machine
     * readers such as CI get the same verdict the terminal shows. Called when a failed
     * run is serialised with `--format json`.
     *
     * @return array{thresholdKind: string, count: int, cap: int, scope: string, message: string} - fully populated report row carrying the raw
     *                              fields plus the pre-rendered `message`, so consumers never rebuild the wording.
     */
    public function toArray(): array
    {
        // Emit the raw fields (so a consumer can regroup or translate) alongside the ready-made `message`.
        return [
            'thresholdKind' => $this->thresholdKind,
            'count'         => $this->count,
            'cap'           => $this->cap,
            'scope'         => $this->scope,
            'message'       => $this->message(),
        ];
    }
}
