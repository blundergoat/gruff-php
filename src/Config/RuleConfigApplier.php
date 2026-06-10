<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use Closure;
use GruffPhp\Finding\Severity;
use GruffPhp\Rule\RuleRegistry;

/**
 * Applies parsed rule configuration entries to the effective analysis config.
 * @phpstan-type RuleOptionValue int|float|bool|string|array<array-key, int|float|bool|string>
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
 * @phpstan-type ConfigObject array<string, ConfigValue>
 */
final readonly class RuleConfigApplier
{
    /**
     * Create an applier with an optional warning receiver.
     *
     * @param (Closure(string): void)|null $warningSink - Receiver for one-line config warnings; null writes them to STDERR.
     */
    public function __construct(private ?Closure $warningSink = null)
    {
    }

    /**
     * @param AnalysisConfig $config - Config to update.
     * @param RuleRegistry   $registry - Rule registry used to validate rule ids.
     * @param ConfigObject   $rootConfig - Parsed root config object.
     *
     * @return AnalysisConfig - the input config with every known rule's overrides folded in; unknown rule ids are warned about and skipped
     * @throws ConfigException When rule config carries invalid keys or values.
     */
    public function apply(AnalysisConfig $config, RuleRegistry $registry, array $rootConfig): AnalysisConfig
    {
        if (!isset($rootConfig['rules'])) {
            return $config;
        }

        $rulesConfig = $this->requireObject($rootConfig['rules'], 'Config key "rules" must be an object.');

        foreach ($rulesConfig as $ruleId => $ruleConfigValue) {
            if (!$registry->has($ruleId)) {
                // Warn-and-ignore keeps configs that still carry blocks for retired rules working after an upgrade.
                $this->warnUnknownRuleId($ruleId);
                continue;
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
     * Emit a one-line warning for a config block naming an unknown rule id.
     *
     * Unknown ids under `rules:` are skipped rather than rejected so configs
     * that still name retired rules (for example older init-generated files)
     * keep working; `selection:` entries stay strict because they change which
     * rules run.
     *
     * @param string $ruleId - Unknown rule id named by the config block.
     *
     * @return void
     */
    private function warnUnknownRuleId(string $ruleId): void
    {
        $line = sprintf(
            'gruff-php: ignoring unknown rule id "%s" in config (retired or mistyped); remove the "rules.%s" block to silence this warning.',
            $ruleId,
            $ruleId,
        );

        if ($this->warningSink !== null) {
            ($this->warningSink)($line);

            return;
        }

        fwrite(STDERR, $line . PHP_EOL);
    }

    /**
     * @param AnalysisConfig $config - Config being threaded through the loop; carries prior rules' overrides.
     * @param RuleRegistry   $registry - Registry consulted for each rule's default thresholds, options, and rubric.
     * @param string         $ruleId - Already-validated rule id this override block belongs to.
     * @param ConfigObject   $ruleConfig - One rule's parsed override keys (enabled, threshold, options, ...).
     *
     * @return AnalysisConfig - the threaded config with this one rule's settings rebuilt from its override keys
     */
    private function applyRuleConfig(
        AnalysisConfig $config,
        RuleRegistry   $registry,
        string         $ruleId,
        array          $ruleConfig,
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

        return $config->withRuleSettings($ruleId, new RuleSettings(
            enabled:           $this->isEnabled($ruleId, $ruleConfig, $settings->enabled),
            thresholds:        $severityThreshold instanceof SeverityThreshold
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
     * @param string       $ruleId - Rule id named in the thrown error so the user can locate the bad key.
     * @param ConfigObject $ruleConfig - One rule's override block; only its top-level keys are checked here.
     *
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
     * @param string       $ruleId - Rule id named in the type-error message when the value is non-boolean.
     * @param ConfigObject $ruleConfig - One rule's override block; read for an optional excludeFromScore key.
     * @param bool         $isExcludedByDefault - Current flag used when the override omits excludeFromScore.
     *
     * @return bool - the override's boolean when present, otherwise the rule's existing excludeFromScore flag
     */
    private function excludeFromScore(string $ruleId, array $ruleConfig, bool $isExcludedByDefault): bool
    {
        if (!array_key_exists('excludeFromScore', $ruleConfig)) {
            return $isExcludedByDefault;
        }

        if (!is_bool($ruleConfig['excludeFromScore'])) {
            throw new ConfigException(sprintf('Config key "rules.%s.excludeFromScore" must be boolean.', $ruleId));
        }

        return $ruleConfig['excludeFromScore'];
    }

    /**
     * @param string       $ruleId - Rule id named in the type-error message when the value is non-boolean.
     * @param ConfigObject $ruleConfig - One rule's override block; read for an optional enabled key.
     * @param bool         $isEnabledByDefault - Current enabled state used when the override omits enabled.
     *
     * @return bool - the override's boolean when present, otherwise the rule's existing enabled state
     */
    private function isEnabled(string $ruleId, array $ruleConfig, bool $isEnabledByDefault): bool
    {
        if (!array_key_exists('enabled', $ruleConfig)) {
            return $isEnabledByDefault;
        }

        if (!is_bool($ruleConfig['enabled'])) {
            throw new ConfigException(sprintf('Config key "rules.%s.enabled" must be boolean.', $ruleId));
        }

        return $ruleConfig['enabled'];
    }

    /**
     * Merge configured thresholds with rule defaults.
     *
     * @param string                   $ruleId - Rule id used in error messages and to look up its allowed thresholds.
     * @param ConfigObject             $ruleConfig - One rule's override block; read for an optional thresholds map.
     * @param RuleRegistry             $registry - Registry queried for the rule's set of valid threshold names.
     * @param array<string, int|float> $defaultThresholds - Rule's built-in thresholds, used as the base each override merges onto.
     *
     * @return array<string, int|float> - effective thresholds keyed by name: defaults with any configured overrides overlaid
     */
    private function thresholds(
        string       $ruleId,
        array        $ruleConfig,
        RuleRegistry $registry,
        array        $defaultThresholds,
    ): array {
        if (array_key_exists('severity', $ruleConfig)) {
            throw new ConfigException(sprintf('Config key "rules.%s.severity" requires "threshold".', $ruleId));
        }

        if (!array_key_exists('thresholds', $ruleConfig)) {
            return $defaultThresholds;
        }

        $thresholds        = $defaultThresholds;
        $thresholdConfig   = $this->requireObject(
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
     * @param string       $ruleId - Rule id used in error messages and to look up the rule's rubric and defaults.
     * @param ConfigObject $ruleConfig - One rule's override block; read for the single-value threshold/severity pair.
     * @param RuleRegistry $registry - Registry queried for the rule's definition to validate the single-threshold form.
     *
     * @return SeverityThreshold|null - the validated threshold/severity pair, or null when no "threshold" key is set
     */
    private function severityThreshold(
        string       $ruleId,
        array        $ruleConfig,
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

        return new SeverityThreshold($thresholdValue, Severity::from($severityValue));
    }

    /**
     * @param string                   $ruleId - Rule id used to build the error path when the value is rejected.
     * @param string                   $thresholdName - Configured threshold key; must be one the rule declares.
     * @param mixed                    $thresholdValue - Raw configured value, accepted only when numeric.
     * @param array<string, int|float> $allowedThresholds - Threshold names the rule permits; an unlisted name is rejected.
     *
     * @return int|float - the configured value, returned unchanged once its name is allowed and its type is numeric
     */
    private function thresholdValue(
        string $ruleId,
        string $thresholdName,
        mixed  $thresholdValue,
        array  $allowedThresholds,
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
     * Merge configured rule options with rule defaults.
     *
     * @param string                         $ruleId - Rule id used in error messages and to look up its allowed options.
     * @param ConfigObject                   $ruleConfig - One rule's override block; read for an optional options map.
     * @param RuleRegistry                   $registry - Registry queried for the rule's allowed option names and default types.
     * @param array<string, RuleOptionValue> $defaultOptions - Rule's built-in options, used as the base each override merges onto.
     *
     * @return array<string, RuleOptionValue> - effective options keyed by name: defaults with any validated overrides overlaid
     */
    private function options(
        string       $ruleId,
        array        $ruleConfig,
        RuleRegistry $registry,
        array        $defaultOptions,
    ): array {
        if (!array_key_exists('options', $ruleConfig)) {
            return $defaultOptions;
        }

        $options        = $defaultOptions;
        $optionsConfig  = $this->requireObject(
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
     * @param string          $ruleId - Rule id used to build the error path when validation fails.
     * @param string          $optionName - Option key used to build the error path when validation fails.
     * @param mixed           $optionValue - Raw configured value, validated against the default's shape.
     * @param RuleOptionValue $defaultValue - Default option value used as the type contract.
     *
     * @return RuleOptionValue - the configured value validated against the default's runtime type and returned in that shape
     */
    private function optionValue(string $ruleId, string $optionName, mixed $optionValue, mixed $defaultValue): int|float|bool|string|array
    {
        // The default's runtime type is the contract: each branch picks the validator whose accepted type
        // matches it, so an override can only widen/narrow within that one default-declared shape.
        if (is_int($defaultValue)) {
            // An int default forbids the float-tolerant numeric path; an override of 1.0 is a config error.
            return $this->integerOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_float($defaultValue)) {
            // A float default tolerates an int override (3 for 3.0); both coerce to the same option type.
            return $this->numericOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_bool($defaultValue)) {
            // Bool is checked before the array branches so true/false never reach list/map shape validation.
            return $this->isBooleanOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_string($defaultValue)) {
            // String defaults exclude numeric coercion: "8" must stay a string, not become the int 8.
            return $this->stringOptionValue($ruleId, $optionName, $optionValue);
        }

        if (array_is_list($defaultValue)) {
            // List must precede the map fall-through: an empty default array also satisfies array_is_list,
            // so map is only safely distinguishable as the residual once list is ruled out here.
            return $this->listOptionValue($ruleId, $optionName, $optionValue, $defaultValue);
        }

        // Sole remaining shape is an associative map; its keys and per-value types are checked downstream.
        return $this->mapOptionValue($ruleId, $optionName, $optionValue, $defaultValue);
    }

    /**
     * Validate an integer option value.
     *
     * @param string $ruleId - Rule id used to build the error path when the value is not an integer.
     * @param string $optionName - Option key used to build the error path when the value is not an integer.
     * @param mixed  $optionValue - Raw configured value; accepted only when it is an int.
     *
     * @return int - the user's int returned verbatim, never cast or coerced from another type
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
     * @param string $ruleId - Rule id used to build the error path when the value is not numeric.
     * @param string $optionName - Option key used to build the error path when the value is not numeric.
     * @param mixed  $optionValue - Raw configured value; accepted when it is an int or a float.
     *
     * @return int|float - the value with its original numeric type preserved; an int stays int, a float stays float
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
     * @param string $ruleId - Rule id used to build the error path when the value is not boolean.
     * @param string $optionName - Option key used to build the error path when the value is not boolean.
     * @param mixed  $optionValue - Raw configured value; accepted only when it is a bool.
     *
     * @return bool - the configured value when it is a real bool; truthy strings or 0/1 are rejected, not coerced
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
     * @param string $ruleId - Rule id used to build the error path when the value is not a string.
     * @param string $optionName - Option key used to build the error path when the value is not a string.
     * @param mixed  $optionValue - Raw configured value; accepted only when it is a string.
     *
     * @return string - the configured string untrimmed and uncast; whitespace and numeric-looking text survive
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
     * @param string                      $ruleId - Rule id used to build the error path when validation fails.
     * @param string                      $optionName - Option key used to build the error path when validation fails.
     * @param mixed                       $optionValue - Raw configured value; must be a list of scalars.
     * @param list<int|float|bool|string> $defaultValue - Default option list used as an item-type sample.
     *
     * @return list<int|float|bool|string> - the configured list once every item is a scalar matching the default's type
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

        return $result;
    }

    /**
     * Validate one configured list item against the default list sample type.
     *
     * @param string $ruleId - Rule id used to build the error path when an item's type is wrong.
     * @param string $optionName - Option key used to build the error path when an item's type is wrong.
     * @param int    $index - Zero-based item position, surfaced in the error path to point at the bad entry.
     * @param mixed  $optionItem - Configured list item being type-checked.
     * @param mixed  $sample - First default-list item; its type fixes what every configured item must match.
     *
     * @return void
     */
    private function assertListItemType(
        string $ruleId,
        string $optionName,
        int    $index,
        mixed  $optionItem,
        mixed  $sample,
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
     * @param string                                  $ruleId - Rule id used to build the error path when validation fails.
     * @param string                                  $optionName - Option key used to build the error path when validation fails.
     * @param mixed                                   $optionValue - Raw configured value; must be a string-keyed map of scalars.
     * @param array<array-key, int|float|bool|string> $defaultValue - Default option map used as the type contract.
     *
     * @return array<string, int|float|bool|string> - the configured map once keys are strings and values match the default's type
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

        return $result;
    }

    /**
     * Validate one configured map item against the default map sample type.
     *
     * @param string $ruleId - Rule id used to build the error path when an item's type is wrong.
     * @param string $optionName - Option key used to build the error path when an item's type is wrong.
     * @param string $key - Configured map key, surfaced in the error path to point at the bad entry.
     * @param mixed  $optionItem - Configured map value being type-checked.
     * @param mixed  $sample - A default-map value; its type fixes what every configured value must match.
     *
     * @return void
     */
    private function assertMapItemType(
        string $ruleId,
        string $optionName,
        string $key,
        mixed  $optionItem,
        mixed  $sample,
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
     * @param mixed  $decodedValue - Decoded YAML/JSON value; must be a string-keyed (non-list) array.
     * @param string $message - Caller-supplied error text thrown when the value is not object-like.
     *
     * @return ConfigObject - the value as a string-keyed map with each entry normalised into the supported config shape
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

        return $normalizedRuleConfig;
    }

    /**
     * Normalise one decoded rule config value into the supported value set.
     *
     * @param mixed $decodedValue - One decoded YAML/JSON value, either a nested array or a scalar.
     *
     * @return ConfigValue - the value normalised into the supported shape: a depth-bounded nested array or a validated scalar
     */
    private function configValue(mixed $decodedValue): array|bool|float|int|object|string|null
    {
        if (is_array($decodedValue)) {
            return $this->configArray($decodedValue);
        }

        return $this->configScalar($decodedValue);
    }

    /**
     * Validate scalar rule config values after YAML decoding.
     *
     * @param mixed $decodedValue - One decoded leaf value to confirm is a supported scalar (or null/object).
     *
     * @return ConfigScalar - the same value passed through unchanged once confirmed to be a supported scalar, null, or object
     */
    private function configScalar(mixed $decodedValue): bool|float|int|object|string|null
    {
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_object($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            return $decodedValue;
        }

        throw new ConfigException('Config value must be YAML/JSON-compatible.');
    }

    /**
     * Keep decoded configuration values within the supported nested scalar shape.
     *
     * @param array<array-key, mixed> $decodedRuleValues - Top-level decoded array; nested arrays recurse one level deeper.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>> - the
     *                          level-1 array with scalars validated and nested arrays normalised through the depth-2 pass
     */
    private function configArray(array $decodedRuleValues): array
    {
        $normalizedRuleValues = [];

        foreach ($decodedRuleValues as $key => $decodedItem) {
            $normalizedRuleValues[$key] = is_array($decodedItem) ? $this->configArrayDepth2($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedRuleValues;
    }

    /**
     * Keep second-level configuration values within the supported scalar shape.
     *
     * @param array<array-key, mixed> $decodedRuleValues - Second-level decoded array; nested arrays recurse one level deeper.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>> - the level-2 array with scalars
     *                          validated and nested arrays normalised through the depth-3 pass
     */
    private function configArrayDepth2(array $decodedRuleValues): array
    {
        $normalizedRuleValues = [];

        foreach ($decodedRuleValues as $key => $decodedItem) {
            $normalizedRuleValues[$key] = is_array($decodedItem) ? $this->configArrayDepth3($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedRuleValues;
    }

    /**
     * Keep third-level configuration values within the supported scalar shape.
     *
     * @param array<array-key, mixed> $decodedRuleValues - Third-level decoded array; nested arrays recurse one level deeper.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>> - the level-3 array with scalars validated and nested arrays normalised
     *                          through the depth-4 pass
     */
    private function configArrayDepth3(array $decodedRuleValues): array
    {
        $normalizedRuleValues = [];

        foreach ($decodedRuleValues as $key => $decodedItem) {
            $normalizedRuleValues[$key] = is_array($decodedItem) ? $this->configArrayDepth4($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedRuleValues;
    }

    /**
     * Keep fourth-level configuration values as scalar config values.
     *
     * @param array<array-key, mixed> $decodedRuleValues - Fourth-level decoded array; a further nested array is rejected as too deep.
     *
     * @return array<array-key, ConfigScalar> - the deepest allowed level with every item confirmed to be a supported scalar
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

        return $normalizedRuleValues;
    }
}
