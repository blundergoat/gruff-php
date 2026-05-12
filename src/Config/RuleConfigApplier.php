<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Finding\Severity;
use GruffPhp\Rule\RuleRegistry;

/**
 * Applies parsed rule configuration entries to the effective analysis config.
 */
final readonly class RuleConfigApplier
{
    /**
     * @param AnalysisConfig $config Config to update.
     * @param RuleRegistry $registry Rule registry used to validate rule ids.
     * @param array<string, mixed> $rootConfig Parsed root config object.
     * @throws ConfigException When rule config references unknown ids or invalid values.
     * @return AnalysisConfig Config with rule-specific overrides applied.
     */
    public function apply(AnalysisConfig $config, RuleRegistry $registry, array $rootConfig): AnalysisConfig
    {
        if (!isset($rootConfig['rules'])) {
            return $config;
        }

        $rulesConfig = $this->requireObject($rootConfig['rules'], 'Config key "rules" must be an object.');

        foreach ($rulesConfig as $ruleId => $ruleConfigValue) {
            if (!$registry->has($ruleId)) {
                throw new ConfigException(sprintf('Unknown rule id "%s".', $ruleId));
            }

            $config = $this->applyRuleConfig(
                $config,
                $registry,
                $ruleId,
                $this->requireObject($ruleConfigValue, sprintf('Config for rule "%s" must be an object.', $ruleId)),
            );
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $ruleConfig
     * @return AnalysisConfig Config with one rule's overrides applied.
     */
    private function applyRuleConfig(
        AnalysisConfig $config,
        RuleRegistry $registry,
        string $ruleId,
        array $ruleConfig,
    ): AnalysisConfig {
        $this->assertKnownRuleKeys($ruleId, $ruleConfig);

        $settings = $config->ruleSettings($ruleId);
        $severityThreshold = $this->severityThreshold($ruleId, $ruleConfig, $registry);

        return $config->withRuleSettings($ruleId, new RuleSettings(
            enabled: $this->enabled($ruleId, $ruleConfig, $settings->enabled),
            thresholds: $severityThreshold instanceof SeverityThreshold
                ? $settings->thresholds
                : $this->thresholds($ruleId, $ruleConfig, $registry, $settings->thresholds),
            options: $this->options($ruleId, $ruleConfig, $registry, $settings->options),
            severityThreshold: $severityThreshold,
        ));
    }

    /**
     * @param array<string, mixed> $ruleConfig
     * @return void
     */
    private function assertKnownRuleKeys(string $ruleId, array $ruleConfig): void
    {
        foreach (array_keys($ruleConfig) as $key) {
            if (!in_array($key, ['enabled', 'threshold', 'severity', 'thresholds', 'options'], true)) {
                throw new ConfigException(sprintf('Unknown config key "rules.%s.%s".', $ruleId, $key));
            }
        }
    }

    /**
     * @param array<string, mixed> $ruleConfig
     * @return bool Effective enabled flag for the rule.
     */
    private function enabled(string $ruleId, array $ruleConfig, bool $default): bool
    {
        if (!array_key_exists('enabled', $ruleConfig)) {
            return $default;
        }

        if (!is_bool($ruleConfig['enabled'])) {
            throw new ConfigException(sprintf('Config key "rules.%s.enabled" must be boolean.', $ruleId));
        }

        return $ruleConfig['enabled'];
    }

    /**
     * @param array<string, mixed> $ruleConfig
     * @param array<string, int|float> $defaultThresholds
     * @return array<string, int|float>
     */
    private function thresholds(
        string $ruleId,
        array $ruleConfig,
        RuleRegistry $registry,
        array $defaultThresholds,
    ): array {
        if (array_key_exists('severity', $ruleConfig)) {
            throw new ConfigException(sprintf('Config key "rules.%s.severity" requires "threshold".', $ruleId));
        }

        if (!array_key_exists('thresholds', $ruleConfig)) {
            return $defaultThresholds;
        }

        $thresholds = $defaultThresholds;
        $thresholdConfig = $this->requireObject(
            $ruleConfig['thresholds'],
            sprintf('Config key "rules.%s.thresholds" must be an object.', $ruleId),
        );
        $allowedThresholds = $registry->get($ruleId)->definition()->defaultThresholds;

        foreach ($thresholdConfig as $thresholdName => $thresholdValue) {
            $thresholds[$thresholdName] = $this->thresholdValue(
                $ruleId,
                $thresholdName,
                $thresholdValue,
                $allowedThresholds,
            );
        }

        return $thresholds;
    }

    /**
     * @param array<string, mixed> $ruleConfig
     * @return SeverityThreshold|null Single threshold override when configured.
     */
    private function severityThreshold(
        string $ruleId,
        array $ruleConfig,
        RuleRegistry $registry,
    ): ?SeverityThreshold {
        if (!array_key_exists('threshold', $ruleConfig)) {
            return null;
        }

        if (array_key_exists('thresholds', $ruleConfig)) {
            throw new ConfigException(sprintf('Config key "rules.%s" cannot combine "threshold" and "thresholds".', $ruleId));
        }

        $thresholdValue = $ruleConfig['threshold'];
        $severityValue = $ruleConfig['severity'] ?? null;
        $defaultThresholds = $registry->get($ruleId)->definition()->defaultThresholds;

        if (!array_key_exists('warning', $defaultThresholds) || !array_key_exists('error', $defaultThresholds) || count($defaultThresholds) !== 2) {
            throw new ConfigException(sprintf('Config key "rules.%s.threshold" is only supported for rules with warning/error thresholds.', $ruleId));
        }

        if (!is_int($thresholdValue) && !is_float($thresholdValue)) {
            throw new ConfigException(sprintf('Config key "rules.%s.threshold" must be numeric.', $ruleId));
        }

        if (!is_string($severityValue) || !in_array($severityValue, [Severity::Warning->value, Severity::Error->value], true)) {
            throw new ConfigException(sprintf('Config key "rules.%s.severity" must be "warning" or "error".', $ruleId));
        }

        return new SeverityThreshold($thresholdValue, Severity::from($severityValue));
    }

    /**
     * @param array<string, int|float> $allowedThresholds
     * @return int|float Validated threshold value.
     */
    private function thresholdValue(
        string $ruleId,
        string $thresholdName,
        mixed $thresholdValue,
        array $allowedThresholds,
    ): int|float {
        if (!array_key_exists($thresholdName, $allowedThresholds)) {
            throw new ConfigException(sprintf('Unknown threshold "rules.%s.thresholds.%s".', $ruleId, $thresholdName));
        }

        if (!is_int($thresholdValue) && !is_float($thresholdValue)) {
            throw new ConfigException(sprintf('Threshold "rules.%s.thresholds.%s" must be numeric.', $ruleId, $thresholdName));
        }

        return $thresholdValue;
    }

    /**
     * @param array<string, mixed> $ruleConfig
     * @param array<string, mixed> $defaultOptions
     * @return array<string, mixed>
     */
    private function options(
        string $ruleId,
        array $ruleConfig,
        RuleRegistry $registry,
        array $defaultOptions,
    ): array {
        if (!array_key_exists('options', $ruleConfig)) {
            return $defaultOptions;
        }

        $options = $defaultOptions;
        $optionsConfig = $this->requireObject(
            $ruleConfig['options'],
            sprintf('Config key "rules.%s.options" must be an object.', $ruleId),
        );
        $allowedOptions = $registry->get($ruleId)->definition()->defaultOptions;

        foreach ($optionsConfig as $optionName => $optionValue) {
            if (!array_key_exists($optionName, $allowedOptions)) {
                throw new ConfigException(sprintf('Unknown option "rules.%s.options.%s".', $ruleId, $optionName));
            }

            $options[$optionName] = $this->optionValue($ruleId, $optionName, $optionValue, $allowedOptions[$optionName]);
        }

        return $options;
    }

    /**
     * Validate and normalize a configured rule option against its default type.
     *
     * @return mixed Option value after type-specific validation.
     */
    private function optionValue(string $ruleId, string $optionName, mixed $optionValue, mixed $defaultValue): mixed
    {
        if (is_int($defaultValue)) {
            if (!is_int($optionValue)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be an integer.', $ruleId, $optionName));
            }

            return $optionValue;
        }

        if (is_float($defaultValue)) {
            if (!is_int($optionValue) && !is_float($optionValue)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be numeric.', $ruleId, $optionName));
            }

            return $optionValue;
        }

        if (is_bool($defaultValue)) {
            if (!is_bool($optionValue)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be boolean.', $ruleId, $optionName));
            }

            return $optionValue;
        }

        if (is_string($defaultValue)) {
            if (!is_string($optionValue)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be a string.', $ruleId, $optionName));
            }

            return $optionValue;
        }

        if (is_array($defaultValue) && array_is_list($defaultValue)) {
            if (!is_array($optionValue) || !array_is_list($optionValue)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be a list.', $ruleId, $optionName));
            }

            if ($defaultValue === []) {
                return $optionValue;
            }

            $sample = $defaultValue[0];
            foreach ($optionValue as $index => $item) {
                if (is_string($sample) && !is_string($item)) {
                    throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be a string.', $ruleId, $optionName, $index));
                }

                if (is_int($sample) && !is_int($item)) {
                    throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be an integer.', $ruleId, $optionName, $index));
                }
            }
        }

        return $optionValue;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireObject(mixed $value, string $message): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ConfigException($message);
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new ConfigException($message);
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
