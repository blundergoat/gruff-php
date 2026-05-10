<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Rule\RuleRegistry;
use JsonException;

final readonly class ConfigLoader
{
    private const DEFAULT_CONFIG_FILE = '.gruff.json';

    public function __construct(private string $projectRoot)
    {
    }

    public function load(?string $configPath, RuleRegistry $registry): AnalysisConfig
    {
        $config = AnalysisConfig::fromRegistry($registry);
        $resolvedPath = $this->resolvePath($configPath);

        if ($resolvedPath === null) {
            return $config;
        }

        return $this->applyConfigFile($config, $registry, $resolvedPath);
    }

    private function resolvePath(?string $configPath): ?string
    {
        if ($configPath !== null && $configPath !== '') {
            $path = $configPath[0] === '/' ? $configPath : $this->projectRoot . '/' . $configPath;

            if (!is_file($path)) {
                throw new ConfigException(sprintf('Config file not found: %s', $configPath));
            }

            return $path;
        }

        $defaultPath = $this->projectRoot . '/' . self::DEFAULT_CONFIG_FILE;

        return is_file($defaultPath) ? $defaultPath : null;
    }

    private function applyConfigFile(AnalysisConfig $config, RuleRegistry $registry, string $path): AnalysisConfig
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ConfigException(sprintf('Unable to read config file: %s', $path));
        }

        $decoded = $this->decodeJson($contents);
        $rootConfig = $this->requireObject($decoded, 'Config root must be a JSON object.');

        foreach (array_keys($rootConfig) as $rootKey) {
            if (!in_array($rootKey, ['rules', 'minimumPhpVersion', 'paths', 'allowlists', 'selection'], true)) {
                throw new ConfigException(sprintf('Unknown config key "%s".', $rootKey));
            }
        }

        if (array_key_exists('minimumPhpVersion', $rootConfig)) {
            $version = $rootConfig['minimumPhpVersion'];
            if (!is_int($version) && !is_float($version)) {
                throw new ConfigException('Config key "minimumPhpVersion" must be numeric.');
            }

            if ($version < 7.4) {
                throw new ConfigException('Config key "minimumPhpVersion" must be at least 7.4.');
            }

            $config = $config->withMinimumPhpVersion((float) $version);
        }

        if (array_key_exists('paths', $rootConfig)) {
            $config = $config->withIgnoredPathPatterns($this->parsePathsConfig($rootConfig['paths']));
        }

        if (array_key_exists('allowlists', $rootConfig)) {
            $allowlists = $this->parseAllowlistsConfig($rootConfig['allowlists']);
            $config = $config
                ->withAcceptedAbbreviations($allowlists['acceptedAbbreviations'])
                ->withAllowedSecretPreviews($allowlists['secretPreviews']);
        }

        if (array_key_exists('selection', $rootConfig)) {
            $config = $config->withRuleSelection($this->parseSelectionConfig($rootConfig['selection'], $registry));
        }

        if (!isset($rootConfig['rules'])) {
            return $config;
        }

        $rulesConfig = $this->requireObject($rootConfig['rules'], 'Config key "rules" must be a JSON object.');

        foreach ($rulesConfig as $ruleId => $ruleConfigValue) {
            if (!$registry->has($ruleId)) {
                throw new ConfigException(sprintf('Unknown rule id "%s".', $ruleId));
            }

            $ruleConfig = $this->requireObject(
                $ruleConfigValue,
                sprintf('Config for rule "%s" must be a JSON object.', $ruleId),
            );

            $config = $this->applyRuleConfig($config, $registry, $ruleId, $ruleConfig);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $ruleConfig
     */
    private function applyRuleConfig(
        AnalysisConfig $config,
        RuleRegistry $registry,
        string $ruleId,
        array $ruleConfig,
    ): AnalysisConfig {
        foreach (array_keys($ruleConfig) as $key) {
            if ($key !== 'enabled' && $key !== 'thresholds' && $key !== 'options') {
                throw new ConfigException(sprintf('Unknown config key "rules.%s.%s".', $ruleId, $key));
            }
        }

        $settings = $config->ruleSettings($ruleId);
        $enabled = $settings->enabled;
        $thresholds = $settings->thresholds;
        $options = $settings->options;

        if (array_key_exists('enabled', $ruleConfig)) {
            if (!is_bool($ruleConfig['enabled'])) {
                throw new ConfigException(sprintf('Config key "rules.%s.enabled" must be boolean.', $ruleId));
            }

            $enabled = $ruleConfig['enabled'];
        }

        if (array_key_exists('thresholds', $ruleConfig)) {
            $thresholdConfig = $this->requireObject(
                $ruleConfig['thresholds'],
                sprintf('Config key "rules.%s.thresholds" must be a JSON object.', $ruleId),
            );

            $allowedThresholds = $registry->get($ruleId)->definition()->defaultThresholds;

            foreach ($thresholdConfig as $thresholdName => $thresholdValue) {
                if (!array_key_exists($thresholdName, $allowedThresholds)) {
                    throw new ConfigException(sprintf(
                        'Unknown threshold "rules.%s.thresholds.%s".',
                        $ruleId,
                        $thresholdName,
                    ));
                }

                if (!is_int($thresholdValue) && !is_float($thresholdValue)) {
                    throw new ConfigException(sprintf(
                        'Threshold "rules.%s.thresholds.%s" must be numeric.',
                        $ruleId,
                        $thresholdName,
                    ));
                }

                $thresholds[$thresholdName] = $thresholdValue;
            }
        }

        if (array_key_exists('options', $ruleConfig)) {
            $optionsConfig = $this->requireObject(
                $ruleConfig['options'],
                sprintf('Config key "rules.%s.options" must be a JSON object.', $ruleId),
            );

            $allowedOptions = $registry->get($ruleId)->definition()->defaultOptions;

            foreach ($optionsConfig as $optionName => $optionValue) {
                if (!array_key_exists($optionName, $allowedOptions)) {
                    throw new ConfigException(sprintf(
                        'Unknown option "rules.%s.options.%s".',
                        $ruleId,
                        $optionName,
                    ));
                }

                $options[$optionName] = $optionValue;
            }
        }

        return $config->withRuleSettings($ruleId, new RuleSettings($enabled, $thresholds, $options));
    }

    /**
     * @return list<string>
     */
    private function parsePathsConfig(mixed $value): array
    {
        $pathsConfig = $this->requireObject($value, 'Config key "paths" must be a JSON object.');

        foreach (array_keys($pathsConfig) as $key) {
            if ($key !== 'ignore') {
                throw new ConfigException(sprintf('Unknown config key "paths.%s".', $key));
            }
        }

        if (!array_key_exists('ignore', $pathsConfig)) {
            return [];
        }

        return $this->parseStringList($pathsConfig['ignore'], 'paths.ignore', true, true);
    }

    /**
     * @return array{acceptedAbbreviations: list<string>, secretPreviews: list<string>}
     */
    private function parseAllowlistsConfig(mixed $value): array
    {
        $allowlists = $this->requireObject($value, 'Config key "allowlists" must be a JSON object.');

        foreach (array_keys($allowlists) as $key) {
            if (!in_array($key, ['acceptedAbbreviations', 'secretPreviews'], true)) {
                throw new ConfigException(sprintf('Unknown config key "allowlists.%s".', $key));
            }
        }

        $acceptedAbbreviations = array_key_exists('acceptedAbbreviations', $allowlists)
            ? $this->parseStringList($allowlists['acceptedAbbreviations'], 'allowlists.acceptedAbbreviations', false, false)
            : [];
        foreach ($acceptedAbbreviations as $abbreviation) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $abbreviation)) {
                throw new ConfigException(sprintf(
                    'Config value "allowlists.acceptedAbbreviations" contains invalid identifier "%s".',
                    $abbreviation,
                ));
            }
        }

        $secretPreviews = array_key_exists('secretPreviews', $allowlists)
            ? $this->parseStringList($allowlists['secretPreviews'], 'allowlists.secretPreviews', false, false)
            : [];

        return [
            'acceptedAbbreviations' => $acceptedAbbreviations,
            'secretPreviews' => $secretPreviews,
        ];
    }

    private function parseSelectionConfig(mixed $value, RuleRegistry $registry): RuleSelection
    {
        $selection = $this->requireObject($value, 'Config key "selection" must be a JSON object.');

        foreach (array_keys($selection) as $key) {
            if (!in_array($key, ['tiers', 'pillars', 'rules', 'excludePillars', 'excludeRules'], true)) {
                throw new ConfigException(sprintf('Unknown config key "selection.%s".', $key));
            }
        }

        $tiers = array_key_exists('tiers', $selection)
            ? $this->parseStringList($selection['tiers'], 'selection.tiers', false, false)
            : [];
        foreach ($tiers as $tier) {
            if (RuleTier::tryFrom($tier) === null) {
                throw new ConfigException(sprintf('Unknown rule tier "selection.tiers.%s".', $tier));
            }
        }

        $pillars = array_key_exists('pillars', $selection)
            ? $this->parseStringList($selection['pillars'], 'selection.pillars', false, false)
            : [];
        foreach ($pillars as $pillar) {
            if (Pillar::tryFrom($pillar) === null) {
                throw new ConfigException(sprintf('Unknown pillar "selection.pillars.%s".', $pillar));
            }
        }

        $rules = array_key_exists('rules', $selection)
            ? $this->parseRuleIdList($selection['rules'], 'selection.rules', $registry)
            : [];

        $excludePillars = array_key_exists('excludePillars', $selection)
            ? $this->parseStringList($selection['excludePillars'], 'selection.excludePillars', false, false)
            : [];
        foreach ($excludePillars as $pillar) {
            if (Pillar::tryFrom($pillar) === null) {
                throw new ConfigException(sprintf('Unknown pillar "selection.excludePillars.%s".', $pillar));
            }
        }

        $excludeRules = array_key_exists('excludeRules', $selection)
            ? $this->parseRuleIdList($selection['excludeRules'], 'selection.excludeRules', $registry)
            : [];

        return new RuleSelection($tiers, $pillars, $rules, $excludePillars, $excludeRules);
    }

    /**
     * @return list<string>
     */
    private function parseRuleIdList(mixed $value, string $path, RuleRegistry $registry): array
    {
        $ruleIds = $this->parseStringList($value, $path, false, false);

        foreach ($ruleIds as $ruleId) {
            if (!$registry->has($ruleId)) {
                throw new ConfigException(sprintf('Unknown rule id "%s" in "%s".', $ruleId, $path));
            }
        }

        return $ruleIds;
    }

    /**
     * @return list<string>
     */
    private function parseStringList(mixed $value, string $path, bool $pathPatterns, bool $allowGlobs): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ConfigException(sprintf('Config key "%s" must be a list of strings.', $path));
        }

        $strings = [];

        foreach ($value as $index => $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new ConfigException(sprintf('Config key "%s.%d" must be a non-empty string.', $path, $index));
            }

            $normalized = str_replace('\\', '/', trim($item));

            if ($pathPatterns) {
                if (str_starts_with($normalized, '/') || str_contains($normalized, '../') || $normalized === '..') {
                    throw new ConfigException(sprintf('Config key "%s.%d" must be a relative project path pattern.', $path, $index));
                }

                if (!$allowGlobs && str_contains($normalized, '*')) {
                    throw new ConfigException(sprintf('Config key "%s.%d" does not support glob syntax.', $path, $index));
                }
            }

            $strings[] = $normalized;
        }

        return array_values(array_unique($strings));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigException(sprintf('Invalid JSON config: %s', $exception->getMessage()), 0, $exception);
        }

        return $this->requireObject($decoded, 'Config root must be a JSON object.');
    }

    /**
     * @return array<string, mixed>
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

            $result[$key] = $item;
        }

        return $result;
    }
}
