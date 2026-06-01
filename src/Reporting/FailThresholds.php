<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Config\ConfigException;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Severity;
use InvalidArgumentException;

/**
 * Severity-bucketed and total finding-count thresholds that decide whether a run fails.
 *
 * This is the single source of truth for gate evaluation: the legacy
 * `--fail-on <severity>` flag desugars to an equivalent instance via fromFailOn(),
 * and the richer `failureConditions:` config block is parsed by fromConfig().
 */
final readonly class FailThresholds
{
    /**
     * Severities checked most-severe first so the reported trip favours the worst breach.
     *
     * @var list<value-of<Severity>>
     */
    private const SEVERITY_ORDER = ['error', 'warning', 'advisory'];

    /**
     * @param int|null             $total            Maximum total findings allowed, or null for no total cap.
     * @param array<string, int>   $severityCounts   Maximum findings allowed per severity value, keyed by severity value.
     * @param FailThresholds|null  $newFindingsGate  Optional sub-gate applied to the new-findings set only.
     * @throws InvalidArgumentException When any cap is negative.
     */
    public function __construct(
        public ?int $total,
        public array $severityCounts,
        public ?FailThresholds $newFindingsGate = null,
    ) {
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
     * Build thresholds equivalent to the legacy --fail-on severity gate.
     *
     * @param FailThreshold $threshold Legacy severity threshold to desugar.
     * @return self Thresholds reproducing the binary gate exactly.
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

        // The legacy flag has no total cap; a zero per-severity cap reproduces "fail on any finding at or above X".
        return new self(null, $severityCounts);
    }

    /**
     * Build thresholds from a parsed failureConditions config block.
     *
     * @param array<array-key, mixed> $failureConditions Decoded failureConditions block.
     * @throws ConfigException When keys, severities, or values are invalid.
     * @return self Thresholds described by the config block.
     */
    public static function fromConfig(array $failureConditions): self
    {
        // Top-level entry point: only here is a nested newFindings sub-gate allowed (true), never deeper.
        return self::parseConditions($failureConditions, 'failureConditions', true);
    }

    /**
     * Recursively parse a failureConditions block, optionally allowing a newFindings sub-gate.
     *
     * @param array<array-key, mixed> $conditions        Decoded conditions block.
     * @param string                  $keyPath           Config key path used for error messages.
     * @param bool                    $allowsNewFindings Whether a nested newFindings sub-gate is permitted at this level.
     * @throws ConfigException When keys, severities, or values are invalid.
     * @return self Thresholds described by the block.
     */
    private static function parseConditions(array $conditions, string $keyPath, bool $allowsNewFindings): self
    {
        self::assertKnownKeys($conditions, $keyPath, $allowsNewFindings);

        // Assemble the gate from its three independently-parsed parts after the keys are validated.
        return new self(
            self::parseTotal($conditions, $keyPath),
            self::parseSeverityThresholds($conditions, $keyPath),
            self::parseNewFindingsGate($conditions, $keyPath, $allowsNewFindings),
        );
    }

    /**
     * Reject any key the conditions block does not support at this nesting level.
     *
     * @param array<array-key, mixed> $conditions        Decoded conditions block.
     * @param string                  $keyPath           Config key path used for error messages.
     * @param bool                    $allowsNewFindings Whether the newFindings key is permitted at this level.
     * @throws ConfigException When an unsupported key is present.
     * @return void
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
     * Parse the optional total-finding cap.
     *
     * @param array<array-key, mixed> $conditions Decoded conditions block.
     * @param string                  $keyPath    Config key path used for error messages.
     * @throws ConfigException When the total value is not a non-negative integer.
     * @return int|null Total cap, or null when the block omits it.
     */
    private static function parseTotal(array $conditions, string $keyPath): ?int
    {
        if (!array_key_exists('total', $conditions)) {
            // Omitting total means "no overall cap"; null is the explicit no-limit value the constructor expects.
            return null;
        }

        $totalValue = $conditions['total'];
        if (!is_int($totalValue) || $totalValue < 0) {
            throw new ConfigException(sprintf('Config key "%s.total" must be a non-negative integer.', $keyPath));
        }

        // Validated non-negative cap on the total finding count.
        return $totalValue;
    }

    /**
     * Parse the optional per-severity caps keyed by severity value.
     *
     * @param array<array-key, mixed> $conditions Decoded conditions block.
     * @param string                  $keyPath    Config key path used for error messages.
     * @throws ConfigException When a severity name or its cap value is invalid.
     * @return array<string, int> Caps keyed by severity value.
     */
    private static function parseSeverityThresholds(array $conditions, string $keyPath): array
    {
        if (!array_key_exists('severityThresholds', $conditions)) {
            // No per-severity block; an empty map means no severity is individually capped.
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

        // Validated caps keyed by canonical severity value, ready for the constructor's non-negativity recheck.
        return $severityCounts;
    }

    /**
     * Parse the optional nested newFindings sub-gate.
     *
     * @param array<array-key, mixed> $conditions        Decoded conditions block.
     * @param string                  $keyPath           Config key path used for error messages.
     * @param bool                    $allowsNewFindings Whether a nested newFindings sub-gate is permitted at this level.
     * @throws ConfigException When the newFindings block is present but not an object.
     * @return self|null Sub-gate, or null when no nested newFindings block applies.
     */
    private static function parseNewFindingsGate(array $conditions, string $keyPath, bool $allowsNewFindings): ?self
    {
        if (!$allowsNewFindings || !array_key_exists('newFindings', $conditions)) {
            // No sub-gate when nesting is disallowed at this level or the block simply omits newFindings.
            return null;
        }

        $newFindings = $conditions['newFindings'];
        if (!is_array($newFindings)) {
            throw new ConfigException(sprintf('Config key "%s.newFindings" must be an object.', $keyPath));
        }

        // Recurse with allowsNewFindings=false so a newFindings block cannot itself nest another one.
        return self::parseConditions($newFindings, $keyPath . '.newFindings', false);
    }

    /**
     * Return the first threshold the findings exceed, or null when the run passes.
     *
     * Severity caps are checked most-severe first, then the total cap; any breach
     * fails the run (OR semantics).
     *
     * @param list<Finding> $findings Post-baseline findings to evaluate against the gate.
     * @return ThresholdTrip|null The breached threshold, or null when no threshold trips.
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
                // Most-severe-first iteration means the first breach reported is the worst one.
                return new ThresholdTrip($severity, $counts[$severity], $cap);
            }
        }

        $totalCount = count($findings);
        if ($this->total !== null && $totalCount > $this->total) {
            // No single severity breached, but the aggregate count did; report the total trip.
            return new ThresholdTrip(ThresholdTrip::KIND_TOTAL, $totalCount, $this->total);
        }

        // No cap exceeded: the run passes the gate.
        return null;
    }

    /**
     * Return a copy of this gate with its new-findings sub-gate replaced.
     *
     * @param FailThresholds|null $newFindingsGate New-findings sub-gate, or null to clear it.
     * @return self Gate carrying the updated new-findings sub-gate.
     */
    public function withNewFindingsGate(?FailThresholds $newFindingsGate): self
    {
        // Readonly value object, so swapping the sub-gate means returning a fresh copy of the caps.
        return new self($this->total, $this->severityCounts, $newFindingsGate);
    }

    /**
     * Evaluate the new-findings sub-gate against the new set first, then the total gate against all findings.
     *
     * The new-findings trip is preferred when both fire because it is the more
     * actionable signal for a developer.
     *
     * @param list<Finding> $allFindings Post-baseline findings the total gate evaluates.
     * @param list<Finding> $newFindings New-findings set the sub-gate evaluates.
     * @return ThresholdTrip|null The breached threshold, or null when no threshold trips.
     */
    public function tripsOnScope(array $allFindings, array $newFindings): ?ThresholdTrip
    {
        if ($this->newFindingsGate instanceof FailThresholds) {
            $newTrip = $this->newFindingsGate->tripsOn($newFindings);
            if ($newTrip instanceof ThresholdTrip) {
                // A new-findings breach wins: tag it NEW so the report points at the freshly introduced findings.
                return $newTrip->withScope(ThresholdTrip::SCOPE_NEW);
            }
        }

        // No new-findings sub-gate or it passed; fall through to the overall gate across every finding.
        return $this->tripsOn($allFindings);
    }
}
