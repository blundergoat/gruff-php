<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\FailThresholds;
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
     * Bundled preset names available to the `extends:` key.
     *
     * @var list<string>
     */
    public const BUNDLED_PRESETS = ['gruff.starter', 'gruff.recommended', 'gruff.strict'];

    /**
     * Maximum depth of an `extends:` inheritance chain before failing.
     */
    private const MAX_EXTENDS_DEPTH = 5;

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
        // Bundled presets live under this package install root, two levels up from src/Config.
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
                // Either filename present is enough; the loader resolves which one to use later.
                return true;
            }
        }

        // Neither the preferred nor the legacy filename exists, so the root has no project config.
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
            // No config file anywhere, so run on the registry defaults unchanged.
            return $config;
        }

        // Layer the discovered config file (and its extends chain) over those defaults.
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

            // An explicit --config path wins outright; we never fall back when the caller named one.
            return $path;
        }

        $projectDefaultPaths = $this->defaultConfigPaths($this->projectRoot);
        foreach ($projectDefaultPaths as $path) {
            if (is_file($path)) {
                // First existing default in the project root (preferred name before legacy) is authoritative.
                return $path;
            }
        }

        if ($this->fallbackConfigRoot === null) {
            // No project config and no package fallback configured, so this run has none.
            return null;
        }

        foreach ($this->defaultConfigPaths($this->fallbackConfigRoot) as $fallbackPath) {
            if (!in_array($fallbackPath, $projectDefaultPaths, true) && is_file($fallbackPath)) {
                // Use the packaged default, but only a path the project root did not already offer.
                return $fallbackPath;
            }
        }

        // Project root and fallback both came up empty.
        return null;
    }

    /**
     * Return preferred then legacy default config paths for a root.
     *
     * @param string $root Directory to look in; a trailing slash is tolerated and stripped.
     * @return list<string>
     */
    private function defaultConfigPaths(string $root): array
    {
        $root = rtrim($root, '/');

        // Preferred name first so callers that take the first match prefer it over the legacy one.
        return [
            $root . '/' . self::DEFAULT_CONFIG_FILE,
            $root . '/' . self::LEGACY_DEFAULT_CONFIG_FILE,
        ];
    }

    /**
     * Apply a parsed config file to the registry-derived defaults.
     *
     * @param AnalysisConfig $config   Starting config (registry defaults) that each chain entry layers onto.
     * @param RuleRegistry  $registry Registry consulted when resolving rule-selection and per-rule settings.
     * @param string        $path     Root config file whose extends chain is resolved and applied in order.
     * @return AnalysisConfig Config after file values have been applied.
     */
    private function applyConfigFile(AnalysisConfig $config, RuleRegistry $registry, string $path): AnalysisConfig
    {
        foreach ($this->resolveExtendsChain($path, [], 1) as $rootConfig) {
            $this->assertKnownRootKeys($rootConfig);
            $this->assertSchemaVersion($rootConfig);

            $config = $this->applyMinimumPhpVersion($config, $rootConfig);
            $config = $this->applyMinimumSeverityConfig($config, $rootConfig);
            $config = $this->applyFailureConditionsConfig($config, $rootConfig);
            $config = $this->applyPathConfig($config, $rootConfig);
            $config = $this->applyAllowlistConfig($config, $rootConfig);
            $config = $this->applySelectionConfig($config, $registry, $rootConfig);
            $config = (new RuleConfigApplier())->apply($config, $registry, $rootConfig);
        }

        // Hand back the config after every chain entry, ancestor through child, has been layered on.
        return $config;
    }

    /**
     * Resolve a config file's `extends:` chain into an ordered list of configs.
     *
     * The list is ancestor-first, current-file-last, so applying each in order
     * layers child settings over inherited ones (a child block overrides the
     * parent's for the same section — see ADR-021). A cycle or a chain deeper than
     * the cap throws.
     *
     * @param string       $path     Config file to resolve.
     * @param list<string> $ancestry Canonical paths already in the chain (cycle guard).
     * @param int          $depth    Current resolution depth (1 at the root file).
     * @throws ConfigException When the chain cycles, exceeds the depth cap, or a target is invalid.
     * @return list<ConfigObject> Configs to apply in order, ancestor first.
     */
    private function resolveExtendsChain(string $path, array $ancestry, int $depth): array
    {
        $label = PathHelper::canonical($path);
        if (in_array($label, $ancestry, true)) {
            throw new ConfigException(sprintf('Config "extends" cycle detected: %s.', implode(' -> ', [...$ancestry, $label])));
        }

        if ($depth > self::MAX_EXTENDS_DEPTH) {
            throw new ConfigException(sprintf('Config "extends" chain exceeds the maximum depth of %d: %s.', self::MAX_EXTENDS_DEPTH, implode(' -> ', [...$ancestry, $label])));
        }

        $rootConfig = $this->readRootConfig($path);
        $extends    = $rootConfig['extends'] ?? null;
        if ($extends === null) {
            // Leaf of the chain: no parent to inherit, so this file is the whole sequence.
            return [$rootConfig];
        }

        if (!is_string($extends) || $extends === '') {
            throw new ConfigException('Config key "extends" must be a non-empty preset name or path.');
        }

        $parentPath  = $this->resolveExtendsReference($extends, $path);
        $parentChain = $this->resolveExtendsChain($parentPath, [...$ancestry, $label], $depth + 1);

        // Parent first, this file last, so the child's sections override the inherited ones on apply.
        return [...$parentChain, $rootConfig];
    }

    /**
     * Resolve an `extends:` reference (bundled preset name or path) to a config file path.
     *
     * @param string $reference   Preset name (`gruff.*`) or a relative/absolute path.
     * @param string $loadingFile Config file declaring the `extends:` (paths resolve from its directory).
     * @throws ConfigException When the preset name is unknown or the path target is missing.
     * @return string Absolute path to the referenced config file.
     */
    private function resolveExtendsReference(string $reference, string $loadingFile): string
    {
        if (str_starts_with($reference, 'gruff.')) {
            $presetPath = self::packageRoot() . '/resources/profiles/' . $reference . '.yaml';
            if (!is_file($presetPath)) {
                throw new ConfigException(sprintf(
                    "Unknown preset '%s'. Available presets: %s.",
                    $reference,
                    implode(', ', self::BUNDLED_PRESETS),
                ));
            }

            // Bundled preset resolved to its shipped profile file under resources/profiles.
            return $presetPath;
        }

        $candidate = PathHelper::isAbsolute($reference)
            ? $reference
            : dirname($loadingFile) . '/' . $reference;

        if (!is_file($candidate)) {
            throw new ConfigException(sprintf('Config "extends" target not found: %s.', $reference));
        }

        // A non-preset reference is a path, resolved relative to the file that declared the extends.
        return $candidate;
    }

    /**
     * Read the YAML configuration root from disk.
     *
     * @param string $path Absolute config file path to read and decode; its extension selects the parser.
     * @return ConfigObject
     */
    private function readRootConfig(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ConfigException(sprintf('Unable to read config file: %s', $path));
        }

        $decoded = $this->decodeConfig($contents, $path);

        // The top level must be a YAML mapping; a list or scalar document is rejected as invalid config.
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
            if (!in_array($rootKey, ['schemaVersion', 'extends', 'rules', 'minimumPhpVersion', 'minimumSeverity', 'failureConditions', 'paths', 'allowlists', 'selection'], true)) {
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
     * @param AnalysisConfig $config     Config to extend; returned unchanged when no minimumSeverity block is present.
     * @param ConfigObject $rootConfig
     *
     * @return AnalysisConfig Config with the per-command minimumSeverity map applied.
     */
    private function applyMinimumSeverityConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('minimumSeverity', $rootConfig)) {
            // Block is optional: absent means leave any inherited severity thresholds untouched.
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

        // Every key and value validated; commit the per-command threshold map onto the config.
        return $config->withMinimumSeverity($resolved);
    }

    /**
     * Apply the configured minimum PHP version when present.
     *
     * @param AnalysisConfig $config     Config to extend; returned unchanged when no minimumPhpVersion is set.
     * @param ConfigObject $rootConfig
     *
     * @return AnalysisConfig Config with the PHP version floor applied.
     */
    private function applyMinimumPhpVersion(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('minimumPhpVersion', $rootConfig)) {
            // Key is optional; without it the modernisation rules keep their built-in version floor.
            return $config;
        }

        $version = $rootConfig['minimumPhpVersion'];
        if (!is_int($version) && !is_float($version)) {
            throw new ConfigException('Config key "minimumPhpVersion" must be numeric.');
        }

        if ($version < 7.4) {
            throw new ConfigException('Config key "minimumPhpVersion" must be at least 7.4.');
        }

        // 7.4 is the lowest version gruff reasons about, so anything at or above it is accepted as the floor.
        return $config->withMinimumPhpVersion((float) $version);
    }

    /**
     * Apply configured path ignores when present.
     *
     * @param AnalysisConfig $config     Config to extend; returned unchanged when no paths block is present.
     * @param ConfigObject $rootConfig
     *
     * @return AnalysisConfig Config with ignored path patterns applied.
     */
    private function applyPathConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('paths', $rootConfig)) {
            // No paths block, so discovery keeps whatever ignore patterns were already in effect.
            return $config;
        }

        // Replace ignore patterns wholesale with the parsed paths.ignore list for this config layer.
        return $config->withIgnoredPathPatterns($this->parsePathsConfig($rootConfig['paths']));
    }

    /**
     * Apply the optional failureConditions count gate when present.
     *
     * @param AnalysisConfig $config     Config to extend; returned unchanged when no failureConditions block is set.
     * @param ConfigObject $rootConfig
     * @throws ConfigException When the failureConditions block is malformed.
     * @return AnalysisConfig Config with the failure-condition thresholds applied.
     */
    private function applyFailureConditionsConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('failureConditions', $rootConfig)) {
            // Block is optional; absent leaves any inherited count gates in place.
            return $config;
        }

        $failureConditions = $rootConfig['failureConditions'];
        if (!is_array($failureConditions)) {
            throw new ConfigException('Config key "failureConditions" must be an object.');
        }

        // Parsing also validates the per-finding count thresholds before they gate the exit code.
        return $config->withFailureConditions(FailThresholds::fromConfig($failureConditions));
    }

    /**
     * Apply configured allowlists when present. Sub-keys that the user omitted
     * leave the registry-seeded defaults intact; a user who configures
     * `allowlists.secretPreviews` only must NOT lose
     * `DEFAULT_ACCEPTED_ABBREVIATIONS` (`id`, `url`, etc.) as a side effect.
     *
     * @param AnalysisConfig $config     Config to extend; each allowlist sub-key the user omitted is left as-is.
     * @param ConfigObject $rootConfig
     *
     * @return AnalysisConfig Config with allowlist values applied.
     */
    private function applyAllowlistConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('allowlists', $rootConfig)) {
            // No allowlists block, so the registry-seeded abbreviation and secret-preview defaults stand.
            return $config;
        }

        $allowlists = $this->parseAllowlistsConfig($rootConfig['allowlists']);

        if ($allowlists['acceptedAbbreviations'] !== null) {
            $config = $config->withAcceptedAbbreviations($allowlists['acceptedAbbreviations']);
        }

        if ($allowlists['secretPreviews'] !== null) {
            $config = $config->withAllowedSecretPreviews($allowlists['secretPreviews']);
        }

        // Only the sub-keys the user actually set were overridden; the rest keep their defaults.
        return $config;
    }

    /**
     * Apply configured rule selection when present.
     *
     * @param AnalysisConfig $config     Config to extend; returned unchanged when no selection block is present.
     * @param RuleRegistry  $registry   Registry of known rule ids the parser validates include/exclude entries against.
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
            // No selection block, so the previously active include/exclude set carries forward.
            return $config;
        }

        // Parser checks each id against the registry, so an unknown rule fails rather than silently no-opping.
        return $config->withRuleSelection((new SelectionConfigParser())->parse($this->configValue($rootConfig['selection']), $registry));
    }

    /**
     * Parse paths.ignore into the ignore patterns used during discovery.
     *
     * @param mixed $decodedValue Decoded value of the `paths` key; must be a mapping or the parse throws.
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
            // A paths block with no ignore key contributes no patterns rather than erroring.
            return [];
        }

        // hasPathPatterns + allowsGlobs: ignore entries are relative path globs, so enable path-escape and
        // glob validation; a blank or absolute/traversing pattern throws rather than silently widening discovery.
        return (new StringListConfigParser())->parse($this->configValue($pathsConfig['ignore']), 'paths.ignore', true, true);
    }

    /**
     * Parse naming and secret-preview allowlists from configuration. Sub-keys
     * that the user omitted return `null` so the caller can keep the
     * registry-seeded defaults intact rather than overriding them with an
     * empty list.
     *
     * @param mixed $decodedValue Decoded value of the `allowlists` key; must be a mapping or the parse throws.
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

        // Null for an omitted sub-key signals "keep defaults"; an empty list would instead wipe them.
        return [
            'acceptedAbbreviations' => $acceptedAbbreviations,
            'secretPreviews' => $secretPreviews,
        ];
    }

    /**
     * Decode supported YAML config text into a root object.
     *
     * @param string $contents Raw file contents to parse as YAML.
     * @param string $path     Source path; only its extension is read here, to gate the .yaml/.yml requirement.
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

        // A valid YAML document can still be a list or scalar; require a mapping at the root.
        return $this->requireObject($decoded, 'Config root must be an object.');
    }

    /**
     * Validate that a decoded config value is an object-like array.
     *
     * @param mixed  $decodedValue Decoded value to check; an empty array passes, a list or scalar does not.
     * @param string $message      Error text thrown when the value is not an object or has a non-string key.
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

        // Keys are confirmed strings and every value normalised, so this is a well-formed config object.
        return $normalizedConfig;
    }

    /**
     * Normalise one decoded config value into the supported value set.
     *
     * @param mixed $decodedValue One decoded YAML node (array or scalar) to constrain to the supported shape.
     * @return ConfigValue
     */
    private function configValue(mixed $decodedValue): array|bool|float|int|object|string|null
    {
        if (is_array($decodedValue)) {
            // Arrays recurse so nesting limits and per-element scalar checks apply at every depth.
            return $this->configArray($decodedValue);
        }

        // A leaf scalar is validated directly against the allowed YAML/JSON scalar types.
        return $this->configScalar($decodedValue);
    }

    /**
     * Validate scalar config values after YAML decoding.
     *
     * @param mixed $decodedValue Decoded leaf value expected to be a YAML/JSON scalar (bool, number, string, object, null).
     * @return ConfigScalar
     */
    private function configScalar(mixed $decodedValue): bool|float|int|object|string|null
    {
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_object($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            // Already one of the permitted scalar types, so pass it through untouched.
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

        // Top-level array normalised; nested arrays were handed down to the depth-2 pass.
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

        // Second-level array normalised; deeper arrays were handed down to the depth-3 pass.
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

        // Third-level array normalised; the depth-4 pass holds the line on further nesting.
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

        // Deepest supported level: a nested array at depth 4 is rejected, so every retained value is a scalar.
        return $normalizedConfigValues;
    }
}
