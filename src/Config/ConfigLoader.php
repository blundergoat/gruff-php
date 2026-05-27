<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves and applies gruff YAML configuration files.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>>
 * @phpstan-type ConfigObject array<string, ConfigValue>
 */
final readonly class ConfigLoader
{
    /**
     * Preferred default config file name discovered from project roots.
     */
    public const DEFAULT_CONFIG_FILE = '.gruff-php.yaml';

    /**
     * Legacy default config file name accepted during the config rename.
     */
    public const LEGACY_DEFAULT_CONFIG_FILE = '.gruff.yaml';

    /**
     * Canonical schema version accepted as the top-level `schemaVersion:` value.
     */
    public const SCHEMA_VERSION = 'gruff-php.config.v0.1';

    /**
     * Gating commands accepted as `minimumSeverity:` keys. Commands not in this
     * list (summary, init, list-rules) do not gate exit code; rejecting them at
     * load time prevents the silent-no-op footgun where a user sets
     * `minimumSeverity.summary: error` expecting CI to fail but never sees a
     * non-zero exit. See ADR-015.
     *
     * @var list<string>
     */
    public const GATING_COMMANDS = ['analyse', 'report', 'dashboard'];

    /**
     * Create a loader for a project root with an optional package config fallback.
     *
     * @param string      $projectRoot        Project root used for primary config discovery.
     * @param string|null $fallbackConfigRoot Root used for fallback config discovery.
     */
    public function __construct(
        private string $projectRoot,
        private ?string $fallbackConfigRoot = null,
    ) {
    }

    /**
     * Return the installed package root for fallback config discovery.
     *
     * @return string Absolute package root path.
     */
    public static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Report whether the project root already holds a discoverable config file.
     *
     * Used by callers that need a fast "is there any project config?" check
     * without loading the file. Covers both the preferred and legacy filenames
     * so callers do not drift from {@see resolveConfigPath()}.
     *
     * @param string $projectRoot Project root used for config discovery.
     * @return bool True when a preferred or legacy config file exists at the root.
     */
    public static function hasProjectConfig(string $projectRoot): bool
    {
        $root = rtrim($projectRoot, '/');

        foreach ([self::DEFAULT_CONFIG_FILE, self::LEGACY_DEFAULT_CONFIG_FILE] as $candidate) {
            if (is_file($root . '/' . $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load analysis config from an explicit, project, or fallback YAML file.
     *
     * @param string|null  $configPath Explicit config path supplied by the CLI.
     * @param RuleRegistry $registry   Rule registry used to seed default config.
     * @return AnalysisConfig Loaded config merged onto registry defaults.
     */
    public function load(?string $configPath, RuleRegistry $registry): AnalysisConfig
    {
        $config       = AnalysisConfig::fromRegistry($registry);
        $resolvedPath = $this->resolveConfigPath($configPath);

        if ($resolvedPath === null) {
            return $config;
        }

        return $this->applyConfigFile($config, $registry, $resolvedPath);
    }

    /**
     * Resolve the config file path that should be used for this run.
     *
     * @param string|null $configPath Explicit config path supplied by the CLI.
     * @throws ConfigException When an explicit config path does not exist.
     * @return string|null Absolute config path, or null when none is available.
     */
    public function resolveConfigPath(?string $configPath): ?string
    {
        if ($configPath !== null && $configPath !== '') {
            $path = PathHelper::resolveAgainst($this->projectRoot, $configPath);

            if (!is_file($path)) {
                throw new ConfigException(sprintf('Config file not found: %s', $configPath));
            }

            return $path;
        }

        $projectDefaultPaths = $this->defaultConfigPaths($this->projectRoot);
        foreach ($projectDefaultPaths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        if ($this->fallbackConfigRoot === null) {
            return null;
        }

        foreach ($this->defaultConfigPaths($this->fallbackConfigRoot) as $fallbackPath) {
            if (!in_array($fallbackPath, $projectDefaultPaths, true) && is_file($fallbackPath)) {
                return $fallbackPath;
            }
        }

        return null;
    }

    /**
     * Return preferred then legacy default config paths for a root.
     *
     * @return list<string>
     */
    private function defaultConfigPaths(string $root): array
    {
        $root = rtrim($root, '/');

        return [
            $root . '/' . self::DEFAULT_CONFIG_FILE,
            $root . '/' . self::LEGACY_DEFAULT_CONFIG_FILE,
        ];
    }

    /**
     * Apply a parsed config file to the registry-derived defaults.
     *
     * @return AnalysisConfig Config after file values have been applied.
     */
    private function applyConfigFile(AnalysisConfig $config, RuleRegistry $registry, string $path): AnalysisConfig
    {
        $rootConfig = $this->readRootConfig($path);
        $this->assertKnownRootKeys($rootConfig);
        $this->assertSchemaVersion($rootConfig);

        $config = $this->applyMinimumPhpVersion($config, $rootConfig);
        $config = $this->applyMinimumSeverityConfig($config, $rootConfig);
        $config = $this->applyPathConfig($config, $rootConfig);
        $config = $this->applyAllowlistConfig($config, $rootConfig);
        $config = $this->applySelectionConfig($config, $registry, $rootConfig);

        return (new RuleConfigApplier())->apply($config, $registry, $rootConfig);
    }

    /**
     * Read the YAML configuration root from disk.
     *
     * @return ConfigObject
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
     * Reject unsupported top-level config keys.
     *
     * @param ConfigObject $rootConfig
     *
     * @return void
     */
    private function assertKnownRootKeys(array $rootConfig): void
    {
        foreach (array_keys($rootConfig) as $rootKey) {
            if (!in_array($rootKey, ['schemaVersion', 'rules', 'minimumPhpVersion', 'minimumSeverity', 'paths', 'allowlists', 'selection'], true)) {
                throw new ConfigException(sprintf('Unknown config key "%s".', $rootKey));
            }
        }
    }

    /**
     * Hard-error when the top-level `schemaVersion:` field is missing, the
     * wrong type, or names an unexpected version. ADR-015 introduces this
     * field in 0.2.0 as a required key with a single accepted value; the
     * hard-error path is the intentional UX per the pre-public-adoption
     * schema window.
     *
     * @param ConfigObject $rootConfig
     *
     * @return void
     */
    private function assertSchemaVersion(array $rootConfig): void
    {
        if (!array_key_exists('schemaVersion', $rootConfig)) {
            throw new ConfigException(sprintf(
                'Config key "schemaVersion" is required. Add \'schemaVersion: %s\' to the top of .gruff-php.yaml, or regenerate with \'gruff-php init --force\'.',
                self::SCHEMA_VERSION,
            ));
        }

        $rawVersion = $rootConfig['schemaVersion'];
        if (!is_string($rawVersion)) {
            throw new ConfigException('Config key "schemaVersion" must be a string.');
        }

        if ($rawVersion !== self::SCHEMA_VERSION) {
            throw new ConfigException(sprintf(
                'Config key "schemaVersion" must be "%s". Got "%s". Update .gruff-php.yaml or regenerate with \'gruff-php init --force\'.',
                self::SCHEMA_VERSION,
                $rawVersion,
            ));
        }
    }

    /**
     * Validate and apply the optional `minimumSeverity:` block. Rejects every
     * non-gating command key (including summary, init, list-rules) and every
     * non-canonical threshold value with a clear error. See ADR-015 for the
     * rejection rationale.
     *
     * @param ConfigObject $rootConfig
     *
     * @return AnalysisConfig Config with the per-command minimumSeverity map applied.
     */
    private function applyMinimumSeverityConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('minimumSeverity', $rootConfig)) {
            return $config;
        }

        $entries = $rootConfig['minimumSeverity'];
        if (!is_array($entries) || ($entries !== [] && array_is_list($entries))) {
            throw new ConfigException('Config key "minimumSeverity" must be a map of command name to threshold.');
        }

        $resolved = [];
        foreach ($entries as $command => $rawValue) {
            if (!is_string($command)) {
                throw new ConfigException('Config key "minimumSeverity" keys must be strings.');
            }

            if (!in_array($command, self::GATING_COMMANDS, true)) {
                throw new ConfigException(sprintf(
                    'Config key "minimumSeverity.%s" is not a valid gating command. Valid keys are: %s.',
                    $command,
                    implode(', ', self::GATING_COMMANDS),
                ));
            }

            if (!is_string($rawValue)) {
                throw new ConfigException(sprintf(
                    'Config key "minimumSeverity.%s" must be a string. Got %s.',
                    $command,
                    get_debug_type($rawValue),
                ));
            }

            $threshold = FailThreshold::fromInput($rawValue);
            if ($threshold === null) {
                throw new ConfigException(sprintf(
                    'Config key "minimumSeverity.%s" value "%s" is not a valid threshold. Accepted: %s.',
                    $command,
                    $rawValue,
                    implode(', ', array_map(static fn (FailThreshold $case): string => $case->value, FailThreshold::cases())),
                ));
            }

            $resolved[$command] = $threshold;
        }

        return $config->withMinimumSeverity($resolved);
    }

    /**
     * Apply the configured minimum PHP version when present.
     *
     * @param ConfigObject $rootConfig
     *
     * @return AnalysisConfig Config with the PHP version floor applied.
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
     * Apply configured path ignores when present.
     *
     * @param ConfigObject $rootConfig
     *
     * @return AnalysisConfig Config with ignored path patterns applied.
     */
    private function applyPathConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('paths', $rootConfig)) {
            return $config;
        }

        return $config->withIgnoredPathPatterns($this->parsePathsConfig($rootConfig['paths']));
    }

    /**
     * Apply configured allowlists when present. Sub-keys that the user omitted
     * leave the registry-seeded defaults intact; a user who configures
     * `allowlists.secretPreviews` only must NOT lose
     * `DEFAULT_ACCEPTED_ABBREVIATIONS` (`id`, `url`, etc.) as a side effect.
     *
     * @param ConfigObject $rootConfig
     *
     * @return AnalysisConfig Config with allowlist values applied.
     */
    private function applyAllowlistConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('allowlists', $rootConfig)) {
            return $config;
        }

        $allowlists = $this->parseAllowlistsConfig($rootConfig['allowlists']);

        if ($allowlists['acceptedAbbreviations'] !== null) {
            $config = $config->withAcceptedAbbreviations($allowlists['acceptedAbbreviations']);
        }

        if ($allowlists['secretPreviews'] !== null) {
            $config = $config->withAllowedSecretPreviews($allowlists['secretPreviews']);
        }

        return $config;
    }

    /**
     * Apply configured rule selection when present.
     *
     * @param ConfigObject $rootConfig
     *
     * @return AnalysisConfig Config with include/exclude selection applied.
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
     * Parse paths.ignore into the ignore patterns used during discovery.
     *
     * @return list<string>
     */
    private function parsePathsConfig(mixed $decodedValue): array
    {
        $pathsConfig = $this->requireObject($decodedValue, 'Config key "paths" must be an object.');

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
     * Parse naming and secret-preview allowlists from configuration. Sub-keys
     * that the user omitted return `null` so the caller can keep the
     * registry-seeded defaults intact rather than overriding them with an
     * empty list.
     *
     * @return array{acceptedAbbreviations: list<string>|null, secretPreviews: list<string>|null}
     */
    private function parseAllowlistsConfig(mixed $decodedValue): array
    {
        $allowlists = $this->requireObject($decodedValue, 'Config key "allowlists" must be an object.');

        foreach (array_keys($allowlists) as $key) {
            if (!in_array($key, ['acceptedAbbreviations', 'secretPreviews'], true)) {
                throw new ConfigException(sprintf('Unknown config key "allowlists.%s".', $key));
            }
        }

        $acceptedAbbreviations = null;
        if (array_key_exists('acceptedAbbreviations', $allowlists)) {
            $acceptedAbbreviations = (new StringListConfigParser())->parse($this->configValue($allowlists['acceptedAbbreviations']), 'allowlists.acceptedAbbreviations', false, false);
            foreach ($acceptedAbbreviations as $abbreviation) {
                // Allow only PHP identifier-shaped abbreviations in the naming allowlist.
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $abbreviation)) {
                    throw new ConfigException(sprintf(
                        'Config value "allowlists.acceptedAbbreviations" contains invalid identifier "%s".',
                        $abbreviation,
                    ));
                }
            }
        }

        $secretPreviews = array_key_exists('secretPreviews', $allowlists)
            ? (new StringListConfigParser())->parse($this->configValue($allowlists['secretPreviews']), 'allowlists.secretPreviews', false, false)
            : null;

        return [
            'acceptedAbbreviations' => $acceptedAbbreviations,
            'secretPreviews' => $secretPreviews,
        ];
    }

    /**
     * Decode supported YAML config text into a root object.
     *
     * @return ConfigObject
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
     * Validate that a decoded config value is an object-like array.
     *
     * @return ConfigObject
     */
    private function requireObject(mixed $decodedValue, string $message): array
    {
        if (!is_array($decodedValue) || ($decodedValue !== [] && array_is_list($decodedValue))) {
            throw new ConfigException($message);
        }

        $normalizedConfig = [];

        foreach ($decodedValue as $key => $decodedItem) {
            if (!is_string($key)) {
                throw new ConfigException($message);
            }

            $normalizedConfig[$key] = $this->configValue($decodedItem);
        }

        return $normalizedConfig;
    }

    /**
     * Normalise one decoded config value into the supported value set.
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
     * Validate scalar config values after YAML decoding.
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
     * Keep decoded configuration values within the supported nested scalar shape.
     *
     * @param array<array-key, mixed> $decodedConfigValues
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>>
     */
    private function configArray(array $decodedConfigValues): array
    {
        $normalizedConfigValues = [];

        foreach ($decodedConfigValues as $key => $decodedItem) {
            $normalizedConfigValues[$key] = is_array($decodedItem) ? $this->configArrayDepth2($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedConfigValues;
    }

    /**
     * Keep second-level configuration values within the supported scalar shape.
     *
     * @param array<array-key, mixed> $decodedConfigValues
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>
     */
    private function configArrayDepth2(array $decodedConfigValues): array
    {
        $normalizedConfigValues = [];

        foreach ($decodedConfigValues as $key => $decodedItem) {
            $normalizedConfigValues[$key] = is_array($decodedItem) ? $this->configArrayDepth3($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedConfigValues;
    }

    /**
     * Keep third-level configuration values within the supported scalar shape.
     *
     * @param array<array-key, mixed> $decodedConfigValues
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>>
     */
    private function configArrayDepth3(array $decodedConfigValues): array
    {
        $normalizedConfigValues = [];

        foreach ($decodedConfigValues as $key => $decodedItem) {
            $normalizedConfigValues[$key] = is_array($decodedItem) ? $this->configArrayDepth4($decodedItem) : $this->configScalar($decodedItem);
        }

        return $normalizedConfigValues;
    }

    /**
     * Keep fourth-level configuration values as scalar config values.
     *
     * @param array<array-key, mixed> $decodedConfigValues
     * @return array<array-key, ConfigScalar>
     */
    private function configArrayDepth4(array $decodedConfigValues): array
    {
        $normalizedConfigValues = [];

        foreach ($decodedConfigValues as $key => $decodedItem) {
            if (is_array($decodedItem)) {
                throw new ConfigException('Config value nesting is deeper than supported.');
            }

            $normalizedConfigValues[$key] = $this->configScalar($decodedItem);
        }

        return $normalizedConfigValues;
    }
}
