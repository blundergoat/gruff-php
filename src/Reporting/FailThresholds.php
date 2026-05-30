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
        return self::parseConditions($failureConditions, 'failureConditions', true);
    }

    /**
     * Recursively parse a failureConditions block, optionally allowing a newFindings sub-gate.
     *
     * @param array<array-key, mixed> $conditions       Decoded conditions block.
     * @param string                  $keyPath          Config key path used for error messages.
     * @param bool                    $allowNewFindings Whether a nested newFindings sub-gate is permitted at this level.
     * @throws ConfigException When keys, severities, or values are invalid.
     * @return self Thresholds described by the block.
     */
    private static function parseConditions(array $conditions, string $keyPath, bool $allowNewFindings): self
    {
        $allowedKeys = $allowNewFindings ? ['total', 'severityThresholds', 'newFindings'] : ['total', 'severityThresholds'];
        foreach (array_keys($conditions) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new ConfigException(sprintf('Unknown config key "%s.%s".', $keyPath, (string) $key));
            }
        }

        $total = null;
        if (array_key_exists('total', $conditions)) {
            $totalValue = $conditions['total'];
            if (!is_int($totalValue) || $totalValue < 0) {
                throw new ConfigException(sprintf('Config key "%s.total" must be a non-negative integer.', $keyPath));
            }
            $total = $totalValue;
        }

        $severityCounts = [];
        if (array_key_exists('severityThresholds', $conditions)) {
            $thresholds = $conditions['severityThresholds'];
            if (!is_array($thresholds)) {
                throw new ConfigException(sprintf('Config key "%s.severityThresholds" must be an object.', $keyPath));
            }

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
        }

        $newFindingsGate = null;
        if ($allowNewFindings && array_key_exists('newFindings', $conditions)) {
            $newFindings = $conditions['newFindings'];
            if (!is_array($newFindings)) {
                throw new ConfigException(sprintf('Config key "%s.newFindings" must be an object.', $keyPath));
            }
            $newFindingsGate = self::parseConditions($newFindings, $keyPath . '.newFindings', false);
        }

        return new self($total, $severityCounts, $newFindingsGate);
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

    /**
     * Return a copy of this gate with its new-findings sub-gate replaced.
     *
     * @param FailThresholds|null $newFindingsGate New-findings sub-gate, or null to clear it.
     * @return self Gate carrying the updated new-findings sub-gate.
     */
    public function withNewFindingsGate(?FailThresholds $newFindingsGate): self
    {
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
                return $newTrip->withScope(ThresholdTrip::SCOPE_NEW);
            }
        }

        return $this->tripsOn($allFindings);
    }
}
