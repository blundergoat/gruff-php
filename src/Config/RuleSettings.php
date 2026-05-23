<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Finding\Severity;
use LogicException;

/**
 * Holds per-rule enablement, thresholds, and option values.
 */
final readonly class RuleSettings
{
    /**
     * @param bool                                                                         $enabled           Whether the rule should run for this config.
     * @param array<string, int|float>                                                     $thresholds        Named numeric thresholds available to the rule.
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options           Rule-specific option values from config.
     * @param SeverityThreshold|null                                                       $severityThreshold Optional single threshold/severity override.
     */
    public function __construct(
        public bool $enabled,
        public array $thresholds,
        public array $options = [],
        public ?SeverityThreshold $severityThreshold = null,
    ) {
    }

    /**
     * Return a configured numeric threshold by name.
     *
     * @param string $name Threshold key to read.
     * @throws LogicException When the configured value is missing or non-numeric.
     * @return int|float Threshold value.
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
     * Match a value where higher numbers are worse against configured thresholds.
     *
     * @param int|float $measuredValue Measured rule value to compare.
     * @return ThresholdMatch|null Matching severity threshold, or null when the value is allowed.
     */
    public function highValueThresholdMatch(int|float $measuredValue): ?ThresholdMatch
    {
        if ($this->severityThreshold instanceof SeverityThreshold) {
            return $measuredValue > $this->severityThreshold->threshold
                ? new ThresholdMatch($this->severityThreshold->threshold, $this->severityThreshold->severity)
                : null;
        }

        $warningThreshold = $this->numericThreshold('warning');
        if ($measuredValue <= $warningThreshold) {
            return null;
        }

        $errorThreshold = $this->numericThreshold('error');
        $severity       = $measuredValue > $errorThreshold ? Severity::Error : Severity::Warning;

        return new ThresholdMatch(
            $severity === Severity::Error ? $errorThreshold : $warningThreshold,
            $severity,
        );
    }

    /**
     * Match a value where lower numbers are worse against configured thresholds.
     *
     * @param int|float $measuredValue Measured rule value to compare.
     * @return ThresholdMatch|null Matching severity threshold, or null when the value is allowed.
     */
    public function lowValueThresholdMatch(int|float $measuredValue): ?ThresholdMatch
    {
        if ($this->severityThreshold instanceof SeverityThreshold) {
            return $measuredValue < $this->severityThreshold->threshold
                ? new ThresholdMatch($this->severityThreshold->threshold, $this->severityThreshold->severity)
                : null;
        }

        $warningThreshold = $this->numericThreshold('warning');
        if ($measuredValue >= $warningThreshold) {
            return null;
        }

        $errorThreshold = $this->numericThreshold('error');
        $severity       = $measuredValue < $errorThreshold ? Severity::Error : Severity::Warning;

        return new ThresholdMatch(
            $severity === Severity::Error ? $errorThreshold : $warningThreshold,
            $severity,
        );
    }

    /**
     * Return a configured option by name.
     *
     * @param string $name Option key to read.
     * @throws LogicException When the option key is missing.
     * @return int|float|bool|string|array<array-key, int|float|bool|string> Option value.
     */
    public function option(string $name): int|float|bool|string|array
    {
        if (!array_key_exists($name, $this->options)) {
            throw new LogicException(sprintf('Missing option "%s".', $name));
        }

        return $this->options[$name];
    }

    /**
     * Return a configured option as a string list.
     *
     * @param string $name Option key to read.
     * @throws LogicException When the option value is not a list of strings.
     * @return list<string> String option values.
     */
    public function stringListOption(string $name): array
    {
        $optionValue = $this->options[$name] ?? [];

        if (!is_array($optionValue)) {
            throw new LogicException(sprintf('Option "%s" must be an array of strings.', $name));
        }

        $stringOptions = [];

        foreach ($optionValue as $optionItem) {
            if (!is_string($optionItem)) {
                throw new LogicException(sprintf('Option "%s" must contain only strings.', $name));
            }

            $stringOptions[] = $optionItem;
        }

        return $stringOptions;
    }
}
