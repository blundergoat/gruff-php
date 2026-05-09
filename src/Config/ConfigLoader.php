<?php

declare(strict_types=1);

namespace GruffPhp\Config;

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
            if ($rootKey !== 'rules') {
                throw new ConfigException(sprintf('Unknown config key "%s".', $rootKey));
            }
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
            if ($key !== 'enabled' && $key !== 'thresholds') {
                throw new ConfigException(sprintf('Unknown config key "rules.%s.%s".', $ruleId, $key));
            }
        }

        $settings = $config->ruleSettings($ruleId);
        $enabled = $settings->enabled;
        $thresholds = $settings->thresholds;

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

        return $config->withRuleSettings($ruleId, new RuleSettings($enabled, $thresholds));
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
