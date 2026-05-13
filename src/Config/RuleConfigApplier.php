<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Finding\Severity;
use GruffPhp\Rule\RuleRegistry;

/**
 * Applies parsed rule configuration entries to the effective analysis config.
 * @phpstan-type RuleOptionValue int|float|bool|string|array<array-key, int|float|bool|string>
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>>
 * @phpstan-type ConfigObject array<string, ConfigValue>
 */
final readonly class RuleConfigApplier
{
    /**
     * @param AnalysisConfig $config     Config to update.
     * @param RuleRegistry   $registry   Rule registry used to validate rule ids.
     * @param ConfigObject   $rootConfig Parsed root config object.
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
     * @param ConfigObject $ruleConfig
     * @return AnalysisConfig Config with one rule's overrides applied.
     */
    private function applyRuleConfig(
        AnalysisConfig $config,
        RuleRegistry $registry,
        string $ruleId,
        array $ruleConfig,
    ): AnalysisConfig {
        $this->assertKnownRuleKeys($ruleId, $ruleConfig);

        $settings          = $config->ruleSettings($ruleId);
        $severityThreshold = $this->severityThreshold($ruleId, $ruleConfig, $registry);

        return $config->withRuleSettings($ruleId, new RuleSettings(
            enabled:    $this->isEnabled($ruleId, $ruleConfig, $settings->enabled),
            thresholds: $severityThreshold instanceof SeverityThreshold
                ? $settings->thresholds
                : $this->thresholds($ruleId, $ruleConfig, $registry, $settings->thresholds),
            options:           $this->options($ruleId, $ruleConfig, $registry, $settings->options),
            severityThreshold: $severityThreshold,
        ));
    }

    /**
     * Reject unknown keys before applying a per-rule override.
     *
     * @param ConfigObject $ruleConfig
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
     * @param ConfigObject $ruleConfig
     * @return bool Effective enabled flag for the rule.
     */
    private function isEnabled(string $ruleId, array $ruleConfig, bool $default): bool
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
     * @param ConfigObject             $ruleConfig
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

        $thresholds      = $defaultThresholds;
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
     * @param ConfigObject $ruleConfig
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

        $thresholdValue    = $ruleConfig['threshold'];
        $severityValue     = $ruleConfig['severity'] ?? null;
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
     * @param ConfigObject                   $ruleConfig
     * @param array<string, RuleOptionValue> $defaultOptions
     * @return array<string, RuleOptionValue>
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

        $options       = $defaultOptions;
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
     * @param RuleOptionValue $defaultValue Default option value used as the type contract.
     * @return RuleOptionValue Option value after type-specific validation.
     */
    private function optionValue(string $ruleId, string $optionName, mixed $optionValue, mixed $defaultValue): int|float|bool|string|array
    {
        if (is_int($defaultValue)) {
            return $this->integerOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_float($defaultValue)) {
            return $this->numericOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_bool($defaultValue)) {
            return $this->isBooleanOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_string($defaultValue)) {
            return $this->stringOptionValue($ruleId, $optionName, $optionValue);
        }

        if (array_is_list($defaultValue)) {
            return $this->listOptionValue($ruleId, $optionName, $optionValue, $defaultValue);
        }

        throw new ConfigException(sprintf('Option "rules.%s.options.%s" has an unsupported default value type.', $ruleId, $optionName));
    }

    /**
     * Validate an integer option value.
     *
     * @return int Validated option value.
     */
    private function integerOptionValue(string $ruleId, string $optionName, mixed $optionValue): int
    {
        if (!is_int($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be an integer.', $ruleId, $optionName));
        }

        return $optionValue;
    }

    /**
     * Validate a numeric option value.
     *
     * @return int|float Validated option value.
     */
    private function numericOptionValue(string $ruleId, string $optionName, mixed $optionValue): int|float
    {
        if (!is_int($optionValue) && !is_float($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be numeric.', $ruleId, $optionName));
        }

        return $optionValue;
    }

    /**
     * Validate a boolean option value.
     *
     * @return bool Validated option value.
     */
    private function isBooleanOptionValue(string $ruleId, string $optionName, mixed $optionValue): bool
    {
        if (!is_bool($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be boolean.', $ruleId, $optionName));
        }

        return $optionValue;
    }

    /**
     * Validate a string option value.
     *
     * @return string Validated option value.
     */
    private function stringOptionValue(string $ruleId, string $optionName, mixed $optionValue): string
    {
        if (!is_string($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be a string.', $ruleId, $optionName));
        }

        return $optionValue;
    }

    /**
     * Validate a list option value and its item type when the default list is typed.
     *
     * @param list<int|float|bool|string> $defaultValue Default option list used as an item-type sample.
     * @return list<int|float|bool|string> Validated option list.
     */
    private function listOptionValue(string $ruleId, string $optionName, mixed $optionValue, array $defaultValue): array
    {
        if (!is_array($optionValue) || !array_is_list($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be a list.', $ruleId, $optionName));
        }

        $result = [];
        $sample = $defaultValue[0] ?? null;
        foreach ($optionValue as $index => $optionItem) {
            if (!is_int($optionItem) && !is_float($optionItem) && !is_bool($optionItem) && !is_string($optionItem)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be a scalar value.', $ruleId, $optionName, $index));
            }

            if ($sample !== null) {
                $this->assertListItemType(
                    ruleId:     $ruleId,
                    optionName: $optionName,
                    index:      $index,
                    optionItem: $optionItem,
                    sample:     $sample,
                );
            }

            $result[] = $optionItem;
        }

        return $result;
    }

    /**
     * Validate one configured list item against the default list sample type.
     *
     * @return void No return value.
     */
    private function assertListItemType(
        string $ruleId,
        string $optionName,
        int $index,
        mixed $optionItem,
        mixed $sample,
    ): void {
        if (is_string($sample) && !is_string($optionItem)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be a string.', $ruleId, $optionName, $index));
        }

        if (is_int($sample) && !is_int($optionItem)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be an integer.', $ruleId, $optionName, $index));
        }
    }

    /**
     * Validate that a decoded rule config value is an object-like array.
     *
     * @return ConfigObject
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

            $result[$key] = $this->configValue($item);
        }

        return $result;
    }

    /**
     * Normalise one decoded rule config value into the supported value set.
     *
     * @return ConfigValue
     */
    private function configValue(mixed $value): array|bool|float|int|object|string|null
    {
        if (is_array($value)) {
            return $this->configArray($value);
        }

        return $this->configScalar($value);
    }

    /**
     * Validate scalar rule config values after YAML decoding.
     *
     * @return ConfigScalar
     */
    private function configScalar(mixed $value): bool|float|int|object|string|null
    {
        if (is_bool($value) || is_float($value) || is_int($value) || is_object($value) || is_string($value) || $value === null) {
            return $value;
        }

        throw new ConfigException('Config value must be YAML/JSON-compatible.');
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>>
     */
    private function configArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->configArrayDepth2($item) : $this->configScalar($item);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>
     */
    private function configArrayDepth2(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->configArrayDepth3($item) : $this->configScalar($item);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>>
     */
    private function configArrayDepth3(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->configArrayDepth4($item) : $this->configScalar($item);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, ConfigScalar>
     */
    private function configArrayDepth4(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            if (is_array($item)) {
                throw new ConfigException('Config value nesting is deeper than supported.');
            }

            $result[$key] = $this->configScalar($item);
        }

        return $result;
    }
}
