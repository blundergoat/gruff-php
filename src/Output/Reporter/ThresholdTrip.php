<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

/**
 * Records a breached finding-count cap for terminal, Markdown, and JSON reports.
 * Retains the observed count, configured cap, and whether the gate considered its full finding set or only new findings.
 *
 * `FailThresholds` creates the trip after a count exceeds its cap; reporters use its message and fields.
 */
final readonly class ThresholdTrip
{
    /**
     * `thresholdKind` value meaning the aggregate finding count overflowed, rather than any single severity.
     */
    public const KIND_TOTAL = 'total';

    /**
     * Default scope for the full finding set supplied to the gate.
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
     * @param string $thresholdKind - Aggregate "total" cap or the severity whose cap was exceeded.
     * @param int $count - Observed count for this threshold; values at or below its cap do not breach it.
     * @param int $cap - Configured maximum allowed count.
     * @param string $scope - Finding set counted: "total" or "new"; defaults to "total".
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
     * new-findings sub-gate catches a breach and needs the trip marked "new", so the report can
     * point the user at the findings their change introduced rather than the whole existing backlog.
     *
     * @param string $scope - Finding set to record: "total" for the whole run or "new" for newly introduced findings only.
     *
     * @return self - A fresh trip with the same kind, count, and cap but the given scope; the original trip is left untouched.
     */
    public function withScope(string $scope): self
    {
        return new self($this->thresholdKind, $this->count, $this->cap, $scope);
    }

    /**
     * Renders the trip as the one sentence the user reads when a gate fails, for example
     * "3 error finding(s) exceed the cap of 0". Called for the terminal failure line, the Markdown
     * report's "Failed:" line, and when assembling the JSON report payload.
     *
     * @return string - Gate-failure sentence naming the count, cap, kind, and new scope when applicable.
     */
    public function message(): string
    {

        $scopeWord = $this->scope === self::SCOPE_NEW ? 'new ' : '';


        return $this->thresholdKind === self::KIND_TOTAL
            ? sprintf('%d %sfindings exceed the total cap of %d', $this->count, $scopeWord, $this->cap)
            : sprintf('%d %s%s finding(s) exceed the cap of %d', $this->count, $scopeWord, $this->thresholdKind, $this->cap);
    }

    /**
     * Flattens the trip into the JSON shape the report writes under "failureReason", so machine
     * readers such as CI get the same verdict the terminal shows. Called when a failed
     * run is serialised with "--format json".
     *
     * @return array{thresholdKind: string, count: int, cap: int, scope: string, message: string} - Gate fields and the failure message.
     */
    public function toArray(): array
    {

        return [
            'thresholdKind' => $this->thresholdKind,
            'count'         => $this->count,
            'cap'           => $this->cap,
            'scope'         => $this->scope,
            'message'       => $this->message(),
        ];
    }
}
