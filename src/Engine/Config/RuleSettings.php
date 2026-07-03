<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Severity;
use LogicException;

/**
 * Holds per-rule enablement, thresholds, and option values.
 */
final readonly class RuleSettings
{
    /**
      * User flow: Turns project settings into the analysis run the user requested.
      *
     * @param bool                                                                         $enabled - Whether the rule should run for this config.
     * @param array<string, int|float>                                                     $thresholds - Named numeric thresholds available to the rule.
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options - Rule-specific option values from config.
     * @param SeverityThreshold|null                                                       $severityThreshold - Optional single threshold/severity override.
     * @param bool                                                                         $excludeFromScore - When true, the rule still runs and surfaces findings in reports but its findings do not penalise the composite score. See ADR-016.
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
      * User flow: Turns project settings into the analysis run the user requested.
      *
     * @return bool - True when this rule's findings should be reported but not scored.
     */
    public function isExcludedFromScore(): bool
    {
        return $this->excludeFromScore;
    }

    /**
     * Return a configured numeric threshold by name.
     *
      * User flow: Turns project settings into the analysis run the user requested.
      *
     * @param string $name - Threshold key to read.
     * @throws LogicException When the configured value is missing or non-numeric.
     *
     * @return int|float - Threshold value.
     */
    public function numericThreshold(string $name): int|float
    {
        // User view: missing data becomes a safe configured analysis run default.
        $thresholdValue = $this->thresholds[$name] ?? null;

        // User view: choose the configured analysis run branch for this case.
        if (!is_int($thresholdValue) && !is_float($thresholdValue)) {
            throw new LogicException(sprintf('Missing numeric threshold "%s".', $name));
        }

        return $thresholdValue;
    }

    /**
     * Match a value where higher numbers are worse against configured thresholds.
     *
      * User flow: Turns project settings into the analysis run the user requested.
      *
     * @param int|float $measuredValue - Measured rule value to compare.
     *
     * @return ThresholdMatch|null - Matching severity threshold, or null when the value is allowed.
     */
    public function highValueThresholdMatch(int|float $measuredValue): ?ThresholdMatch
    {
        // User view: choose the configured analysis run branch for this case.
        if ($this->severityThreshold instanceof SeverityThreshold) {
            // Single-threshold override: breach only once the value strictly exceeds it.
            return $measuredValue > $this->severityThreshold->threshold
                ? new ThresholdMatch($this->severityThreshold->threshold, $this->severityThreshold->severity)
                : null;
        }

        $warningThreshold = $this->numericThreshold('warning');
        // User view: choose the configured analysis run branch for this case.
        if ($measuredValue <= $warningThreshold) {
            // Metric is within budget; null is the rule's "passes, nothing to report" signal to callers.
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
     * Match a value where lower numbers are worse against configured thresholds.
     *
      * User flow: Turns project settings into the analysis run the user requested.
      *
     * @param int|float $measuredValue - Measured rule value to compare.
     *
     * @return ThresholdMatch|null - Matching severity threshold, or null when the value is allowed.
     */
    public function lowValueThresholdMatch(int|float $measuredValue): ?ThresholdMatch
    {
        // User view: choose the configured analysis run branch for this case.
        if ($this->severityThreshold instanceof SeverityThreshold) {
            // Single-threshold override: breach only once the value falls strictly below it.
            return $measuredValue < $this->severityThreshold->threshold
                ? new ThresholdMatch($this->severityThreshold->threshold, $this->severityThreshold->severity)
                : null;
        }

        $warningThreshold = $this->numericThreshold('warning');
        // User view: choose the configured analysis run branch for this case.
        if ($measuredValue >= $warningThreshold) {
            // Metric meets the required floor; null is the rule's "passes, nothing to report" signal to callers.
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
     * Return a configured option by name.
     *
      * User flow: Turns project settings into the analysis run the user requested.
      *
     * @param string $name - Option key to read.
     * @throws LogicException When the option key is missing.
     *
     * @return int|float|bool|string|array<array-key, int|float|bool|string> - Option value.
     */
    public function option(string $name): int|float|bool|string|array
    {
        // User view: choose the configured analysis run branch for this case.
        if (!array_key_exists($name, $this->options)) {
            throw new LogicException(sprintf('Missing option "%s".', $name));
        }

        // The rule's configured option value in its raw union type; callers narrow it (see stringListOption).
        return $this->options[$name];
    }

    /**
     * Return a configured option as a string list.
     *
      * User flow: Turns project settings into the analysis run the user requested.
      *
     * @param string $name - Option key to read.
     * @throws LogicException When the option value is not a list of strings.
     *
     * @return list<string> - String option values.
     */
    public function stringListOption(string $name): array
    {
        // User view: missing data becomes a safe configured analysis run default.
        $optionValue = $this->options[$name] ?? [];

        // User view: choose the configured analysis run branch for this case.
        if (!is_array($optionValue)) {
            throw new LogicException(sprintf('Option "%s" must be an array of strings.', $name));
        }

        $stringOptions = [];

        // User view: add each item that can appear in configured analysis run.
        foreach ($optionValue as $optionItem) {
            // User view: choose the configured analysis run branch for this case.
            if (!is_string($optionItem)) {
                throw new LogicException(sprintf('Option "%s" must contain only strings.', $name));
            }

            $stringOptions[] = $optionItem;
        }

        // The option's configured string values; an unset option yields the empty list, not an error.
        return $stringOptions;
    }
}
