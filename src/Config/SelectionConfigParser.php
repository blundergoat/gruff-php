<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Rule\RuleRegistry;

/**
 * Parses rule selection configuration into include and exclude filters.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
 * @phpstan-type ConfigObject array<string, ConfigValue>
 */
final readonly class SelectionConfigParser
{
    /**
     * Use the supplied string-list parser when decoding rule selection config.
     *
     * @param StringListConfigParser $stringListConfigParser - Parser used for scalar/list selection values.
     */
    public function __construct(private StringListConfigParser $stringListConfigParser = new StringListConfigParser())
    {
    }

    /**
     * @param ConfigValue  $decodedValue - Raw selection config value.
     * @param RuleRegistry $registry - Registry used to validate selected rule ids.
     *
     * @return RuleSelection - the include/exclude filters (tiers, pillars, rule ids) deciding which rules apply
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
     * Reject unknown selection keys before parsing include/exclude lists.
     *
     * @param ConfigObject $selection - Decoded selection block whose keys are validated before per-list parsing.
     *
     * @return void
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
     * Read included tier names from the selection config.
     *
     * @param ConfigObject $selection - Decoded selection block; an absent `tiers` key means no tier filter.
     *
     * @return list<string> - included tier names, each a valid RuleTier; empty when no tier filter is configured
     */
    private function tiers(array $selection): array
    {
        if (!array_key_exists('tiers', $selection)) {
            // Empty list is the "no tier filter requested" sentinel: every tier stays eligible (default-include).
            return [];
        }

        $tiers = $this->stringListConfigParser->parse($this->configValue($selection['tiers']), 'selection.tiers', false, false);

        foreach ($tiers as $tier) {
            if (RuleTier::tryFrom($tier) === null) {
                throw new ConfigException(sprintf('Unknown rule tier "selection.tiers.%s".', $tier));
            }
        }

        // The configured tier names to include; each is a real RuleTier, so the run can filter on them.
        return $tiers;
    }

    /**
     * Read included pillar names from the selection config.
     *
     * @param ConfigObject $selection - Decoded selection block; the named key decides include versus exclude semantics.
     * @param string       $key - Which selection sub-list to read, 'pillars' (include) or 'excludePillars'.
     *
     * @return list<string> - configured pillar names for this include/exclude side, each a valid Pillar; empty when the key is absent
     */
    private function pillars(array $selection, string $key): array
    {
        if (!array_key_exists($key, $selection)) {
            // Absent include/exclude key means no pillar constraint for this side of the selection.
            return [];
        }

        $pillars = $this->stringListConfigParser->parse($this->configValue($selection[$key]), 'selection.' . $key, false, false);

        foreach ($pillars as $pillar) {
            if (Pillar::tryFrom($pillar) === null) {
                throw new ConfigException(sprintf('Unknown pillar "selection.%s.%s".', $key, $pillar));
            }
        }

        // The configured pillar names for this include/exclude side; each is a real Pillar the run can filter on.
        return $pillars;
    }

    /**
     * Read included rule identifiers from the selection config.
     *
     * @param ConfigObject $selection - Decoded selection block; the named key decides include versus exclude semantics.
     * @param string       $key - Which selection sub-list to read, 'rules' (include) or 'excludeRules'.
     * @param RuleRegistry $registry - Source of truth for valid rule ids; unknown ids are rejected.
     *
     * @return list<string> - configured rule ids for this include/exclude side, each recognised by the registry; empty when the key is absent
     */
    private function ruleIds(array $selection, string $key, RuleRegistry $registry): array
    {
        if (!array_key_exists($key, $selection)) {
            // Absent include/exclude key means no rule-id constraint for this side of the selection.
            return [];
        }

        $ruleIds = $this->stringListConfigParser->parse($this->configValue($selection[$key]), 'selection.' . $key, false, false);

        foreach ($ruleIds as $ruleId) {
            if (!$registry->has($ruleId)) {
                throw new ConfigException(sprintf('Unknown rule id "%s" in "selection.%s".', $ruleId, $key));
            }
        }

        // The configured rule ids for this include/exclude side; each names a rule the registry recognises.
        return $ruleIds;
    }

    /**
     * Validate that the selection config is an object-like array.
     *
     * @param ConfigValue $decodedValue - Raw decoded `selection` value before object-shape validation.
     *
     * @return ConfigObject - the selection normalised to a string-keyed object the per-key readers expect
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

        // The selection as a string-keyed object: the shape the per-key tier/pillar/rule readers expect.
        return $normalizedSelection;
    }

    /**
     * Normalise one decoded selection value into the supported value set.
     *
     * @param mixed $decodedValue - One raw YAML/JSON-decoded value, scalar or nested array, to validate.
     *
     * @return ConfigValue - the validated value: a depth-limited nested array, or a single accepted scalar leaf
     */
    private function configValue(mixed $decodedValue): array|bool|float|int|object|string|null
    {
        if (is_array($decodedValue)) {
            // Arrays recurse so nested structures get depth-limited validation, not scalar treatment.
            return $this->configArray($decodedValue);
        }

        // Anything non-array is a leaf; defer to the scalar gate for the type check.
        return $this->configScalar($decodedValue);
    }

    /**
     * Validate scalar selection config values after YAML decoding.
     *
     * @param mixed $decodedValue - One decoded leaf value; anything not YAML/JSON-compatible is rejected.
     *
     * @return ConfigScalar - the accepted leaf returned verbatim; null is preserved as a legitimate config value
     */
    private function configScalar(mixed $decodedValue): bool|float|int|object|string|null
    {
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_object($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            // An accepted config leaf: returned verbatim, as scalars carry no shape that needs normalising.
            return $decodedValue;
        }

        throw new ConfigException('Config value must be YAML/JSON-compatible.');
    }

    /**
     * Keep decoded configuration values within the supported nested scalar shape.
     *
     * @param array<array-key, mixed> $decodedSelectionValues - Decoded selection subtree at the first supported nesting level.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>> - the
     *                          validated first-level subtree, keys preserved, deeper arrays recursed
     */
    private function configArray(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];

        foreach ($decodedSelectionValues as $key => $decodedItem) {
            $normalizedSelectionValues[$key] = is_array($decodedItem) ? $this->configArrayDepth2($decodedItem) : $this->configScalar($decodedItem);
        }

        // The validated first-level config subtree: scalars plus nested arrays constrained to the supported depth.
        return $normalizedSelectionValues;
    }

    /**
     * Keep second-level configuration values within the supported scalar shape.
     *
     * @param array<array-key, mixed> $decodedSelectionValues - Decoded selection subtree at the second supported nesting level.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>> - the validated second-level subtree,
     *                          keys preserved, deeper arrays recursed
     */
    private function configArrayDepth2(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];

        foreach ($decodedSelectionValues as $key => $decodedItem) {
            $normalizedSelectionValues[$key] = is_array($decodedItem) ? $this->configArrayDepth3($decodedItem) : $this->configScalar($decodedItem);
        }

        // The validated second-level config subtree: scalars plus nested arrays constrained to the supported depth.
        return $normalizedSelectionValues;
    }

    /**
     * Keep third-level configuration values within the supported scalar shape.
     *
     * @param array<array-key, mixed> $decodedSelectionValues - Decoded selection subtree at the third supported nesting level.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>> - the validated third-level subtree, keys preserved, deeper arrays
     *                          recursed
     */
    private function configArrayDepth3(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];

        foreach ($decodedSelectionValues as $key => $decodedItem) {
            $normalizedSelectionValues[$key] = is_array($decodedItem) ? $this->configArrayDepth4($decodedItem) : $this->configScalar($decodedItem);
        }

        // The validated third-level config subtree: scalars plus nested arrays constrained to the supported depth.
        return $normalizedSelectionValues;
    }

    /**
     * Keep fourth-level configuration values as scalar config values.
     *
     * @param array<array-key, mixed> $decodedSelectionValues - Decoded selection subtree at the final supported nesting level.
     *
     * @return array<array-key, ConfigScalar> - the deepest subtree, scalar leaves only, keys preserved; any further nesting is rejected
     */
    private function configArrayDepth4(array $decodedSelectionValues): array
    {
        $normalizedSelectionValues = [];

        foreach ($decodedSelectionValues as $key => $decodedItem) {
            if (is_array($decodedItem)) {
                throw new ConfigException('Config value nesting is deeper than supported.');
            }

            $normalizedSelectionValues[$key] = $this->configScalar($decodedItem);
        }

        // The deepest config level: scalar leaves only, since selection config is capped at four nesting levels.
        return $normalizedSelectionValues;
    }
}
