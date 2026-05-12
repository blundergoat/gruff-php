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
     * @param ConfigValue  $value    Raw selection config value.
     * @param RuleRegistry $registry Registry used to validate selected rule ids.
     * @return RuleSelection Parsed rule selection filters.
     * @throws ConfigException When the selection config has unknown keys or invalid values.
     */
    public function parse(object|array|string|int|float|bool|null $value, RuleRegistry $registry): RuleSelection
    {
        $selection = $this->requireObject($value);
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
     * @param ConfigValue $value
     * @return ConfigObject
     */
    private function requireObject(object|array|string|int|float|bool|null $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ConfigException('Config key "selection" must be an object.');
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new ConfigException('Config key "selection" must be an object.');
            }

            $result[$key] = $this->configValue($item);
        }

        return $result;
    }

    /**
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
