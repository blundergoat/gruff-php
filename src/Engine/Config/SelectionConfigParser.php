<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Rules\RuleRegistry;

/**
 * Parses the `selection` block into include and exclude filters that decide which rules run.
 * Validates keys and named tiers, pillars, and rule ids before building a RuleSelection.
 *
 * Nested arrays are limited to four levels within each selection value; malformed input raises a config error.
 * Retired rule ids are stored under their current ids so filters match current findings.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
 * @phpstan-type ConfigObject array<string, ConfigValue>
 */
final readonly class SelectionConfigParser
{
    /**
     * Creates a selection parser that validates each configured list through the supplied parser.
     *
     * @param StringListConfigParser $stringListConfigParser - Parser that validates selection values as lists of non-empty strings.
     */
    public function __construct(private StringListConfigParser $stringListConfigParser = new StringListConfigParser())
    {
    }

    /**
     * Turns the raw selection block into validated include and exclude filters, rejecting unknown keys
     * and invalid names before the run trusts them.
     *
     * @param ConfigValue  $decodedValue - Raw selection config value.
     * @param RuleRegistry $registry - Registry used to validate selected rule ids.
     *
     * @return RuleSelection - the include/exclude filters (tiers, pillars, rule ids) deciding which rules apply.
     * @throws ConfigException - When the selection config has unknown keys or invalid values.
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
     * Rejects any unrecognised key in the selection block, so a misspelled key fails loudly instead of being silently ignored.
     *
     * @param ConfigObject $selection - Decoded selection block whose keys are validated before per-list parsing.
     *
     * @return void - Returns when every key belongs to the supported selection-key set.
     */
    private function assertKnownKeys(array $selection): void
    {

        foreach (array_keys($selection) as $key) {
            if (!in_array($key, ['tiers', 'pillars', 'rules', 'excludePillars', 'excludeRules'], true)) {
                throw new ConfigException(sprintf('Unknown config key "selection.%s".', $key));
            }
        }
    }

    /**
     * Reads and validates the included tier names, so a run only ever filters by tiers that exist.
     *
     * @param ConfigObject $selection - Decoded selection block; an absent tiers key means no tier filter.
     *
     * @return list<string> - included tier names, each a valid RuleTier; empty when no tier filter is configured.
     */
    private function tiers(array $selection): array
    {
        if (!array_key_exists('tiers', $selection)) {
            return [];
        }

        $tiers = $this->stringListConfigParser->parse($this->configValue($selection['tiers']), 'selection.tiers', false, false);


        foreach ($tiers as $tier) {

            if (RuleTier::tryFrom($tier) === null) {
                throw new ConfigException(sprintf('Unknown rule tier "selection.tiers.%s".', $tier));
            }
        }

        return $tiers;
    }

    /**
     * Validates one include or exclude list so selection filters name existing pillars.
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


        foreach ($pillars as $pillar) {

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
     * @return list<string> - this side's rule ids, each known to the registry, a retired id replaced by its current one; empty when the key is absent
     */
    private function ruleIds(array $selection, string $key, RuleRegistry $registry): array
    {
        // The key is absent, so this side has no rule filter.
        if (!array_key_exists($key, $selection)) {
            return [];
        }

        $ruleIds = $this->stringListConfigParser->parse($this->configValue($selection[$key]), 'selection.' . $key, false, false);


        foreach ($ruleIds as $ruleId) {

            if (!$registry->has($ruleId)) {
                throw new ConfigException(sprintf('Unknown rule id "%s" in "selection.%s".', $ruleId, $key));
            }
        }

        // A 0.5 config may still list a retired id; the selection stores its replacement so it filters today's findings.
        return array_map(RuleRegistry::canonicalRuleId(...), $ruleIds);
    }

    /**
     * Requires a string-keyed selection map, accepting an empty array as an empty block.
     * Validates nested values before the per-key readers use the block.
     *
     * @param ConfigValue $decodedValue - Raw decoded selection value before object-shape validation.
     *
     * @return ConfigObject - The validated string-keyed array used by the per-key readers.
     */
    private function requireObject(object|array|string|int|float|bool|null $decodedValue): array
    {

        if (!is_array($decodedValue) || ($decodedValue !== [] && array_is_list($decodedValue))) {
            throw new ConfigException('Config key "selection" must be an object.');
        }

        $normalizedSelection = [];


        foreach ($decodedValue as $key => $decodedItem) {

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
     * @param mixed $decodedValue - One decoded value to validate; arrays and object leaves are supported.
     *
     * @return ConfigValue - Validated array with at most four levels, or an accepted scalar, object, or null leaf.
     */
    private function configValue(mixed $decodedValue): array|bool|float|int|object|string|null
    {

        if (is_array($decodedValue)) {
            return $this->configArray($decodedValue);
        }

        return $this->configScalar($decodedValue);
    }

    /**
     * Accepts supported non-array leaves without converting them or inspecting object contents.
     *
     * @param mixed $decodedValue - Candidate leaf; scalar values, objects, and null are accepted.
     *
     * @return ConfigScalar - The accepted scalar, object, or null value, returned unchanged.
     */
    private function configScalar(mixed $decodedValue): bool|float|int|object|string|null
    {

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
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>> - Validated subtree with keys preserved.
     */
    private function configArrayDepth2(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];


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
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>> - Validated third-level subtree with original keys.
     */
    private function configArrayDepth3(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];


        foreach ($decodedSelectionValues as $key => $decodedItem) {
            $normalizedSelectionValues[$key] = is_array($decodedItem) ? $this->configArrayDepth4($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedSelectionValues;
    }

    /**
     * Validates the deepest allowed array level, accepting scalar, object, and null leaves.
     * Further arrays are rejected as too deeply nested.
     *
     * @param array<array-key, mixed> $decodedSelectionValues - Decoded selection subtree at the final supported nesting level.
     *
     * @return array<array-key, ConfigScalar> - Validated leaves with keys preserved; nested arrays are rejected.
     */
    private function configArrayDepth4(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];


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
