<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Severity;
use InvalidArgumentException;

/**
 * Evaluates finding-count caps for analyse failure reporting.
 * Supports an overall cap, per-severity caps, and a separate gate for findings new since a baseline.
 * fromFailOn() and fromConfig() build gates; tripsOnScope() checks new-findings caps before ordinary caps.
 */
final readonly class FailThresholds
{
    /**
     * The order severities are checked in, worst first, so the failure a user sees names their most
     * serious breach rather than a lesser one that also tripped.
     *
     * @var list<value-of<Severity>> - Severity values in failure-reporting order.
     */
    private const SEVERITY_ORDER = ['error', 'warning', 'advisory'];

    /**
     * Assembles a gate from its caps, rejecting any negative cap up front so a malformed config
     * fails loudly at construction instead of silently failing every run (a negative cap can never be met).
     *
     * @param int|null $total - Maximum total findings allowed; null leaves the total uncapped.
     * @param array<string, int> $severityCounts - Maximum count per severity value; an empty map imposes no severity caps.
     * @param FailThresholds|null $newFindingsGate - Gate for new-since-baseline findings; null disables the separate gate.
     * @throws InvalidArgumentException - If the total cap or any supplied severity cap is negative.
     */
    public function __construct(
        public ?int $total,
        public array $severityCounts,
        public ?FailThresholds $newFindingsGate = null,
    ) {
        // A negative total cap could never be met, so reject it now rather than let it fail every run silently.
        if ($total !== null && $total < 0) {
            throw new InvalidArgumentException('Total finding cap must be a non-negative integer.');
        }


        foreach ($severityCounts as $severity => $cap) {

            if ($cap < 0) {
                throw new InvalidArgumentException(sprintf('Severity cap for "%s" must be a non-negative integer.', $severity));
            }
        }
    }

    /**
     * Translates the --fail-on severity flag into an equivalent gate, so the simple
     * flag and the richer config block both funnel through the same evaluation path.
     *
     * @param FailThreshold $threshold - Severity selected with --fail-on, for example error.
     *
     * @return self - Gate with zero caps for rejected severities; none leaves all counts uncapped.
     */
    public static function fromFailOn(FailThreshold $threshold): self
    {
        $severityCounts = match ($threshold) {
            FailThreshold::None => [],
            FailThreshold::Error => [Severity::Error->value => 0],
            FailThreshold::Warning => [Severity::Error->value => 0, Severity::Warning->value => 0],
            FailThreshold::Advisory => [
                Severity::Error->value => 0,
                Severity::Warning->value => 0,
                Severity::Advisory->value => 0,
            ],
        };

        // The flag never caps the total; the zero per-severity cap is what makes it "fail on any finding at or above X".
        return new self(null, $severityCounts);
    }

    /**
     * Turns the failureConditions block from the user's .gruff-php.yaml into a gate, the entry
     * point used whenever a project spells out its own pass/fail policy instead of a bare flag.
     *
     * @param array<array-key, mixed> $failureConditions - Decoded failureConditions block read from the user's config.
     * @throws ConfigException - When a key, severity name, or value in the block is invalid.
     *
     * @return self - A gate matching the caps the user wrote in their config.
     */
    public static function fromConfig(array $failureConditions): self
    {
        // Only the outermost block may contain newFindings; passing true permits it here, and deeper calls do not inherit that permission.
        return self::parseConditions($failureConditions, 'failureConditions', true);
    }

    /**
     * The shared parser behind both the top-level block and any nested sub-gate: validate the keys,
     * then read the total, per-severity, and new-findings caps into one gate.
     *
     * @param array<array-key, mixed> $conditions - Top-level or newFindings block to validate and parse.
     * @param string $keyPath - Dotted config path included in validation errors.
     * @param bool $allowsNewFindings - Whether this level may contain a newFindings block.
     * @throws ConfigException - When a key, severity name, or value in the block is invalid.
     *
     * @return self - The gate described by this block.
     */
    private static function parseConditions(array $conditions, string $keyPath, bool $allowsNewFindings): self
    {
        self::assertKnownKeys($conditions, $keyPath, $allowsNewFindings);

        return new self(
            self::parseTotal($conditions, $keyPath),
            self::parseSeverityThresholds($conditions, $keyPath),
            self::parseNewFindingsGate($conditions, $keyPath, $allowsNewFindings),
        );
    }

    /**
     * Guards against typos and unsupported options in a user's config block, failing on the first
     * unrecognised key so a misspelled option is rejected rather than silently ignored.
     *
     * @param array<array-key, mixed> $conditions - Conditions block whose keys are validated.
     * @param string $keyPath - Dotted config path included in the error.
     * @param bool $allowsNewFindings - Whether newFindings belongs to the permitted key set.
     * @throws ConfigException - When an unsupported key is present.
     *
     * @return void - Validates the keys without modifying the block.
     */
    private static function assertKnownKeys(array $conditions, string $keyPath, bool $allowsNewFindings): void
    {
        $allowedKeys = $allowsNewFindings ? ['total', 'severityThresholds', 'newFindings'] : ['total', 'severityThresholds'];

        foreach (array_keys($conditions) as $key) {

            if (!in_array($key, $allowedKeys, true)) {
                throw new ConfigException(sprintf('Unknown config key "%s.%s".', $keyPath, (string) $key));
            }
        }
    }

    /**
     * Reads the optional total cap from the block, the ceiling on how many findings of any
     * severity a user will tolerate before the run is failed.
     *
     * @param array<array-key, mixed> $conditions - Decoded conditions block that may or may not contain a total key.
     * @param string                  $keyPath - Dotted config path quoted back in the error the user sees.
     * @throws ConfigException - When the total value is not a non-negative integer.
     *
     * @return int|null - Total cap set by the user; null when total is omitted, leaving the total uncapped.
     */
    private static function parseTotal(array $conditions, string $keyPath): ?int
    {

        if (!array_key_exists('total', $conditions)) {
            return null;
        }

        $totalValue = $conditions['total'];

        if (!is_int($totalValue) || $totalValue < 0) {
            throw new ConfigException(sprintf('Config key "%s.total" must be a non-negative integer.', $keyPath));
        }

        return $totalValue;
    }

    /**
     * Reads the optional severityThresholds map, the per-severity ceilings that let a user, say,
     * tolerate a handful of advisories but zero errors.
     *
     * @param array<array-key, mixed> $conditions - Decoded conditions block that may or may not contain a severityThresholds key.
     * @param string                  $keyPath - Dotted config path quoted back in the error the user sees.
     * @throws ConfigException - When the block is not an array, a severity is unknown, or a cap is not a non-negative integer.
     *
     * @return array<string, int> - Caps keyed by severity value; an empty map when the user set no per-severity limits.
     */
    private static function parseSeverityThresholds(array $conditions, string $keyPath): array
    {

        if (!array_key_exists('severityThresholds', $conditions)) {
            return [];
        }

        $thresholds = $conditions['severityThresholds'];

        if (!is_array($thresholds)) {
            throw new ConfigException(sprintf('Config key "%s.severityThresholds" must be an object.', $keyPath));
        }

        $severityCounts = [];

        foreach ($thresholds as $severity => $cap) {
            $severityKey = (string) $severity;

            if (Severity::tryFrom($severityKey) === null) {
                throw new ConfigException(sprintf('Unknown severity "%s" in %s.severityThresholds. Use advisory, warning, or error.', $severityKey, $keyPath));
            }

            if (!is_int($cap) || $cap < 0) {
                throw new ConfigException(sprintf('Config key "%s.severityThresholds.%s" must be a non-negative integer.', $keyPath, $severityKey));
            }
            $severityCounts[$severityKey] = $cap;
        }

        return $severityCounts;
    }

    /**
     * Parses the optional newFindings block into a sub-gate with further nesting disabled.
     *
     * @param array<array-key, mixed> $conditions - Conditions block that may contain newFindings.
     * @param string $keyPath - Dotted config path included in validation errors.
     * @param bool $allowsNewFindings - Whether this level may contain a newFindings block.
     * @throws ConfigException - If the block is not an array or contains invalid or nested conditions.
     *
     * @return self|null - Parsed sub-gate; null when the block is absent or nesting is disallowed.
     */
    private static function parseNewFindingsGate(array $conditions, string $keyPath, bool $allowsNewFindings): ?self
    {

        if (!$allowsNewFindings || !array_key_exists('newFindings', $conditions)) {
            return null;
        }

        $newFindings = $conditions['newFindings'];

        if (!is_array($newFindings)) {
            throw new ConfigException(sprintf('Config key "%s.newFindings" must be an object.', $keyPath));
        }

        // Parse the nested block as its own gate; passing false causes a nested newFindings block to be rejected.
        return self::parseConditions($newFindings, $keyPath . '.newFindings', false);
    }

    /**
     * Checks severity caps from error to advisory, then the total cap.
     * Only this gate's own caps are evaluated; tripsOnScope() handles the new-findings sub-gate.
     *
     * @param list<Finding> $findings - Findings whose counts are compared with this gate's severity and total caps.
     *
     * @return ThresholdTrip|null - First exceeded severity or total cap; null when this gate's own caps are satisfied.
     */
    public function tripsOn(array $findings): ?ThresholdTrip
    {
        $counts = [
            Severity::Error->value => 0,
            Severity::Warning->value => 0,
            Severity::Advisory->value => 0,
        ];


        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }


        foreach (self::SEVERITY_ORDER as $severity) {
            $cap = $this->severityCounts[$severity] ?? null;

            if ($cap !== null && $counts[$severity] > $cap) {
                return new ThresholdTrip($severity, $counts[$severity], $cap);
            }
        }

        $totalCount = count($findings);

        if ($this->total !== null && $totalCount > $this->total) {
            return new ThresholdTrip(ThresholdTrip::KIND_TOTAL, $totalCount, $this->total);
        }

        return null;
    }

    /**
     * Returns a copy of this gate with a different new-findings sub-gate swapped in, used when the
     * baseline workflow attaches (or clears) the separate check on newly introduced findings.
     *
     * @param FailThresholds|null $newFindingsGate - The sub-gate to attach; null clears any existing new-findings gate.
     *
     * @return self - A new gate identical to this one but carrying the given new-findings sub-gate.
     */
    public function withNewFindingsGate(?FailThresholds $newFindingsGate): self
    {
        return new self($this->total, $this->severityCounts, $newFindingsGate);
    }

    /**
     * Checks the new-findings sub-gate first, returning its breach before evaluating the ordinary caps.
     *
     * @param list<Finding> $allFindings - Every post-baseline finding, weighed against this gate's own caps.
     * @param list<Finding> $newFindings - The subset new since the baseline, weighed against the new-findings sub-gate.
     *
     * @return ThresholdTrip|null - Sub-gate breach tagged new, otherwise the ordinary breach; null if neither gate trips.
     */
    public function tripsOnScope(array $allFindings, array $newFindings): ?ThresholdTrip
    {

        if ($this->newFindingsGate instanceof FailThresholds) {
            $newTrip = $this->newFindingsGate->tripsOn($newFindings);

            if ($newTrip instanceof ThresholdTrip) {
                return $newTrip->withScope(ThresholdTrip::SCOPE_NEW);
            }
        }


        return $this->tripsOn($allFindings);
    }
}
