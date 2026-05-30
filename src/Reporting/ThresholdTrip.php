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
     * @param string $thresholdKind Threshold that tripped: "total" or a severity value (advisory, warning, error).
     * @param int    $count         Actual finding count observed for the threshold.
     * @param int    $cap           Configured maximum that was exceeded.
     */
    public function __construct(
        public string $thresholdKind,
        public int $count,
        public int $cap,
    ) {
    }

    /**
     * Build a one-line human-readable description of the trip.
     *
     * @return string Failure explanation suitable for CI logs.
     */
    public function message(): string
    {
        return $this->thresholdKind === self::KIND_TOTAL
            ? sprintf('%d findings exceed the total cap of %d', $this->count, $this->cap)
            : sprintf('%d %s finding(s) exceed the cap of %d', $this->count, $this->thresholdKind, $this->cap);
    }

    /**
     * Serialize this value object into the array shape used by reports.
     *
     * @return array{thresholdKind: string, count: int, cap: int, message: string}
     */
    public function toArray(): array
    {
        return [
            'thresholdKind' => $this->thresholdKind,
            'count' => $this->count,
            'cap' => $this->cap,
            'message' => $this->message(),
        ];
    }
}
