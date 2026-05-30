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
     * @param int|null            $total          Maximum total findings allowed, or null for no total cap.
     * @param array<string, int>  $severityCounts Maximum findings allowed per severity value, keyed by severity value.
     * @throws InvalidArgumentException When any cap is negative.
     */
    public function __construct(
        public ?int $total,
        public array $severityCounts,
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
        foreach (array_keys($failureConditions) as $key) {
            if ($key !== 'total' && $key !== 'severityThresholds') {
                throw new ConfigException(sprintf('Unknown config key "failureConditions.%s".', (string) $key));
            }
        }

        $total = null;
        if (array_key_exists('total', $failureConditions)) {
            $totalValue = $failureConditions['total'];
            if (!is_int($totalValue) || $totalValue < 0) {
                throw new ConfigException('Config key "failureConditions.total" must be a non-negative integer.');
            }
            $total = $totalValue;
        }

        $severityCounts = [];
        if (array_key_exists('severityThresholds', $failureConditions)) {
            $thresholds = $failureConditions['severityThresholds'];
            if (!is_array($thresholds)) {
                throw new ConfigException('Config key "failureConditions.severityThresholds" must be an object.');
            }

            foreach ($thresholds as $severity => $cap) {
                $severityKey = (string) $severity;
                if (Severity::tryFrom($severityKey) === null) {
                    throw new ConfigException(sprintf('Unknown severity "%s" in failureConditions.severityThresholds. Use advisory, warning, or error.', $severityKey));
                }
                if (!is_int($cap) || $cap < 0) {
                    throw new ConfigException(sprintf('Config key "failureConditions.severityThresholds.%s" must be a non-negative integer.', $severityKey));
                }
                $severityCounts[$severityKey] = $cap;
            }
        }

        return new self($total, $severityCounts);
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
                return new ThresholdTrip($severity, $counts[$severity], $cap);
            }
        }

        $totalCount = count($findings);
        if ($this->total !== null && $totalCount > $this->total) {
            return new ThresholdTrip(ThresholdTrip::KIND_TOTAL, $totalCount, $this->total);
        }

        return null;
    }
}
