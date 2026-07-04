<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Rules\RuleRegistry;

/**
 * Parses the `selection` config block into the include and exclude filters that decide which rules run.
 *
 * A user narrows their scan by writing a `selection:` block - include only certain tiers, pillars, or
 * rules, or exclude some. This turns that raw YAML into a validated `RuleSelection`: it rejects unknown
 * keys, checks every named tier, pillar, and rule id actually exists, and bounds how deeply nested a
 * value can be - so a typo in the selection block becomes a clear config error the user can fix, not a
 * silently misapplied filter.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
 * @phpstan-type ConfigObject array<string, ConfigValue>
 */
final readonly class SelectionConfigParser
{
    /**
     * Wires in the string-list parser used to decode each selection sub-list.
     *
     * @param StringListConfigParser $stringListConfigParser - Parser used for scalar/list selection values.
     */
    public function __construct(private StringListConfigParser $stringListConfigParser = new StringListConfigParser())
    {
    }

    /**
     * Turns the raw `selection` block into validated include and exclude filters, rejecting unknown keys
     * and invalid names before the run trusts them.
     *
     * @param ConfigValue  $decodedValue - Raw selection config value.
     * @param RuleRegistry $registry - Registry used to validate selected rule ids.
     *
     * @return RuleSelection - the include/exclude filters (tiers, pillars, rule ids) deciding which rules apply.
     * @throws ConfigException When the selection config has unknown keys or invalid values.
     */
    public function parse(object|array|string|int|float|bool|null $decodedValue, RuleRegistry $registry): RuleSelection
    {
        $selection = $this->requireObject($decodedValue);
        $this->assertKnownKeys($selection);

        return new RuleSelection(
            tiers:          $this->tiers($selection),
            pillars:        $this->pillars($selection, 'pillars'),
            rules:          $this->ruleIds($selection, 'rules', $registry),
            excludePillars: $this->pillars($selection, 'excludePillars'),
            excludeRules:   $this->ruleIds($selection, 'excludeRules', $registry),
        );
    }

    /**
     * Rejects any unrecognised key in the selection block, so a misspelled key fails loudly instead of
     * being silently ignored.
     *
     * @param ConfigObject $selection - Decoded selection block whose keys are validated before per-list parsing.
     *
     * @return void
     */
    private function assertKnownKeys(array $selection): void
    {
        // Check each key the user wrote against the handful the selection block supports.
        foreach (array_keys($selection) as $key) {
            // An unrecognised key is almost always a typo, so name it in the error rather than ignore it.
            if (!in_array($key, ['tiers', 'pillars', 'rules', 'excludePillars', 'excludeRules'], true)) {
                throw new ConfigException(sprintf('Unknown config key "selection.%s".', $key));
            }
        }
    }

    /**
     * Reads and validates the included tier names, so a run only ever filters by tiers that exist.
     *
     * @param ConfigObject $selection - Decoded selection block; an absent `tiers` key means no tier filter.
     *
     * @return list<string> - included tier names, each a valid RuleTier; empty when no tier filter is configured.
     */
    private function tiers(array $selection): array
    {
        // No `tiers` key means the user set no tier filter, so return an empty list.
        if (!array_key_exists('tiers', $selection)) {
            return [];
        }

        $tiers = $this->stringListConfigParser->parse($this->configValue($selection['tiers']), 'selection.tiers', false, false);

        // Check each named tier is a real one before trusting it.
        foreach ($tiers as $tier) {
            // An unknown tier name is a config mistake, so reject it by name.
            if (RuleTier::tryFrom($tier) === null) {
                throw new ConfigException(sprintf('Unknown rule tier "selection.tiers.%s".', $tier));
            }
        }

        return $tiers;
    }

    /**
     * Reads and validates one pillar list (include or exclude), so a run only filters by pillars that
     * exist.
     *
     * @param ConfigObject $selection - Decoded selection block; the named key decides include versus exclude semantics.
     * @param string       $key - Which selection sub-list to read, 'pillars' (include) or 'excludePillars'.
     *
     * @return list<string> - configured pillar names for this include/exclude side, each a valid Pillar; empty when the key is absent.
     */
    private function pillars(array $selection, string $key): array
    {
        // The key is absent, so this side has no pillar filter.
        if (!array_key_exists($key, $selection)) {
            return [];
        }

        $pillars = $this->stringListConfigParser->parse($this->configValue($selection[$key]), 'selection.' . $key, false, false);

        // Check each named pillar is a real one before trusting it.
        foreach ($pillars as $pillar) {
            // An unknown pillar name is a config mistake, so reject it by name.
            if (Pillar::tryFrom($pillar) === null) {
                throw new ConfigException(sprintf('Unknown pillar "selection.%s.%s".', $key, $pillar));
            }
        }

        return $pillars;
    }

    /**
     * Reads and validates one rule-id list (include or exclude) against the registry, so a run never
     * filters on a rule that does not exist.
     *
     * @param ConfigObject $selection - Decoded selection block; the named key decides include versus exclude semantics.
     * @param string       $key - Which selection sub-list to read, 'rules' (include) or 'excludeRules'.
     * @param RuleRegistry $registry - Source of truth for valid rule ids; unknown ids are rejected.
     *
     * @return list<string> - configured rule ids for this include/exclude side, each recognised by the registry; empty when the key is absent.
     */
    private function ruleIds(array $selection, string $key, RuleRegistry $registry): array
    {
        // The key is absent, so this side has no rule filter.
        if (!array_key_exists($key, $selection)) {
            return [];
        }

        $ruleIds = $this->stringListConfigParser->parse($this->configValue($selection[$key]), 'selection.' . $key, false, false);

        // Check each rule id is one the registry knows before trusting it.
        foreach ($ruleIds as $ruleId) {
            // An unknown rule id is a config mistake, so reject it by name.
            if (!$registry->has($ruleId)) {
                throw new ConfigException(sprintf('Unknown rule id "%s" in "selection.%s".', $ruleId, $key));
            }
        }

        return $ruleIds;
    }

    /**
     * Confirms the selection block is an object (not a list or scalar) and normalises it, so the per-key
     * readers can rely on its shape.
     *
     * @param ConfigValue $decodedValue - Raw decoded `selection` value before object-shape validation.
     *
     * @return ConfigObject - the selection normalised to a string-keyed object the per-key readers expect.
     */
    private function requireObject(object|array|string|int|float|bool|null $decodedValue): array
    {
        // The selection has to be a keyed object; a bare list or scalar is the wrong shape.
        if (!is_array($decodedValue) || ($decodedValue !== [] && array_is_list($decodedValue))) {
            throw new ConfigException('Config key "selection" must be an object.');
        }

        $normalizedSelection = [];

        // Validate and normalise each key/value the user wrote.
        foreach ($decodedValue as $key => $decodedItem) {
            // A non-string key means the block is not the object shape selection needs.
            if (!is_string($key)) {
                throw new ConfigException('Config key "selection" must be an object.');
            }

            $normalizedSelection[$key] = $this->configValue($decodedItem);
        }

        return $normalizedSelection;
    }

    /**
     * Normalises one decoded selection value into the supported shape, recursing into arrays.
     *
     * @param mixed $decodedValue - One raw YAML/JSON-decoded value, scalar or nested array, to validate.
     *
     * @return ConfigValue - the validated value: a depth-limited nested array, or a single accepted scalar leaf.
     */
    private function configValue(mixed $decodedValue): array|bool|float|int|object|string|null
    {
        // Arrays are validated element by element; a scalar is checked on its own.
        if (is_array($decodedValue)) {
            return $this->configArray($decodedValue);
        }

        return $this->configScalar($decodedValue);
    }

    /**
     * Confirms one leaf value is a YAML/JSON-compatible scalar, rejecting anything gruff cannot store.
     *
     * @param mixed $decodedValue - One decoded leaf value; anything not YAML/JSON-compatible is rejected.
     *
     * @return ConfigScalar - the accepted leaf returned verbatim; null is preserved as a legitimate config value.
     */
    private function configScalar(mixed $decodedValue): bool|float|int|object|string|null
    {
        // Only plain scalars (and null) are valid config leaves; anything else is rejected.
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_object($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            return $decodedValue;
        }

        throw new ConfigException('Config value must be YAML/JSON-compatible.');
    }

    /**
     * Validates the first level of a nested selection value, recursing one level deeper per element.
     *
     * @param array<array-key, mixed> $decodedSelectionValues - Decoded selection subtree at the first supported nesting level.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>> - the
     *                          validated first-level subtree, keys preserved, deeper arrays recursed.
     */
    private function configArray(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];

        // Validate each element, recursing into the next nesting level when it is itself an array.
        foreach ($decodedSelectionValues as $key => $decodedItem) {
            $normalizedSelectionValues[$key] = is_array($decodedItem) ? $this->configArrayDepth2($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedSelectionValues;
    }

    /**
     * Validates the second level of a nested selection value, recursing one level deeper per element.
     *
     * @param array<array-key, mixed> $decodedSelectionValues - Decoded selection subtree at the second supported nesting level.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>> - the validated second-level subtree,
     *                          keys preserved, deeper arrays recursed.
     */
    private function configArrayDepth2(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];

        // Validate each element, recursing one level deeper for nested arrays.
        foreach ($decodedSelectionValues as $key => $decodedItem) {
            $normalizedSelectionValues[$key] = is_array($decodedItem) ? $this->configArrayDepth3($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedSelectionValues;
    }

    /**
     * Validates the third level of a nested selection value, recursing into the final allowed level.
     *
     * @param array<array-key, mixed> $decodedSelectionValues - Decoded selection subtree at the third supported nesting level.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>> - the validated third-level subtree, keys preserved, deeper arrays
     *                          recursed.
     */
    private function configArrayDepth3(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];

        // Validate each element, recursing into the final allowed level for nested arrays.
        foreach ($decodedSelectionValues as $key => $decodedItem) {
            $normalizedSelectionValues[$key] = is_array($decodedItem) ? $this->configArrayDepth4($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedSelectionValues;
    }

    /**
     * Validates the deepest allowed level, where every element must be a scalar - any further nesting is
     * rejected as too deep.
     *
     * @param array<array-key, mixed> $decodedSelectionValues - Decoded selection subtree at the final supported nesting level.
     *
     * @return array<array-key, ConfigScalar> - the deepest subtree, scalar leaves only, keys preserved; any further nesting is rejected.
     */
    private function configArrayDepth4(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];

        // At the deepest allowed level every element must be a scalar leaf.
        foreach ($decodedSelectionValues as $key => $decodedItem) {
            // An array here would be a fifth level of nesting, deeper than gruff supports.
            if (is_array($decodedItem)) {
                throw new ConfigException('Config value nesting is deeper than supported.');
            }

            $normalizedSelectionValues[$key] = $this->configScalar($decodedItem);
        }

        return $normalizedSelectionValues;
    }
}
