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
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>>
 * @phpstan-type ConfigObject array<string, ConfigValue>
 */
final readonly class SelectionConfigParser
{
    /**
     * Use the supplied string-list parser when decoding rule selection config.
     *
     * @param StringListConfigParser $stringListConfigParser Parser used for scalar/list selection values.
     */
    public function __construct(private StringListConfigParser $stringListConfigParser = new StringListConfigParser())
    {
    }

    /**
     * @param ConfigValue  $decodedValue Raw selection config value.
     * @param RuleRegistry $registry   Registry used to validate selected rule ids.
     * @return RuleSelection Parsed rule selection filters.
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
     * @param ConfigObject $selection
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
     * @param ConfigObject $selection
     * @return list<string>
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
     * @param ConfigObject $selection
     * @return list<string>
     */
    private function pillars(array $selection, string $key): array
    {
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
     * @param ConfigObject $selection
     * @return list<string>
     */
    private function ruleIds(array $selection, string $key, RuleRegistry $registry): array
    {
        if (!array_key_exists($key, $selection)) {
            return [];
        }

        $ruleIds = $this->stringListConfigParser->parse($this->configValue($selection[$key]), 'selection.' . $key, false, false);

        foreach ($ruleIds as $ruleId) {
            if (!$registry->has($ruleId)) {
                throw new ConfigException(sprintf('Unknown rule id "%s" in "selection.%s".', $ruleId, $key));
            }
        }

        return $ruleIds;
    }

    /**
     * Validate that the selection config is an object-like array.
     *
     * @param ConfigValue $decodedValue
     * @return ConfigObject
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
     * Normalise one decoded selection value into the supported value set.
     *
     * @return ConfigValue
     */
    private function configValue(mixed $decodedValue): array|bool|float|int|object|string|null
    {
        if (is_array($decodedValue)) {
            return $this->configArray($decodedValue);
        }

        return $this->configScalar($decodedValue);
    }

    /**
     * Validate scalar selection config values after YAML decoding.
     *
     * @return ConfigScalar
     */
    private function configScalar(mixed $decodedValue): bool|float|int|object|string|null
    {
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_object($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            return $decodedValue;
        }

        throw new ConfigException('Config value must be YAML/JSON-compatible.');
    }

    /**
     * @param array<array-key, mixed> $decodedSelectionValues
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>>
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
     * @param array<array-key, mixed> $decodedSelectionValues
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>
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
     * @param array<array-key, mixed> $decodedSelectionValues
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>>
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
     * @param array<array-key, mixed> $decodedSelectionValues
     * @return array<array-key, ConfigScalar>
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

        return $normalizedSelectionValues;
    }
}
