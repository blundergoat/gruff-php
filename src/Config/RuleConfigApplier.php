<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Rule\RuleRegistry;

final readonly class RuleConfigApplier
{
    /**
     * @param array<string, mixed> $rootConfig
     */
    public function apply(AnalysisConfig $config, RuleRegistry $registry, array $rootConfig): AnalysisConfig
    {
        if (!isset($rootConfig['rules'])) {
            return $config;
        }

        $rulesConfig = $this->requireObject($rootConfig['rules'], 'Config key "rules" must be a JSON object.');

        foreach ($rulesConfig as $ruleId => $ruleConfigValue) {
            if (!$registry->has($ruleId)) {
                throw new ConfigException(sprintf('Unknown rule id "%s".', $ruleId));
            }

            $config = $this->applyRuleConfig(
                $config,
                $registry,
                $ruleId,
                $this->requireObject($ruleConfigValue, sprintf('Config for rule "%s" must be a JSON object.', $ruleId)),
            );
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $ruleConfig
     */
    private function applyRuleConfig(
        AnalysisConfig $config,
        RuleRegistry $registry,
        string $ruleId,
        array $ruleConfig,
    ): AnalysisConfig {
        $this->assertKnownRuleKeys($ruleId, $ruleConfig);

        $settings = $config->ruleSettings($ruleId);

        return $config->withRuleSettings($ruleId, new RuleSettings(
            enabled: $this->enabled($ruleId, $ruleConfig, $settings->enabled),
            thresholds: $this->thresholds($ruleId, $ruleConfig, $registry, $settings->thresholds),
            options: $this->options($ruleId, $ruleConfig, $registry, $settings->options),
        ));
    }

    /**
     * @param array<string, mixed> $ruleConfig
     */
    private function assertKnownRuleKeys(string $ruleId, array $ruleConfig): void
    {
        foreach (array_keys($ruleConfig) as $key) {
            if ($key !== 'enabled' && $key !== 'thresholds' && $key !== 'options') {
                throw new ConfigException(sprintf('Unknown config key "rules.%s.%s".', $ruleId, $key));
            }
        }
    }

    /**
     * @param array<string, mixed> $ruleConfig
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
        if (!array_key_exists('thresholds', $ruleConfig)) {
            return $defaultThresholds;
        }

        $thresholds = $defaultThresholds;
        $thresholdConfig = $this->requireObject(
            $ruleConfig['thresholds'],
            sprintf('Config key "rules.%s.thresholds" must be a JSON object.', $ruleId),
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
     * @param array<string, int|float> $allowedThresholds
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
            sprintf('Config key "rules.%s.options" must be a JSON object.', $ruleId),
        );
        $allowedOptions = $registry->get($ruleId)->definition()->defaultOptions;

        foreach ($optionsConfig as $optionName => $optionValue) {
            if (!array_key_exists($optionName, $allowedOptions)) {
                throw new ConfigException(sprintf('Unknown option "rules.%s.options.%s".', $ruleId, $optionName));
            }

            $options[$optionName] = $optionValue;
        }

        return $options;
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
