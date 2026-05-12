<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Rule\RuleRegistry;

/**
 * Parses rule selection configuration into include and exclude filters.
 */
final readonly class SelectionConfigParser
{
    /**
     * Use the supplied string-list parser when decoding rule selection config.
     *
     * @param StringListConfigParser $strings Parser used for scalar/list selection values.
     */
    public function __construct(private StringListConfigParser $strings = new StringListConfigParser())
    {
    }

    /**
     * @param array<array-key, mixed>|bool|float|int|object|string|null $value    Raw selection config value.
     * @param RuleRegistry                                              $registry Registry used to validate selected rule ids.
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
     * @param array<string, mixed> $selection
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
     * @param array<string, mixed> $selection
     * @return list<string>
     */
    private function tiers(array $selection): array
    {
        if (!array_key_exists('tiers', $selection)) {
            return [];
        }

        $tiers = $this->strings->parse($this->configValue($selection['tiers']), 'selection.tiers', false, false);

        foreach ($tiers as $tier) {
            if (RuleTier::tryFrom($tier) === null) {
                throw new ConfigException(sprintf('Unknown rule tier "selection.tiers.%s".', $tier));
            }
        }

        return $tiers;
    }

    /**
     * @param array<string, mixed> $selection
     * @return list<string>
     */
    private function pillars(array $selection, string $key): array
    {
        if (!array_key_exists($key, $selection)) {
            return [];
        }

        $pillars = $this->strings->parse($this->configValue($selection[$key]), 'selection.' . $key, false, false);

        foreach ($pillars as $pillar) {
            if (Pillar::tryFrom($pillar) === null) {
                throw new ConfigException(sprintf('Unknown pillar "selection.%s.%s".', $key, $pillar));
            }
        }

        return $pillars;
    }

    /**
     * @param array<string, mixed> $selection
     * @return list<string>
     */
    private function ruleIds(array $selection, string $key, RuleRegistry $registry): array
    {
        if (!array_key_exists($key, $selection)) {
            return [];
        }

        $ruleIds = $this->strings->parse($this->configValue($selection[$key]), 'selection.' . $key, false, false);

        foreach ($ruleIds as $ruleId) {
            if (!$registry->has($ruleId)) {
                throw new ConfigException(sprintf('Unknown rule id "%s" in "selection.%s".', $ruleId, $key));
            }
        }

        return $ruleIds;
    }

    /**
     * @param array<array-key, mixed>|bool|float|int|object|string|null $value
     * @return array<string, mixed>
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

            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @return array<array-key, mixed>|bool|float|int|object|string|null
     */
    private function configValue(mixed $value): array|bool|float|int|object|string|null
    {
        if (is_array($value) || is_bool($value) || is_float($value) || is_int($value) || is_object($value) || is_string($value) || $value === null) {
            return $value;
        }

        throw new ConfigException('Config value must be YAML/JSON-compatible.');
    }
}
