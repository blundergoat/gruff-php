<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use Closure;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Rules\RuleRegistry;

/**
 * Folds the user's per-rule `rules:` config into the effective analysis config, so a project's
 * .gruff-php.yaml actually changes how each rule behaves on the next run.
 *
 * It walks every `rules.<id>` block, validates each key's type against the rule's declared shape, and
 * rebuilds that rule's settings. Unknown rule ids are warned about and skipped so a config naming a
 * retired rule survives an upgrade; a type error in a known block is a hard error the user must fix.
 *
 * @phpstan-type RuleOptionValue int|float|bool|string|array<array-key, int|float|bool|string>
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
 * @phpstan-type ConfigObject array<string, ConfigValue>
 */
final readonly class RuleConfigApplier
{
    /**
     * Builds the applier, optionally routing config warnings somewhere other than STDERR.
     *
     * @param (Closure(string): void)|null $warningSink - Receiver for one-line config warnings; null writes them to STDERR.
     */
    public function __construct(private ?Closure $warningSink = null)
    {
    }

    /**
     * Returns a config with known `rules.<id>` overrides applied, warning about unknown rule ids.
     *
     * @param AnalysisConfig $config - Existing config used as the base for returned overrides.
     * @param RuleRegistry   $registry - Rule registry used to validate rule ids.
     * @param ConfigObject   $rootConfig - Parsed root config object.
     *
     * @return AnalysisConfig - Config with overrides applied; the same instance when rules is absent, null, or empty.
     * @throws ConfigException - When rule config carries invalid keys or values.
     */
    public function apply(AnalysisConfig $config, RuleRegistry $registry, array $rootConfig): AnalysisConfig
    {
        // An absent or null `rules` section leaves the supplied config unchanged.
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
     * Warns that an unknown rule override is ignored so a typo or retired id can be corrected.
     * Skipping unknown overrides lets configurations containing retired rules remain usable after an upgrade.
     *
     * @param string $ruleId - Unknown rule id named by the config block.
     *
     * @return void - Sends one warning to the configured sink or writes a newline-terminated warning to STDERR.
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
     * Rebuilds one rule's settings (enabled, threshold/severity, thresholds, options, score exclusion) from its override block.
     *
     * @param AnalysisConfig $config - Config being threaded through the loop; carries prior rules' overrides.
     * @param RuleRegistry   $registry - Registry consulted for each rule's default thresholds, options, and rubric.
     * @param string         $ruleId - Already-validated rule id this override block belongs to.
     * @param ConfigObject   $ruleConfig - One rule's parsed override keys (enabled, threshold, options, ...).
     *
     * @return AnalysisConfig - the threaded config with this one rule's settings rebuilt from its override keys.
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

        // A config block keyed by a supported legacy id configures the rule that replaced it; errors retain the supplied key.
        $settings         = $config->ruleSettings(RuleRegistry::canonicalRuleId($ruleId));
        $definitionSingle = $registry->get($ruleId)->definition()->severityThreshold;


        if ($definitionSingle instanceof SeverityThreshold && array_key_exists('thresholds', $ruleConfig)) {
            throw new ConfigException(sprintf(
                                          'Config key "rules.%s.thresholds" is not supported; this rule uses a single threshold and severity.',
                                          $ruleId,
                                      ));
        }

        // When the block sets no single threshold, keep the rule's existing threshold/severity pair.
        $severityThreshold = $this->severityThreshold($ruleId, $ruleConfig, $registry)
                             ?? $settings->severityThreshold;

        return $config->withRuleSettings(RuleRegistry::canonicalRuleId($ruleId), new RuleSettings(
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
     * Rejects any unsupported key in a rule's override block, so a typo like `treshold` fails loudly instead of being silently ignored.
     *
     * @param string       $ruleId - Rule id named in the thrown error so the user can locate the bad key.
     * @param ConfigObject $ruleConfig - One rule's override block; only its top-level keys are checked here.
     *
     * @return void - Completes when every top-level key is supported; throws ConfigException for an unknown key.
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
     * Reads a rule's `excludeFromScore` override, keeping the current flag when the block omits it.
     *
     * @param string       $ruleId - Rule id named in the type-error message when the value is non-boolean.
     * @param ConfigObject $ruleConfig - One rule's override block; read for an optional excludeFromScore key.
     * @param bool         $isExcludedByDefault - Current flag used when the override omits excludeFromScore.
     *
     * @return bool - the override's boolean when present, otherwise the rule's existing excludeFromScore flag.
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
     * Reads a rule's `enabled` override, keeping the current enabled state when the block omits it.
     *
     * @param string       $ruleId - Rule id named in the type-error message when the value is non-boolean.
     * @param ConfigObject $ruleConfig - One rule's override block; read for an optional enabled key.
     * @param bool         $isEnabledByDefault - Current enabled state used when the override omits enabled.
     *
     * @return bool - the override's boolean when present, otherwise the rule's existing enabled state.
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
     * Overlays named threshold overrides onto the rule's current settings, validating names against its definition.
     *
     * @param string                   $ruleId - Rule id used in error messages and to look up its allowed thresholds.
     * @param ConfigObject             $ruleConfig - One rule's override block; read for an optional thresholds map.
     * @param RuleRegistry             $registry - Registry queried for the rule's set of valid threshold names.
     * @param array<string, int|float> $defaultThresholds - Current thresholds retained for names without overrides.
     *
     * @return array<string, int|float> - Effective named thresholds, preserving current values for keys not overridden.
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
     * Reads the single `threshold` (plus required `severity`) override into a SeverityThreshold, or null when the block sets none.
     *
     * @param string       $ruleId - Rule id used in error messages and to look up the rule's rubric and defaults.
     * @param ConfigObject $ruleConfig - One rule's override block; read for the single-value threshold/severity pair.
     * @param RuleRegistry $registry - Registry queried for the rule's definition to validate the single-threshold form.
     *
     * @return SeverityThreshold|null - Validated threshold/severity pair, or null when `threshold` is absent.
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
     * Validates one configured threshold: its name must be one the rule declares and its value numeric.
     *
     * @param string                   $ruleId - Rule id used to build the error path when the value is rejected.
     * @param string                   $thresholdName - Configured threshold key; must be one the rule declares.
     * @param mixed                    $thresholdValue - Raw configured value, accepted only when numeric.
     * @param array<string, int|float> $allowedThresholds - Threshold names the rule permits; an unlisted name is rejected.
     *
     * @return int|float - the configured value, returned unchanged once its name is allowed and its type is numeric.
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
     * Overlays configured options onto the rule's current values, selecting validators by the types of definition defaults.
     *
     * @param string                         $ruleId - Rule id used in error messages and to look up its allowed options.
     * @param ConfigObject                   $ruleConfig - One rule's override block; read for an optional options map.
     * @param RuleRegistry                   $registry - Registry supplying allowed option names and default values.
     * @param array<string, RuleOptionValue> $defaultOptions - Current option values used as the base for configured overrides.
     *
     * @return array<string, RuleOptionValue> - Current options with accepted overrides replacing the corresponding values.
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
     * Routes one configured option to the validator matching its default's type, so an override can only
     * refine a value within the shape the rule already declared.
     *
     * @param string          $ruleId - Rule id used to build the error path when validation fails.
     * @param string          $optionName - Option key used to build the error path when validation fails.
     * @param mixed           $optionValue - Raw configured value, validated against the default's shape.
     * @param RuleOptionValue $defaultValue - Default option value used as the type contract.
     *
     * @return RuleOptionValue - the configured value after validation for the default's type; a float default may return an int unchanged.
     */
    private function optionValue(string $ruleId, string $optionName, mixed $optionValue, mixed $defaultValue): int|float|bool|string|array
    {
        // The default's runtime type selects the validator, so an override keeps the shape the rule declared;
        // only a float default also accepts an int.
        if (is_int($defaultValue)) {
            // An int default forbids the float-tolerant numeric path; an override of 1.0 is a config error.
            return $this->integerOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_float($defaultValue)) {
            // Float defaults accept either numeric type without coercing the configured value.
            return $this->numericOptionValue($ruleId, $optionName, $optionValue);
        }

        if (is_bool($defaultValue)) {

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
     * Validates that an int-typed option's override is a real integer, never coerced from another type.
     *
     * @param string $ruleId - Rule id used to build the error path when the value is not an integer.
     * @param string $optionName - Option key used to build the error path when the value is not an integer.
     * @param mixed  $optionValue - Raw configured value; accepted only when it is an int.
     *
     * @return int - the user's int returned verbatim, never cast or coerced from another type.
     */
    private function integerOptionValue(string $ruleId, string $optionName, mixed $optionValue): int
    {

        if (!is_int($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be an integer.', $ruleId, $optionName));
        }

        return $optionValue;
    }

    /**
     * Validates that a float-typed option's override is numeric, preserving whether it arrived as int or float.
     *
     * @param string $ruleId - Rule id used to build the error path when the value is not numeric.
     * @param string $optionName - Option key used to build the error path when the value is not numeric.
     * @param mixed  $optionValue - Raw configured value; accepted when it is an int or a float.
     *
     * @return int|float - the value with its original numeric type preserved; an int stays int, a float stays float.
     */
    private function numericOptionValue(string $ruleId, string $optionName, mixed $optionValue): int|float
    {

        if (!is_int($optionValue) && !is_float($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be numeric.', $ruleId, $optionName));
        }

        return $optionValue;
    }

    /**
     * Validates that a bool-typed option's override is a real boolean, never a truthy string or 0/1.
     *
     * @param string $ruleId - Rule id used to build the error path when the value is not boolean.
     * @param string $optionName - Option key used to build the error path when the value is not boolean.
     * @param mixed  $optionValue - Raw configured value; accepted only when it is a bool.
     *
     * @return bool - the configured value when it is a real bool; truthy strings or 0/1 are rejected, not coerced.
     */
    private function isBooleanOptionValue(string $ruleId, string $optionName, mixed $optionValue): bool
    {

        if (!is_bool($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be boolean.', $ruleId, $optionName));
        }

        return $optionValue;
    }

    /**
     * Validates that a string-typed option's override is a string, so numeric-looking text stays text.
     *
     * @param string $ruleId - Rule id used to build the error path when the value is not a string.
     * @param string $optionName - Option key used to build the error path when the value is not a string.
     * @param mixed  $optionValue - Raw configured value; accepted only when it is a string.
     *
     * @return string - the configured string untrimmed and uncast; whitespace and numeric-looking text survive.
     */
    private function stringOptionValue(string $ruleId, string $optionName, mixed $optionValue): string
    {

        if (!is_string($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be a string.', $ruleId, $optionName));
        }

        return $optionValue;
    }

    /**
     * Validates a list-typed option: the override must be a list of scalars, each matching the default list's item type.
     *
     * @param string                      $ruleId - Rule id used to build the error path when validation fails.
     * @param string                      $optionName - Option key used to build the error path when validation fails.
     * @param mixed                       $optionValue - Raw configured value; must be a list of scalars.
     * @param list<int|float|bool|string> $defaultValue - Default option list used as an item-type sample.
     *
     * @return list<int|float|bool|string> - the configured list once every item is a scalar matching the default's type.
     */
    private function listOptionValue(string $ruleId, string $optionName, mixed $optionValue, array $defaultValue): array
    {
        // A list option rejects a map or any non-list value.
        if (!is_array($optionValue) || !array_is_list($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be a list.', $ruleId, $optionName));
        }

        $result = [];
        // The first default item fixes the item type; an empty default leaves it null, so items may only be strings.
        $sample = $defaultValue[0] ?? null;
        // Check each configured item against the default's item type.
        foreach ($optionValue as $index => $optionItem) {
            // Every list item must be a scalar, never a nested array or object.
            if (!is_int($optionItem) && !is_float($optionItem) && !is_bool($optionItem) && !is_string($optionItem)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be a scalar value.', $ruleId, $optionName, $index));
            }

            // With no type sample to match, only strings are allowed.
            if ($sample === null && !is_string($optionItem)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be a string.', $ruleId, $optionName, $index));
            }

            // With a type sample, each item must match it.
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
     * Checks one list item against the default list's sample type, rejecting a mismatched string or int.
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
        // A string sample means every item must be a string.
        if (is_string($sample) && !is_string($optionItem)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be a string.', $ruleId, $optionName, $index));
        }

        // An int sample means every item must be an integer.
        if (is_int($sample) && !is_int($optionItem)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s.%d" must be an integer.', $ruleId, $optionName, $index));
        }
    }

    /**
     * Validates a map-typed option: the override must be a string-keyed map of scalars, each value matching the default map's value type.
     *
     * @param string                                  $ruleId - Rule id used to build the error path when validation fails.
     * @param string                                  $optionName - Option key used to build the error path when validation fails.
     * @param mixed                                   $optionValue - Raw configured value; must be a string-keyed map of scalars.
     * @param array<array-key, int|float|bool|string> $defaultValue - Default option map used as the type contract.
     *
     * @return array<string, int|float|bool|string> - the configured map once keys are strings and values match the default's type.
     */
    private function mapOptionValue(string $ruleId, string $optionName, mixed $optionValue, array $defaultValue): array
    {
        // A map option rejects a list or any non-object value.
        if (!is_array($optionValue) || array_is_list($optionValue)) {
            throw new ConfigException(sprintf('Option "rules.%s.options.%s" must be an object.', $ruleId, $optionName));
        }

        $result = [];
        $sample = reset($defaultValue);
        // Check each configured entry's key and value.
        foreach ($optionValue as $key => $configuredValue) {
            // Map keys must be strings.
            if (!is_string($key)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s" keys must be strings.', $ruleId, $optionName));
            }

            // Every value must be a scalar, never a nested array or object.
            if (!is_int($configuredValue) && !is_float($configuredValue) && !is_bool($configuredValue) && !is_string($configuredValue)) {
                throw new ConfigException(sprintf('Option "rules.%s.options.%s.%s" must be a scalar value.', $ruleId, $optionName, $key));
            }

            // With a sample value to match, each entry must share its type.
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
     * Checks one map value against the default map's sample type, rejecting a mismatched scalar.
     *
     * @param string $ruleId - Rule id used to build the error path when an item's type is wrong.
     * @param string $optionName - Option key used to build the error path when an item's type is wrong.
     * @param string $key - Configured map key, surfaced in the error path to point at the bad entry.
     * @param mixed  $optionItem - Configured map value being type-checked.
     * @param mixed  $sample - Default-map sample; float samples allow either numeric type.
     *
     * @return void - Completes if the item meets the sample's scalar constraint; throws ConfigException on a mismatch.
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
     * Normalises a decoded string-keyed config map, accepting an empty array as an empty object.
     *
     * @param mixed  $decodedValue - Decoded map; [] is accepted, while non-empty lists and integer keys are rejected.
     * @param string $message - Caller-supplied error text for invalid map shape or keys; entry validation may throw its own message.
     *
     * @return ConfigObject - String-keyed map with each entry normalised into the supported config shape.
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
     * Normalises one decoded value, validating leaf types and bounding the depth of nested arrays.
     *
     * @param mixed $decodedValue - Decoded array, scalar, null, or object; objects pass through without inspection.
     *
     * @return ConfigValue - The normalised array or unchanged supported leaf value, including null and objects.
     */
    private function configValue(mixed $decodedValue): array|bool|float|int|object|string|null
    {

        if (is_array($decodedValue)) {
            return $this->configArray($decodedValue);
        }

        return $this->configScalar($decodedValue);
    }

    /**
     * Accepts scalar values, null, and objects unchanged; rejects other decoded leaf values.
     *
     * @param mixed $decodedValue - Leaf value; scalars, null, and objects are accepted without conversion.
     *
     * @return ConfigScalar - The same scalar, null, or object; unsupported leaf types are rejected.
     */
    private function configScalar(mixed $decodedValue): bool|float|int|object|string|null
    {

        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_object($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            return $decodedValue;
        }

        throw new ConfigException('Config value must be YAML/JSON-compatible.');
    }

    /**
     * Normalises a top-level config array, recursing one level deeper into nested arrays.
     *
     * @param array<array-key, mixed> $decodedRuleValues - Top-level decoded array; nested arrays recurse one level deeper.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>>
     *   - The array with validated leaves and nested arrays limited to the fourth level.
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
     * Normalises a second-level config array, recursing one level deeper into nested arrays.
     *
     * @param array<array-key, mixed> $decodedRuleValues - Second-level decoded array; nested arrays recurse one level deeper.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>> - Validated leaves and nested arrays.
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
     * Normalises a third-level config array, recursing one level deeper into nested arrays.
     *
     * @param array<array-key, mixed> $decodedRuleValues - Third-level decoded array; nested arrays recurse one level deeper.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>> - Validated leaves and normalised fourth-level arrays.
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
     * Normalises the fourth (deepest allowed) config level, rejecting any array nested deeper still.
     *
     * @param array<array-key, mixed> $decodedRuleValues - Fourth-level decoded array; a further nested array is rejected as too deep.
     *
     * @return array<array-key, ConfigScalar> - Supported leaf values preserved; further nested arrays are rejected.
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
