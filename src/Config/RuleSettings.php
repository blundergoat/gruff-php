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
     * @param array<string, int|float> $thresholds
     * @param array<string, mixed> $options
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
     * @return int|float Threshold value.
     */
    public function numericThreshold(string $name): int|float
    {
        $value = $this->thresholds[$name] ?? null;

        if (!is_int($value) && !is_float($value)) {
            throw new LogicException(sprintf('Missing numeric threshold "%s".', $name));
        }

        return $value;
    }

    /**
     * Match a value where higher numbers are worse against configured thresholds.
     *
     * @return ThresholdMatch|null Matching severity threshold, or null when the value is allowed.
     */
    public function highValueThresholdMatch(int|float $value): ?ThresholdMatch
    {
        if ($this->severityThreshold instanceof SeverityThreshold) {
            return $value > $this->severityThreshold->threshold
                ? new ThresholdMatch($this->severityThreshold->threshold, $this->severityThreshold->severity)
                : null;
        }

        $warningThreshold = $this->numericThreshold('warning');
        if ($value <= $warningThreshold) {
            return null;
        }

        $errorThreshold = $this->numericThreshold('error');
        $severity = $value > $errorThreshold ? Severity::Error : Severity::Warning;

        return new ThresholdMatch(
            $severity === Severity::Error ? $errorThreshold : $warningThreshold,
            $severity,
        );
    }

    /**
     * Match a value where lower numbers are worse against configured thresholds.
     *
     * @return ThresholdMatch|null Matching severity threshold, or null when the value is allowed.
     */
    public function lowValueThresholdMatch(int|float $value): ?ThresholdMatch
    {
        if ($this->severityThreshold instanceof SeverityThreshold) {
            return $value < $this->severityThreshold->threshold
                ? new ThresholdMatch($this->severityThreshold->threshold, $this->severityThreshold->severity)
                : null;
        }

        $warningThreshold = $this->numericThreshold('warning');
        if ($value >= $warningThreshold) {
            return null;
        }

        $errorThreshold = $this->numericThreshold('error');
        $severity = $value < $errorThreshold ? Severity::Error : Severity::Warning;

        return new ThresholdMatch(
            $severity === Severity::Error ? $errorThreshold : $warningThreshold,
            $severity,
        );
    }

    /**
     * Check whether an option was configured.
     *
     * @return bool True when the option key exists.
     */
    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /**
     * Return a configured option by name.
     *
     * @return mixed Option value.
     */
    public function option(string $name): mixed
    {
        if (!array_key_exists($name, $this->options)) {
            throw new LogicException(sprintf('Missing option "%s".', $name));
        }

        return $this->options[$name];
    }

    /**
     * @return list<string>
     */
    public function stringListOption(string $name): array
    {
        $value = $this->options[$name] ?? [];

        if (!is_array($value)) {
            throw new LogicException(sprintf('Option "%s" must be an array of strings.', $name));
        }

        $result = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new LogicException(sprintf('Option "%s" must contain only strings.', $name));
            }

            $result[] = $item;
        }

        return $result;
    }
}
