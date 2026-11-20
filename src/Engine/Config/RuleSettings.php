<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Severity;
use LogicException;

/**
 * Holds one rule's resolved enablement, thresholds, options, and scoring settings for a run.
 * Rules read these settings after configuration loading and merging.
 *
 * Threshold helpers map measured values to the configured severity, including a single-threshold override.
 * Score exclusion lets findings inform the user without lowering the composite score.
 */
final readonly class RuleSettings
{
    /**
     * Captures one rule's resolved enablement, thresholds, options, and scoring behaviour for a run.
     *
     * @param bool $enabled - Whether the rule should run for this config.
     * @param array<string, int|float> $thresholds - Named numeric thresholds available to the rule.
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options - Rule-specific option values from config.
     * @param SeverityThreshold|null $severityThreshold - A single threshold and severity; null uses warning/error thresholds.
     * @param bool $excludeFromScore - True keeps findings in reports while excluding them from composite scoring; see ADR-016.
     */
    public function __construct(
        public bool $enabled,
        public array $thresholds,
        public array $options = [],
        public ?SeverityThreshold $severityThreshold = null,
        public bool $excludeFromScore = false,
    ) {
    }

    /**
     * Reports whether this rule's findings inform the user but stay out of the composite score (ADR-016).
     *
     * @return bool - True when this rule's findings should be reported but not scored.
     */
    public function isExcludedFromScore(): bool
    {
        return $this->excludeFromScore;
    }

    /**
     * Returns the lowest limit that a "higher is worse" measurement must exceed before it reports.
     *
     * @return int|float - The single-threshold override when one is set, otherwise the warning threshold.
     */
    public function lowestHighValueThreshold(): int|float
    {
        // A single-threshold override replaces the ladder, so it is the only limit in force.
        if ($this->severityThreshold instanceof SeverityThreshold) {
            return $this->severityThreshold->threshold;
        }

        return $this->numericThreshold('warning');
    }

    /**
     * Reads a named numeric threshold the rule relies on, failing loudly if config left it missing or
     * non-numeric - a rule misconfiguration, not a user-facing input error.
     *
     * @param string $name - Threshold key to read.
     * @throws LogicException - When the configured value is missing or non-numeric.
     *
     * @return int|float - The configured threshold value.
     */
    public function numericThreshold(string $name): int|float
    {
        $thresholdValue = $this->thresholds[$name] ?? null;

        if (!is_int($thresholdValue) && !is_float($thresholdValue)) {
            throw new LogicException(sprintf('Missing numeric threshold "%s".', $name));
        }

        return $thresholdValue;
    }

    /**
     * Grades a "higher is worse" measurement (like complexity or length) against the rule's thresholds,
     * returning the severity it earns or null when the value is within budget.
     *
     * @param int|float $measuredValue - Measured rule value to compare.
     *
     * @return ThresholdMatch|null - Breached severity and threshold; null when the measurement is within budget.
     */
    public function highValueThresholdMatch(int|float $measuredValue): ?ThresholdMatch
    {
        // A single-threshold override replaces the ladder: only a value strictly past it counts as a breach.
        if ($this->severityThreshold instanceof SeverityThreshold) {
            return $measuredValue > $this->severityThreshold->threshold
                ? new ThresholdMatch($this->severityThreshold->threshold, $this->severityThreshold->severity)
                : null;
        }

        $warningThreshold = $this->numericThreshold('warning');
        // At or under the warning threshold the metric is fine, so there is nothing to report.
        if ($measuredValue <= $warningThreshold) {
            return null;
        }

        $errorThreshold = $this->numericThreshold('error');
        $severity       = $measuredValue > $errorThreshold ? Severity::Error : Severity::Warning;

        // Report at the highest band the value crossed so the finding names the breached limit.
        return new ThresholdMatch(
            $severity === Severity::Error ? $errorThreshold : $warningThreshold,
            $severity,
        );
    }

    /**
     * Grades a "lower is worse" measurement (like a coverage percentage) against the rule's thresholds,
     * returning the severity it earns or null when the value clears the floor.
     *
     * @param int|float $measuredValue - Measured rule value to compare.
     *
     * @return ThresholdMatch|null - Breached severity and threshold; null when the measurement meets the floor.
     */
    public function lowValueThresholdMatch(int|float $measuredValue): ?ThresholdMatch
    {
        // A single-threshold override replaces the ladder: only a value strictly below it counts as a breach.
        if ($this->severityThreshold instanceof SeverityThreshold) {
            return $measuredValue < $this->severityThreshold->threshold
                ? new ThresholdMatch($this->severityThreshold->threshold, $this->severityThreshold->severity)
                : null;
        }

        $warningThreshold = $this->numericThreshold('warning');
        // At or above the warning floor the metric is fine, so there is nothing to report.
        if ($measuredValue >= $warningThreshold) {
            return null;
        }

        $errorThreshold = $this->numericThreshold('error');
        $severity       = $measuredValue < $errorThreshold ? Severity::Error : Severity::Warning;

        // Report at the lowest band the value undershot so the finding names the breached floor.
        return new ThresholdMatch(
            $severity === Severity::Error ? $errorThreshold : $warningThreshold,
            $severity,
        );
    }

    /**
     * Reads a rule-specific option by name in its raw type, failing loudly when config never set it.
     *
     * @param string $name - Option key to read.
     * @throws LogicException - When the option key is missing.
     *
     * @return int|float|bool|string|array<array-key, int|float|bool|string> - The configured option value in its raw union type.
     */
    public function option(string $name): int|float|bool|string|array
    {
        if (!array_key_exists($name, $this->options)) {
            throw new LogicException(sprintf('Missing option "%s".', $name));
        }

        return $this->options[$name];
    }

    /**
     * Reads a rule option as a list of strings, treating an unset option as empty but rejecting a value
     * that is not actually a string list.
     *
     * @param string $name - Option key to read.
     * @throws LogicException - When the option value is not a list of strings.
     *
     * @return list<string> - The option's string values; empty list when the option was never set.
     */
    public function stringListOption(string $name): array
    {
        // An unset option reads as an empty list rather than an error.
        $optionValue = $this->options[$name] ?? [];

        // A non-array value here means the option was configured with the wrong shape.
        if (!is_array($optionValue)) {
            throw new LogicException(sprintf('Option "%s" must be an array of strings.', $name));
        }

        $stringOptions = [];

        foreach ($optionValue as $optionItem) {
            // A non-string element means the list was configured wrong, so fail loudly.
            if (!is_string($optionItem)) {
                throw new LogicException(sprintf('Option "%s" must contain only strings.', $name));
            }

            $stringOptions[] = $optionItem;
        }

        return $stringOptions;
    }
}
