<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Rule\RuleRegistry;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class ConfigLoader
{
    public const DEFAULT_CONFIG_FILE = '.gruff.yaml';

    public function __construct(
        private string $projectRoot,
        private ?string $fallbackConfigRoot = null,
    )
    {
    }

    public static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function load(?string $configPath, RuleRegistry $registry): AnalysisConfig
    {
        $config = AnalysisConfig::fromRegistry($registry);
        $resolvedPath = $this->resolveConfigPath($configPath);

        if ($resolvedPath === null) {
            return $config;
        }

        return $this->applyConfigFile($config, $registry, $resolvedPath);
    }

    public function resolveConfigPath(?string $configPath): ?string
    {
        if ($configPath !== null && $configPath !== '') {
            $path = $configPath[0] === '/' ? $configPath : $this->projectRoot . '/' . $configPath;

            if (!is_file($path)) {
                throw new ConfigException(sprintf('Config file not found: %s', $configPath));
            }

            return $path;
        }

        $defaultPath = $this->defaultConfigPath($this->projectRoot);
        if (is_file($defaultPath)) {
            return $defaultPath;
        }

        if ($this->fallbackConfigRoot === null) {
            return null;
        }

        $fallbackPath = $this->defaultConfigPath($this->fallbackConfigRoot);

        return $fallbackPath !== $defaultPath && is_file($fallbackPath) ? $fallbackPath : null;
    }

    private function defaultConfigPath(string $root): string
    {
        return rtrim($root, '/') . '/' . self::DEFAULT_CONFIG_FILE;
    }

    private function applyConfigFile(AnalysisConfig $config, RuleRegistry $registry, string $path): AnalysisConfig
    {
        $rootConfig = $this->readRootConfig($path);
        $this->assertKnownRootKeys($rootConfig);

        $config = $this->applyMinimumPhpVersion($config, $rootConfig);
        $config = $this->applyPathConfig($config, $rootConfig);
        $config = $this->applyAllowlistConfig($config, $rootConfig);
        $config = $this->applySelectionConfig($config, $registry, $rootConfig);

        return (new RuleConfigApplier())->apply($config, $registry, $rootConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function readRootConfig(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ConfigException(sprintf('Unable to read config file: %s', $path));
        }

        $decoded = $this->decodeConfig($contents, $path);

        return $this->requireObject($decoded, 'Config root must be an object.');
    }

    /**
     * @param array<string, mixed> $rootConfig
     */
    private function assertKnownRootKeys(array $rootConfig): void
    {
        foreach (array_keys($rootConfig) as $rootKey) {
            if (!in_array($rootKey, ['rules', 'minimumPhpVersion', 'paths', 'allowlists', 'selection'], true)) {
                throw new ConfigException(sprintf('Unknown config key "%s".', $rootKey));
            }
        }
    }

    /**
     * @param array<string, mixed> $rootConfig
     */
    private function applyMinimumPhpVersion(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('minimumPhpVersion', $rootConfig)) {
            return $config;
        }

        $version = $rootConfig['minimumPhpVersion'];
        if (!is_int($version) && !is_float($version)) {
            throw new ConfigException('Config key "minimumPhpVersion" must be numeric.');
        }

        if ($version < 7.4) {
            throw new ConfigException('Config key "minimumPhpVersion" must be at least 7.4.');
        }

        return $config->withMinimumPhpVersion((float) $version);
    }

    /**
     * @param array<string, mixed> $rootConfig
     */
    private function applyPathConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('paths', $rootConfig)) {
            return $config;
        }

        return $config->withIgnoredPathPatterns($this->parsePathsConfig($rootConfig['paths']));
    }

    /**
     * @param array<string, mixed> $rootConfig
     */
    private function applyAllowlistConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('allowlists', $rootConfig)) {
            return $config;
        }

        $allowlists = $this->parseAllowlistsConfig($rootConfig['allowlists']);

        return $config
            ->withAcceptedAbbreviations($allowlists['acceptedAbbreviations'])
            ->withAllowedSecretPreviews($allowlists['secretPreviews']);
    }

    /**
     * @param array<string, mixed> $rootConfig
     */
    private function applySelectionConfig(
        AnalysisConfig $config,
        RuleRegistry $registry,
        array $rootConfig,
    ): AnalysisConfig {
        if (!array_key_exists('selection', $rootConfig)) {
            return $config;
        }

        return $config->withRuleSelection((new SelectionConfigParser())->parse($this->configValue($rootConfig['selection']), $registry));
    }

    /**
     * @return list<string>
     */
    private function parsePathsConfig(mixed $value): array
    {
        $pathsConfig = $this->requireObject($value, 'Config key "paths" must be an object.');

        foreach (array_keys($pathsConfig) as $key) {
            if ($key !== 'ignore') {
                throw new ConfigException(sprintf('Unknown config key "paths.%s".', $key));
            }
        }

        if (!array_key_exists('ignore', $pathsConfig)) {
            return [];
        }

        return (new StringListConfigParser())->parse($this->configValue($pathsConfig['ignore']), 'paths.ignore', true, true);
    }

    /**
     * @return array{acceptedAbbreviations: list<string>, secretPreviews: list<string>}
     */
    private function parseAllowlistsConfig(mixed $value): array
    {
        $allowlists = $this->requireObject($value, 'Config key "allowlists" must be an object.');

        foreach (array_keys($allowlists) as $key) {
            if (!in_array($key, ['acceptedAbbreviations', 'secretPreviews'], true)) {
                throw new ConfigException(sprintf('Unknown config key "allowlists.%s".', $key));
            }
        }

        $acceptedAbbreviations = array_key_exists('acceptedAbbreviations', $allowlists)
            ? (new StringListConfigParser())->parse($this->configValue($allowlists['acceptedAbbreviations']), 'allowlists.acceptedAbbreviations', false, false)
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
            ? (new StringListConfigParser())->parse($this->configValue($allowlists['secretPreviews']), 'allowlists.secretPreviews', false, false)
            : [];

        return [
            'acceptedAbbreviations' => $acceptedAbbreviations,
            'secretPreviews' => $secretPreviews,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(string $contents, string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($extension, ['yaml', 'yml'], true)) {
            throw new ConfigException(sprintf(
                'Config file must use a .yaml or .yml extension: %s',
                $path,
            ));
        }

        try {
            $decoded = Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw new ConfigException(sprintf('Invalid YAML config: %s', $exception->getMessage()), 0, $exception);
        }

        return $this->requireObject($decoded, 'Config root must be an object.');
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
