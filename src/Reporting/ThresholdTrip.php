<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

/**
 * Describes the single gate threshold a run exceeded, for failure reporting.
 */
final readonly class ThresholdTrip
{
    /**
     * Total-finding cap thresholdKind.
     */
    public const KIND_TOTAL = 'total';

    /**
     * Trip scope: the threshold applied to the full finding set.
     */
    public const SCOPE_TOTAL = 'total';

    /**
     * Trip scope: the threshold applied to the new-findings set only.
     */
    public const SCOPE_NEW = 'new';

    /**
     * @param string $thresholdKind Threshold that tripped: "total" or a severity value (advisory, warning, error).
     * @param int    $count         Actual finding count observed for the threshold.
     * @param int    $cap           Configured maximum that was exceeded.
     * @param string $scope         Finding set the threshold applied to: "total" or "new".
     */
    public function __construct(
        public string $thresholdKind,
        public int $count,
        public int $cap,
        public string $scope = self::SCOPE_TOTAL,
    ) {
    }

    /**
     * Return a copy of this trip re-scoped to the given finding set.
     *
     * @param string $scope Scope to apply: "total" or "new".
     * @return self Trip carrying the requested scope.
     */
    public function withScope(string $scope): self
    {
        // Readonly value object, so re-scoping yields a fresh copy rather than mutating the original trip.
        return new self($this->thresholdKind, $this->count, $this->cap, $scope);
    }

    /**
     * Build a one-line human-readable description of the trip.
     *
     * @return string Failure explanation suitable for CI logs.
     */
    public function message(): string
    {
        $scopeWord = $this->scope === self::SCOPE_NEW ? 'new ' : '';

        // The total cap reads as a bare count; a per-severity cap names which severity overflowed.
        return $this->thresholdKind === self::KIND_TOTAL
            ? sprintf('%d %sfindings exceed the total cap of %d', $this->count, $scopeWord, $this->cap)
            : sprintf('%d %s%s finding(s) exceed the cap of %d', $this->count, $scopeWord, $this->thresholdKind, $this->cap);
    }

    /**
     * Serialize this value object into the array shape used by reports.
     *
     * @return array{thresholdKind: string, count: int, cap: int, scope: string, message: string}
     */
    public function toArray(): array
    {
        // Pre-render the message into the payload so report consumers never rebuild the wording themselves.
        return [
            'thresholdKind' => $this->thresholdKind,
            'count' => $this->count,
            'cap' => $this->cap,
            'scope' => $this->scope,
            'message' => $this->message(),
        ];
    }
}
