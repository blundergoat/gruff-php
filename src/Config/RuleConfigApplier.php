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
            // No rules block means nothing to override; hand back the config untouched.
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

        // Every rule's overrides have been folded into $config across the loop; return the merged result.
        return $config;
    }

    /**
     * @param AnalysisConfig $config     Config being threaded through the loop; carries prior rules' overrides.
     * @param RuleRegistry   $registry   Registry consulted for each rule's default thresholds, options, and rubric.
     * @param string         $ruleId     Already-validated rule id this override block belongs to.
     * @param ConfigObject   $ruleConfig One rule's parsed override keys (enabled, threshold, options, ...).
     * @return AnalysisConfig Config with one rule's overrides applied.
     */
    private function applyRuleConfig(
        AnalysisConfig $config,
        RuleRegistry $registry,
        string $ruleId,
        array $ruleConfig,
    ): AnalysisConfig {
        $this->assertKnownRuleKeys($ruleId, $ruleConfig);

        if (array_key_exists('severity', $ruleConfig) && !array_key_exists('threshold', $ruleConfig)) {
            throw new ConfigException(sprintf('Config key "rules.%s.severity" requires "threshold".', $ruleId));
        }

        $settings         = $config->ruleSettings($ruleId);
        $definitionSingle = $registry->get($ruleId)->definition()->severityThreshold;

        if ($definitionSingle instanceof SeverityThreshold && array_key_exists('thresholds', $ruleConfig)) {
            throw new ConfigException(sprintf(
                'Config key "rules.%s.thresholds" is not supported; this rule uses a single threshold and severity.',
                $ruleId,
            ));
        }

        $severityThreshold = $this->severityThreshold($ruleId, $ruleConfig, $registry)
            ?? $settings->severityThreshold;

        // Rebuild the rule's settings from each override key, falling back to existing values where unset.
        return $config->withRuleSettings($ruleId, new RuleSettings(
            enabled:    $this->isEnabled($ruleId, $ruleConfig, $settings->enabled),
            thresholds: $severityThreshold instanceof SeverityThreshold
                ? $settings->thresholds
                : $this->thresholds($ruleId, $ruleConfig, $registry, $settings->thresholds),
            options:           $this->options($ruleId, $ruleConfig, $registry, $settings->options),
            severityThreshold: $severityThreshold,
            excludeFromScore:  $this->excludeFromScore($ruleId, $ruleConfig, $settings->excludeFromScore),
        ));
    }

    /**
     * Reject unknown keys before applying a per-rule override.
     *
     * @param string       $ruleId     Rule id named in the thrown error so the user can locate the bad key.
     * @param ConfigObject $ruleConfig One rule's override block; only its top-level keys are checked here.
     * @return void
     */
    private function assertKnownRuleKeys(string $ruleId, array $ruleConfig): void
    {
        foreach (array_keys($ruleConfig) as $key) {
            if (!in_array($key, ['enabled', 'threshold', 'severity', 'thresholds', 'options', 'excludeFromScore'], true)) {
                throw new ConfigException(sprintf('Unknown config key "rules.%s.%s".', $ruleId, $key));
            }
        }
    }

    /**
     * @param string       $ruleId              Rule id named in the type-error message when the value is non-boolean.
     * @param ConfigObject $ruleConfig          One rule's override block; read for an optional excludeFromScore key.
     * @param bool         $isExcludedByDefault Current flag used when the override omits excludeFromScore.
     * @return bool Effective excludeFromScore flag for the rule.
     */
    private function excludeFromScore(string $ruleId, array $ruleConfig, bool $isExcludedByDefault): bool
    {
        if (!array_key_exists('excludeFromScore', $ruleConfig)) {
            // Key omitted: preserve whatever the rule already had.
            return $isExcludedByDefault;
        }

        if (!is_bool($ruleConfig['excludeFromScore'])) {
            throw new ConfigException(sprintf('Config key "rules.%s.excludeFromScore" must be boolean.', $ruleId));
        }

        // Caller supplied a valid boolean; it wins over the default.
        return $ruleConfig['excludeFromScore'];
    }

    /**
     * @param string       $ruleId            Rule id named in the type-error message when the value is non-boolean.
     * @param ConfigObject $ruleConfig        One rule's override block; read for an optional enabled key.
     * @param bool         $isEnabledByDefault Current enabled state used when the override omits enabled.
     * @return bool Effective enabled flag for the rule.
     */
    private function isEnabled(string $ruleId, array $ruleConfig, bool $isEnabledByDefault): bool
    {
        if (!array_key_exists('enabled', $ruleConfig)) {
            // Key omitted: keep the rule's existing enabled state.
            return $isEnabledByDefault;
        }

        if (!is_bool($ruleConfig['enabled'])) {
            throw new ConfigException(sprintf('Config key "rules.%s.enabled" must be boolean.', $ruleId));
        }

        // Caller supplied a valid boolean; it overrides the default.
        return $ruleConfig['enabled'];
    }

    /**
     * Merge configured thresholds with rule defaults.
     *
     * @param string                   $ruleId            Rule id used in error messages and to look up its allowed thresholds.
     * @param ConfigObject             $ruleConfig        One rule's override block; read for an optional thresholds map.
     * @param RuleRegistry             $registry          Registry queried for the rule's set of valid threshold names.
     * @param array<string, int|float> $defaultThresholds Rule's built-in thresholds, used as the base each override merges onto.
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
            // No thresholds map supplied; the rule keeps its built-in defaults.
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

        // Defaults with each validated override overlaid by name.
        return $thresholds;
    }

    /**
     * @param string       $ruleId     Rule id used in error messages and to look up the rule's rubric and defaults.
     * @param ConfigObject $ruleConfig One rule's override block; read for the single-value threshold/severity pair.
     * @param RuleRegistry $registry   Registry queried for the rule's definition to validate the single-threshold form.
     * @return SeverityThreshold|null Single threshold override when configured.
     */
    private function severityThreshold(
        string $ruleId,
        array $ruleConfig,
        RuleRegistry $registry,
    ): ?SeverityThreshold {
        if (!array_key_exists('threshold', $ruleConfig)) {
            // No single-value threshold configured; signal "no override" so the caller keeps existing settings.
            return null;
        }

        if (array_key_exists('thresholds', $ruleConfig)) {
            throw new ConfigException(sprintf('Config key "rules.%s" cannot combine "threshold" and "thresholds".', $ruleId));
        }

        $thresholdValue    = $ruleConfig['threshold'];
        $severityValue     = $ruleConfig['severity'] ?? null;
        $definition        = $registry->get($ruleId)->definition();
        $defaultThresholds = $definition->defaultThresholds;
        $hasSingleDefault  = $definition->severityThreshold instanceof SeverityThreshold;
        $hasTieredDefault  = array_key_exists('warning', $defaultThresholds)
            && array_key_exists('error', $defaultThresholds)
            && count($defaultThresholds) === 2;

        if (!$hasSingleDefault && !$hasTieredDefault) {
            throw new ConfigException(sprintf('Config key "rules.%s.threshold" is only supported for rules with a threshold/severity rubric.', $ruleId));
        }

        if (!is_int($thresholdValue) && !is_float($thresholdValue)) {
            throw new ConfigException(sprintf('Config key "rules.%s.threshold" must be numeric.', $ruleId));
        }

        if (!is_string($severityValue) || !in_array($severityValue, [Severity::Advisory->value, Severity::Warning->value, Severity::Error->value], true)) {
            throw new ConfigException(sprintf('Config key "rules.%s.severity" must be "advisory", "warning", or "error".', $ruleId));
        }

        // Threshold and severity both validated; pair them into the single-rubric override.
        return new SeverityThreshold($thresholdValue, Severity::from($severityValue));
    }

    /**
     * @param string                   $ruleId            Rule id used to build the error path when the value is rejected.
     * @param string                   $thresholdName     Configured threshold key; must be one the rule declares.
     * @param mixed                    $thresholdValue    Raw configured value, accepted only when numeric.
     * @param array<string, int|float> $allowedThresholds Threshold names the rule permits; an unlisted name is rejected.
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

        // Name is allowed and value is numeric; safe to merge into the threshold map.
        return $thresholdValue;
    }

    /**
     * Merge configured rule options with rule defaults.
     *
     * @param string                         $ruleId         Rule id used in error messages and to look up its allowed options.
     * @param ConfigObject                   $ruleConfig     One rule's override block; read for an optional options map.
     * @param RuleRegistry                   $registry       Registry queried for the rule's allowed option names and default types.
     * @param array<string, RuleOptionValue> $defaultOptions Rule's built-in options, used as the base each override merges onto.
     * @return array<string, RuleOptionValue>
     */
    private function options(
        string $ruleId,
        array $ruleConfig,
        RuleRegistry $registry,
        array $defaultOptions,
    ): array {
        if (!array_key_exists('options', $ruleConfig)) {
            // No options map supplied; the rule keeps its built-in option defaults.
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

        // Defaults with each validated, type-checked override applied by name.
        return $options;
    }

    /**
     * Validate and normalize a configured rule option against its default type.
     *
     * @param string          $ruleId       Rule id used to build the error path when validation fails.
     * @param string          $optionName   Option key used to build the error path when validation fails.
     * @param mixed           $optionValue  Raw configured value, validated against the default's shape.
     * @param RuleOptionValue $defaultValue Default option value used as the type contract.
     * @return RuleOptionValue Option value after type-specific validation.
     */
    private function optionValue(string $ruleId, string $optionName, mixed $optionValue, mixed $defaultValue): int|float|bool|string|array
    {
        if (is_int($defaultValue)) {
            // Default is an int, so the override must validate as an int.
            return $this->integerOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_float($defaultValue)) {
            // Default is a float, so accept any numeric override.
            return $this->numericOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_bool($defaultValue)) {
            // Default is a bool, so the override must validate as a bool.
            return $this->isBooleanOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_string($defaultValue)) {
            // Default is a string, so the override must validate as a string.
            return $this->stringOptionValue($ruleId, $optionName, $optionValue);
        }

        if (array_is_list($defaultValue)) {
            // Default is a list, so validate the override as a list and check its item type.
            return $this->listOptionValue($ruleId, $optionName, $optionValue, $defaultValue);
        }

        // Only an associative map remains; validate keys and per-value types against the default map.
        return $this->mapOptionValue($ruleId, $optionName, $optionValue, $defaultValue);
    }

    /**
     * Validate an integer option value.
     *
     * @param string $ruleId      Rule id used to build the error path when the value is not an integer.
     * @param string $optionName  Option key used to build the error path when the value is not an integer.
     * @param mixed  $optionValue Raw configured value; accepted only when it is an int.
     * @return int Validated option value.
     */
    private function integerOptionValue(string $ruleId, string $optionName, mixed $optionValue): int
    {
        if (!is_int($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be an integer.', $ruleId, $optionName));
        }

        // Narrowed to int by the guard above.
        return $optionValue;
    }

    /**
     * Validate a numeric option value.
     *
     * @param string $ruleId      Rule id used to build the error path when the value is not numeric.
     * @param string $optionName  Option key used to build the error path when the value is not numeric.
     * @param mixed  $optionValue Raw configured value; accepted when it is an int or a float.
     * @return int|float Validated option value.
     */
    private function numericOptionValue(string $ruleId, string $optionName, mixed $optionValue): int|float
    {
        if (!is_int($optionValue) && !is_float($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be numeric.', $ruleId, $optionName));
        }

        // Narrowed to int|float by the guard above.
        return $optionValue;
    }

    /**
     * Validate a boolean option value.
     *
     * @param string $ruleId      Rule id used to build the error path when the value is not boolean.
     * @param string $optionName  Option key used to build the error path when the value is not boolean.
     * @param mixed  $optionValue Raw configured value; accepted only when it is a bool.
     * @return bool Validated option value.
     */
    private function isBooleanOptionValue(string $ruleId, string $optionName, mixed $optionValue): bool
    {
        if (!is_bool($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be boolean.', $ruleId, $optionName));
        }

        // Narrowed to bool by the guard above.
        return $optionValue;
    }

    /**
     * Validate a string option value.
     *
     * @param string $ruleId      Rule id used to build the error path when the value is not a string.
     * @param string $optionName  Option key used to build the error path when the value is not a string.
     * @param mixed  $optionValue Raw configured value; accepted only when it is a string.
     * @return string Validated option value.
     */
    private function stringOptionValue(string $ruleId, string $optionName, mixed $optionValue): string
    {
        if (!is_string($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be a string.', $ruleId, $optionName));
        }

        // Narrowed to string by the guard above.
        return $optionValue;
    }

    /**
     * Validate a list option value and its item type when the default list is typed.
     *
     * @param string                      $ruleId       Rule id used to build the error path when validation fails.
     * @param string                      $optionName   Option key used to build the error path when validation fails.
     * @param mixed                       $optionValue  Raw configured value; must be a list of scalars.
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

            if ($sample === null && !is_string($optionItem)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be a string.', $ruleId, $optionName, $index));
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

        // Every item passed the scalar and sample-type checks; return the validated list.
        return $result;
    }

    /**
     * Validate one configured list item against the default list sample type.
     *
     * @param string $ruleId     Rule id used to build the error path when an item's type is wrong.
     * @param string $optionName Option key used to build the error path when an item's type is wrong.
     * @param int    $index      Zero-based item position, surfaced in the error path to point at the bad entry.
     * @param mixed  $optionItem Configured list item being type-checked.
     * @param mixed  $sample     First default-list item; its type fixes what every configured item must match.
     * @return void
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
     * Validate an associative option map with scalar values.
     *
     * @param string                                  $ruleId       Rule id used to build the error path when validation fails.
     * @param string                                  $optionName   Option key used to build the error path when validation fails.
     * @param mixed                                   $optionValue  Raw configured value; must be a string-keyed map of scalars.
     * @param array<array-key, int|float|bool|string> $defaultValue Default option map used as the type contract.
     * @return array<string, int|float|bool|string> Validated option map.
     */
    private function mapOptionValue(string $ruleId, string $optionName, mixed $optionValue, array $defaultValue): array
    {
        if (!is_array($optionValue) || array_is_list($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be an object.', $ruleId, $optionName));
        }

        $result = [];
        $sample = reset($defaultValue);
        foreach ($optionValue as $key => $configuredValue) {
            if (!is_string($key)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s" keys must be strings.', $ruleId, $optionName));
            }

            if (!is_int($configuredValue) && !is_float($configuredValue) && !is_bool($configuredValue) && !is_string($configuredValue)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s.%s" must be a scalar value.', $ruleId, $optionName, $key));
            }

            if ($sample !== false) {
                $this->assertMapItemType(
                    ruleId:     $ruleId,
                    optionName: $optionName,
                    key:        $key,
                    optionItem: $configuredValue,
                    sample:     $sample,
                );
            }

            $result[$key] = $configuredValue;
        }

        // Every key was a string and every value passed the sample-type check; return the validated map.
        return $result;
    }

    /**
     * Validate one configured map item against the default map sample type.
     *
     * @param string $ruleId     Rule id used to build the error path when an item's type is wrong.
     * @param string $optionName Option key used to build the error path when an item's type is wrong.
     * @param string $key        Configured map key, surfaced in the error path to point at the bad entry.
     * @param mixed  $optionItem Configured map value being type-checked.
     * @param mixed  $sample     A default-map value; its type fixes what every configured value must match.
     * @return void
     */
    private function assertMapItemType(
        string $ruleId,
        string $optionName,
        string $key,
        mixed $optionItem,
        mixed $sample,
    ): void {
        if (is_string($sample) && !is_string($optionItem)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s.%s" must be a string.', $ruleId, $optionName, $key));
        }

        if (is_int($sample) && !is_int($optionItem)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s.%s" must be an integer.', $ruleId, $optionName, $key));
        }

        if (is_float($sample) && !is_int($optionItem) && !is_float($optionItem)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s.%s" must be numeric.', $ruleId, $optionName, $key));
        }

        if (is_bool($sample) && !is_bool($optionItem)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s.%s" must be boolean.', $ruleId, $optionName, $key));
        }
    }

    /**
     * Validate that a decoded rule config value is an object-like array.
     *
     * @param mixed  $decodedValue Decoded YAML/JSON value; must be a string-keyed (non-list) array.
     * @param string $message      Caller-supplied error text thrown when the value is not object-like.
     * @return ConfigObject
     */
    private function requireObject(mixed $decodedValue, string $message): array
    {
        if (!is_array($decodedValue) || ($decodedValue !== [] && array_is_list($decodedValue))) {
            throw new ConfigException($message);
        }

        $normalizedRuleConfig = [];

        foreach ($decodedValue as $key => $decodedItem) {
            if (!is_string($key)) {
                throw new ConfigException($message);
            }

            $normalizedRuleConfig[$key] = $this->configValue($decodedItem);
        }

        // Confirmed string-keyed; each value normalised into the supported config shape.
        return $normalizedRuleConfig;
    }

    /**
     * Normalise one decoded rule config value into the supported value set.
     *
     * @param mixed $decodedValue One decoded YAML/JSON value, either a nested array or a scalar.
     * @return ConfigValue
     */
    private function configValue(mixed $decodedValue): array|bool|float|int|object|string|null
    {
        if (is_array($decodedValue)) {
            // Nested array: descend so each level is bounded to the supported nesting depth.
            return $this->configArray($decodedValue);
        }

        // Leaf value: validate it is a supported scalar.
        return $this->configScalar($decodedValue);
    }

    /**
     * Validate scalar rule config values after YAML decoding.
     *
     * @param mixed $decodedValue One decoded leaf value to confirm is a supported scalar (or null/object).
     * @return ConfigScalar
     */
    private function configScalar(mixed $decodedValue): bool|float|int|object|string|null
    {
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_object($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            // Value is a supported scalar (or null/object); pass it through unchanged.
            return $decodedValue;
        }

        throw new ConfigException('Config value must be YAML/JSON-compatible.');
    }

    /**
     * Keep decoded configuration values within the supported nested scalar shape.
     *
     * @param array<array-key, mixed> $decodedRuleValues Top-level decoded array; nested arrays recurse one level deeper.
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>>
     */
    private function configArray(array $decodedRuleValues): array
    {
        $normalizedRuleValues = [];

        foreach ($decodedRuleValues as $key => $decodedItem) {
            $normalizedRuleValues[$key] = is_array($decodedItem) ? $this->configArrayDepth2($decodedItem) : $this->configScalar($decodedItem);
        }

        // Level-1 values normalised; nested arrays handed to the depth-2 pass.
        return $normalizedRuleValues;
    }

    /**
     * Keep second-level configuration values within the supported scalar shape.
     *
     * @param array<array-key, mixed> $decodedRuleValues Second-level decoded array; nested arrays recurse one level deeper.
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>
     */
    private function configArrayDepth2(array $decodedRuleValues): array
    {
        $normalizedRuleValues = [];

        foreach ($decodedRuleValues as $key => $decodedItem) {
            $normalizedRuleValues[$key] = is_array($decodedItem) ? $this->configArrayDepth3($decodedItem) : $this->configScalar($decodedItem);
        }

        // Level-2 values normalised; nested arrays handed to the depth-3 pass.
        return $normalizedRuleValues;
    }

    /**
     * Keep third-level configuration values within the supported scalar shape.
     *
     * @param array<array-key, mixed> $decodedRuleValues Third-level decoded array; nested arrays recurse one level deeper.
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>>
     */
    private function configArrayDepth3(array $decodedRuleValues): array
    {
        $normalizedRuleValues = [];

        foreach ($decodedRuleValues as $key => $decodedItem) {
            $normalizedRuleValues[$key] = is_array($decodedItem) ? $this->configArrayDepth4($decodedItem) : $this->configScalar($decodedItem);
        }

        // Level-3 values normalised; nested arrays handed to the depth-4 pass (the last allowed level).
        return $normalizedRuleValues;
    }

    /**
     * Keep fourth-level configuration values as scalar config values.
     *
     * @param array<array-key, mixed> $decodedRuleValues Fourth-level decoded array; a further nested array is rejected as too deep.
     * @return array<array-key, ConfigScalar>
     */
    private function configArrayDepth4(array $decodedRuleValues): array
    {
        $normalizedRuleValues = [];

        foreach ($decodedRuleValues as $key => $decodedItem) {
            if (is_array($decodedItem)) {
                throw new ConfigException('Config value nesting is deeper than supported.');
            }

            $normalizedRuleValues[$key] = $this->configScalar($decodedItem);
        }

        // Deepest allowed level: every item must already be a scalar.
        return $normalizedRuleValues;
    }
}
