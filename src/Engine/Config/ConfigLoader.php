<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Output\Reporter\FailThreshold;
use GruffPhp\Output\Reporter\FailThresholds;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Finds the right gruff YAML config for a run and folds its settings onto the rule defaults.
 *
 * It selects the explicit, project, or packaged file and resolves `extends` ancestor-first.
 * Validation stops malformed or unknown settings before analysis, while valid child values override inherited defaults.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
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
     * Value-independent migration diagnostic for the retired secret-preview suppression list.
     */
    private const LEGACY_SECRET_PREVIEWS_ERROR = 'Config key "allowlists.secretPreviews" only accepts an empty list; remove all configured entries because secret previews no longer suppress findings.';

    /**
     * Builds a config loader for one project root, with an optional packaged-config fallback.
     *
     * @param string      $projectRoot        - Project root used for primary config discovery.
     * @param string|null $fallbackConfigRoot - Packaged fallback root; null limits discovery to the user's project.
     */
    public function __construct(
        private string  $projectRoot,
        private ?string $fallbackConfigRoot = null,
    ) {
    }

    /**
     * Returns the installed package root used to locate the bundled presets for `extends:`.
     *
     * @return string - Absolute package root path.
     */
    public static function packageRoot(): string
    {
        // Bundled presets live under this package install root, three levels up from src/Engine/Config.
        return dirname(__DIR__, 3);
    }

    /**
     * Reports whether the user's project already has a discoverable config without parsing it.
     * Callers use this before analysis when they only need to choose between project settings and defaults; both supported filenames match loading.
     *
     * @param string $projectRoot - Project root used for config discovery.
     *
     * @return bool - True when a preferred or legacy config file exists at the root.
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
     * Loads the analysis config for a run from an explicit, project, or fallback YAML file.
     *
     * @param string|null  $configPath - Explicit CLI config path; null or empty lets the loader discover project or packaged defaults.
     * @param RuleRegistry $registry   - Rule registry used to seed default config.
     *
     * @return AnalysisConfig - Loaded config merged onto registry defaults.
     */
    public function load(?string $configPath, RuleRegistry $registry): AnalysisConfig
    {
        $config       = AnalysisConfig::fromRegistry($registry);
        $resolvedPath = $this->resolveConfigPath($configPath);

        // With no config file found, the run keeps the registry defaults untouched.
        if ($resolvedPath === null) {
            return $config;
        }

        return $this->applyConfigFile($config, $registry, $resolvedPath);
    }

    /**
     * Picks the config file this run should use: an explicit path, a project default, or the fallback.
     *
     * @param string|null $configPath - Explicit CLI config path; null or empty asks the loader to discover a default for the user.
     *
     * @return string|null - Absolute config path, or null when no config file is available anywhere.
     * @throws ConfigException When an explicit config path does not exist.
     */
    public function resolveConfigPath(?string $configPath): ?string
    {
        // A path the user passed with --config is honoured first.
        if ($configPath !== null && $configPath !== '') {
            $path = PathHelper::resolveAgainst($this->projectRoot, $configPath);

            if (!is_file($path)) {
                throw new ConfigException(sprintf('Config file not found: %s', $configPath));
            }

            // An explicit --config path wins outright; we never fall back when the caller named one.
            return $path;
        }

        $projectDefaultPaths = $this->defaultConfigPaths($this->projectRoot);
        // Otherwise look for a default config in the project root.
        foreach ($projectDefaultPaths as $path) {
            if (is_file($path)) {
                // First existing default in the project root (preferred name before legacy) is authoritative.
                return $path;
            }
        }

        // No project config and no packaged fallback configured means this run has none.
        if ($this->fallbackConfigRoot === null) {
            return null;
        }

        // Otherwise fall back to a packaged default config.
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
     * Returns the preferred-name then legacy-name default config paths for a root (existence unchecked).
     *
     * @param string $root - Directory to look in; a trailing slash is tolerated and stripped.
     *
     * @return list<string> - preferred-name path first then legacy-name path, both candidates whether or not they exist on disk.
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
     * Applies a config file - and everything it extends - onto the registry-derived defaults, in order.
     *
     * @param AnalysisConfig $config   - Starting config (registry defaults) that each chain entry layers onto.
     * @param RuleRegistry   $registry - Registry consulted when resolving rule-selection and per-rule settings.
     * @param string         $path     - Root config file whose extends chain is resolved and applied in order.
     *
     * @return AnalysisConfig - Config after file values have been applied.
     */
    private function applyConfigFile(AnalysisConfig $config, RuleRegistry $registry, string $path): AnalysisConfig
    {
        // Apply each config in the extends chain, ancestor first, so a child's settings win.
        foreach ($this->resolveExtendsChain($path, [], 1) as $rootConfig) {
            $this->assertKnownRootKeys($rootConfig);
            $this->assertSchemaVersion($rootConfig);

            $config = $this->applyMinimumPhpVersion($config, $rootConfig);
            $config = $this->applyDeepScanBudgetConfig($config, $rootConfig);
            $config = $this->applyMinimumSeverityConfig($config, $rootConfig);
            $config = $this->applyFailureConditionsConfig($config, $rootConfig);
            $config = $this->applyPathConfig($config, $rootConfig);
            $config = $this->applyAllowlistConfig($config, $rootConfig);
            $config = $this->applySelectionConfig($config, $registry, $rootConfig);
            $config = $this->applySensitiveExclusionsConfig($config, $registry, $rootConfig);
            $config = (new RuleConfigApplier())->apply($config, $registry, $rootConfig);
        }

        return $config;
    }

    /**
     * Resolves the user's `extends:` chain into the ancestor-first order applied before analysis.
     * Parent settings load first so the current project can override them; a cycle or over-deep chain stops with an actionable config error.
     *
     * @param string       $path     - Config file to resolve.
     * @param list<string> $ancestry - Canonical paths already in the chain (cycle guard).
     * @param int          $depth    - Current resolution depth (1 at the root file).
     *
     * @return list<ConfigObject> - Configs to apply in order, ancestor first.
     * @throws ConfigException When the chain cycles, exceeds the depth cap, or a target is invalid.
     */
    private function resolveExtendsChain(string $path, array $ancestry, int $depth): array
    {
        $label = PathHelper::canonical($path);
        // A file already in the chain means an `extends` cycle, which is fatal.
        if (in_array($label, $ancestry, true)) {
            throw new ConfigException(sprintf('Config "extends" cycle detected: %s.', implode(' -> ', [...$ancestry, $label])));
        }

        // Too deep an inheritance chain is capped to stop a runaway config.
        if ($depth > self::MAX_EXTENDS_DEPTH) {
            throw new ConfigException(sprintf('Config "extends" chain exceeds the maximum depth of %d: %s.', self::MAX_EXTENDS_DEPTH, implode(' -> ', [...$ancestry, $label])));
        }

        $rootConfig = $this->readRootConfig($path);
        $extends    = $rootConfig['extends'] ?? null;
        // With no `extends`, this file is the leaf and the whole sequence.
        if ($extends === null) {
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
     * Resolves an `extends:` reference - a bundled `gruff.*` preset or a path - to a config file path.
     *
     * @param string $reference   - Preset name (`gruff.*`) or a relative/absolute path.
     * @param string $loadingFile - Config file declaring the `extends:` (paths resolve from its directory).
     *
     * @return string - Absolute path to the referenced config file.
     * @throws ConfigException When the preset name is unknown or the path target is missing.
     */
    private function resolveExtendsReference(string $reference, string $loadingFile): string
    {
        // A `gruff.` prefix names one of the bundled presets shipped with the package.
        if (str_starts_with($reference, 'gruff.')) {
            $presetPath = self::packageRoot() . '/resources/profiles/' . $reference . '.yaml';
            if (!is_file($presetPath)) {
                throw new ConfigException(sprintf(
                                              "Unknown preset '%s'. Available presets: %s.",
                                              $reference,
                                              implode(', ', self::BUNDLED_PRESETS),
                                          ));
            }

            return $presetPath;
        }

        // Otherwise treat it as a path, relative to the file that declared the `extends`.
        $candidate = PathHelper::isAbsolute($reference)
            ? $reference
            : dirname($loadingFile) . '/' . $reference;

        if (!is_file($candidate)) {
            throw new ConfigException(sprintf('Config "extends" target not found: %s.', $reference));
        }

        return $candidate;
    }

    /**
     * Reads and decodes one YAML config file into its top-level mapping.
     *
     * @param string $path - Absolute config file path to read and decode; its extension selects the parser.
     *
     * @return ConfigObject - the decoded top-level mapping; a list or scalar document throws rather than returning.
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
     * Rejects any top-level config key outside the supported schema, so a typo fails loudly.
     *
     * @param ConfigObject $rootConfig - Decoded root config map whose keys are checked against the supported schema.
     *
     * @return void
     */
    private function assertKnownRootKeys(array $rootConfig): void
    {
        foreach (array_keys($rootConfig) as $rootKey) {
            // A key outside the schema is rejected rather than silently ignored.
            if (!in_array($rootKey, ['schemaVersion', 'extends', 'rules', 'minimumPhpVersion', 'deepScanBudget', 'minimumSeverity', 'failureConditions', 'paths', 'allowlists', 'selection', SensitiveExclusionConfigParser::CONFIG_KEY], true)) {
                throw new ConfigException(sprintf('Unknown config key "%s".', $rootKey));
            }
        }
    }

    /**
     * Requires the user's top-level `schemaVersion:` before any settings reach analysis.
     * A missing, mistyped, or unsupported value stops the run with the migration action defined by ADR-015.
     *
     * @param ConfigObject $rootConfig - Decoded root config map that must carry the required schema version.
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

        // A version other than the one this build accepts is rejected during the schema window.
        if ($rawVersion !== self::SCHEMA_VERSION) {
            throw new ConfigException(sprintf(
                                          'Config key "schemaVersion" must be "%s". Got "%s". Update .gruff-php.yaml or regenerate with \'gruff-php init --force\'.',
                                          self::SCHEMA_VERSION,
                                          $rawVersion,
                                      ));
        }
    }

    /**
     * Applies the user's optional `minimumSeverity:` values to the commands that can fail a run.
     * Unsupported commands or thresholds stop config loading instead of leaving the user with a silent CI no-op; see ADR-015.
     *
     * @param AnalysisConfig $config     - Config to extend; returned unchanged when no minimumSeverity block is present.
     * @param ConfigObject   $rootConfig - Decoded root config map; absence of the block preserves inherited thresholds.
     *
     * @return AnalysisConfig - Config with the per-command minimumSeverity map applied.
     */
    private function applyMinimumSeverityConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        // No minimumSeverity block leaves inherited thresholds in place.
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

            // Only a gating command may set a threshold; others would silently never fail CI.
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
                                              implode(', ', array_map(static fn(FailThreshold $case): string => $case->value, FailThreshold::cases())),
                                          ));
            }

            $resolved[$command] = $threshold;
        }

        return $config->withMinimumSeverity($resolved);
    }

    /**
     * Applies the configured minimum PHP version floor when the config sets one.
     *
     * @param AnalysisConfig $config     - Config to extend; returned unchanged when no minimumPhpVersion is set.
     * @param ConfigObject   $rootConfig - Decoded root config map; absence of the key leaves the default PHP floor in place.
     *
     * @return AnalysisConfig - Config with the PHP version floor applied.
     */
    private function applyMinimumPhpVersion(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        // No minimumPhpVersion leaves the default PHP floor in place.
        if (!array_key_exists('minimumPhpVersion', $rootConfig)) {
            return $config;
        }

        $version = $rootConfig['minimumPhpVersion'];
        if (!is_int($version) && !is_float($version)) {
            throw new ConfigException('Config key "minimumPhpVersion" must be numeric.');
        }

        // Versions below 7.4 predate what gruff can reason about, so they are rejected.
        if ($version < 7.4) {
            throw new ConfigException('Config key "minimumPhpVersion" must be at least 7.4.');
        }

        return $config->withMinimumPhpVersion((float)$version);
    }

    /**
     * Applies the optional budget that bounds PHP parsing and other structural work while preserving
     * raw-text rules. Omitted fields inherit their current value across an extends chain.
     *
     * @param AnalysisConfig $config     - Config carrying the inherited or default budget.
     * @param ConfigObject   $rootConfig - Decoded config map; absence preserves the current budget.
     *
     * @return AnalysisConfig - Config carrying the effective budget with config provenance.
     */
    private function applyDeepScanBudgetConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        if (!array_key_exists('deepScanBudget', $rootConfig)) {
            return $config;
        }

        $budget = $this->requireObject(
            $rootConfig['deepScanBudget'],
            'Config key "deepScanBudget" must be an object.',
        );

        foreach (array_keys($budget) as $key) {
            if (!in_array($key, ['enabled', 'maxLines', 'maxBytes'], true)) {
                throw new ConfigException(sprintf('Unknown config key "deepScanBudget.%s".', $key));
            }
        }

        $effective = $config->deepScanBudget();
        $enabled   = $budget['enabled'] ?? $effective['enabled'];
        $maxLines  = $budget['maxLines'] ?? $effective['maxLines'];
        $maxBytes  = $budget['maxBytes'] ?? $effective['maxBytes'];

        if (!is_bool($enabled)) {
            throw new ConfigException('Config key "deepScanBudget.enabled" must be a boolean.');
        }

        if (!is_int($maxLines) || $maxLines < 1) {
            throw new ConfigException('Config key "deepScanBudget.maxLines" must be a positive integer.');
        }

        if (!is_int($maxBytes) || $maxBytes < 1) {
            throw new ConfigException('Config key "deepScanBudget.maxBytes" must be a positive integer.');
        }

        return $config->withDeepScanBudget($enabled, $maxLines, $maxBytes, 'config');
    }

    /**
     * Applies the configured `paths:` ignore globs that shape which files discovery scans.
     *
     * @param AnalysisConfig $config     - Config to extend; returned unchanged when no paths block is present.
     * @param ConfigObject   $rootConfig - Decoded root config map; absence of the paths block leaves ignore patterns unchanged.
     *
     * @return AnalysisConfig - Config with ignored path patterns applied.
     */
    private function applyPathConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        // No paths block leaves the current ignore patterns unchanged.
        if (!array_key_exists('paths', $rootConfig)) {
            return $config;
        }

        // Replace ignore patterns wholesale with the parsed paths.ignore list for this config layer.
        return $config->withIgnoredPathPatterns($this->parsePathsConfig($rootConfig['paths']));
    }

    /**
     * Applies the optional `failureConditions:` count gates that fail a run on too many findings.
     *
     * @param AnalysisConfig $config     - Config to extend; returned unchanged when no failureConditions block is set.
     * @param ConfigObject   $rootConfig - Decoded root config map; absence of the block preserves inherited failure gates.
     *
     * @return AnalysisConfig - Config with the failure-condition thresholds applied.
     * @throws ConfigException When the failureConditions block is malformed.
     */
    private function applyFailureConditionsConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        // No failureConditions block preserves inherited failure gates.
        if (!array_key_exists('failureConditions', $rootConfig)) {
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
     * Applies the user's `allowlists:` while retaining defaults for every omitted sub-key.
     * The legacy `secretPreviews` key accepts only `[]` and has no effect, so migration cannot disturb naming defaults such as `id` and `url`.
     *
     * @param AnalysisConfig $config     - Config to extend; each allowlist sub-key the user omitted is left as-is.
     * @param ConfigObject   $rootConfig - Decoded root config map; omitted allowlist sub-keys keep registry-seeded defaults.
     *
     * @return AnalysisConfig - Config with allowlist values applied.
     */
    private function applyAllowlistConfig(AnalysisConfig $config, array $rootConfig): AnalysisConfig
    {
        // No allowlists block leaves the registry-seeded defaults intact.
        if (!array_key_exists('allowlists', $rootConfig)) {
            return $config;
        }

        $allowlists = $this->parseAllowlistsConfig($rootConfig['allowlists']);

        // Apply the naming allowlist only when the user actually set it.
        if ($allowlists['acceptedAbbreviations'] !== null) {
            $config = $config->withAcceptedAbbreviations($allowlists['acceptedAbbreviations']);
        }

        if ($allowlists['secretPreviews'] !== null) {
            $config = $config->withAllowedSecretPreviews($allowlists['secretPreviews']);
        }

        return $config;
    }

    /**
     * Applies the configured `selection:` include/exclude rules that decide which rules run.
     *
     * @param AnalysisConfig $config     - Config to extend; returned unchanged when no selection block is present.
     * @param RuleRegistry   $registry   - Registry of known rule ids the parser validates include/exclude entries against.
     * @param ConfigObject   $rootConfig - Decoded root config map; absence of selection leaves the active rule set unchanged.
     *
     * @return AnalysisConfig - Config with include/exclude selection applied.
     */
    private function applySelectionConfig(
        AnalysisConfig $config,
        RuleRegistry   $registry,
        array          $rootConfig,
    ): AnalysisConfig {
        // No selection block leaves the active rule set unchanged.
        if (!array_key_exists('selection', $rootConfig)) {
            return $config;
        }

        // Parser checks each id against the registry, so an unknown rule fails rather than silently no-opping.
        return $config->withRuleSelection((new SelectionConfigParser())->parse($this->configValue($rootConfig['selection']), $registry));
    }

    /**
     * Applies the configured `sensitiveExclusions:` entries, the only channel that may hide a
     * sensitive-data finding. Parsing validates every entry against the registry, so a wildcard, an
     * unknown or non-sensitive rule, a globbed path, a message- or value-matching key, a missing
     * rationale, or a duplicated scope stops the run instead of quietly hiding findings.
     *
     * @param AnalysisConfig $config     - Config to extend; returned unchanged when no sensitiveExclusions block is present.
     * @param RuleRegistry   $registry   - Registry each entry's rule id is checked and classified against.
     * @param ConfigObject   $rootConfig - Decoded root config map; absence of the block leaves inherited exclusions in place.
     *
     * @return AnalysisConfig - Config carrying the validated sensitive-data exclusions.
     * @throws ConfigException When any entry in the block is invalid.
     */
    private function applySensitiveExclusionsConfig(
        AnalysisConfig $config,
        RuleRegistry   $registry,
        array          $rootConfig,
    ): AnalysisConfig {
        // No sensitiveExclusions block leaves any inherited exclusions in place.
        if (!array_key_exists(SensitiveExclusionConfigParser::CONFIG_KEY, $rootConfig)) {
            return $config;
        }

        // Replace the inherited entries wholesale, so a child config's list is the one that applies.
        return $config->withSensitiveExclusions(
            (new SensitiveExclusionConfigParser())->parse($this->configValue($rootConfig[SensitiveExclusionConfigParser::CONFIG_KEY]), $registry),
        );
    }

    /**
     * Parses `paths.ignore` into the relative path globs discovery uses to skip files.
     *
     * @param mixed $decodedValue - Decoded value of the `paths` key; must be a mapping or the parse throws.
     *
     * @return list<string> - validated relative path globs from paths.ignore; empty when the block has no ignore key.
     */
    private function parsePathsConfig(mixed $decodedValue): array
    {
        $pathsConfig = $this->requireObject($decodedValue, 'Config key "paths" must be an object.');

        // Only an `ignore` key is supported under paths.
        foreach (array_keys($pathsConfig) as $key) {
            if ($key !== 'ignore') {
                throw new ConfigException(sprintf('Unknown config key "paths.%s".', $key));
            }
        }

        // A paths block with no ignore key contributes no patterns rather than erroring.
        if (!array_key_exists('ignore', $pathsConfig)) {
            return [];
        }

        // hasPathPatterns + allowsGlobs: ignore entries are relative path globs, so enable path-escape and
        // glob validation; a blank or absolute/traversing pattern throws rather than silently widening discovery.
        return (new StringListConfigParser())->parse($this->configValue($pathsConfig['ignore']), 'paths.ignore', true, true);
    }

    /**
     * Parses the naming allowlist and retained empty secret-preview key, returning null per sub-key the user omitted.
     * Omitted keys return null so user defaults survive; a present secretPreviews key can only return the validated empty list.
     *
     * @param mixed $decodedValue - Decoded value of the `allowlists` key; must be a mapping or the parse throws.
     *
     * @return array{acceptedAbbreviations: list<string>|null, secretPreviews: list<string>|null} - parsed naming list and inert legacy list;
     *   null means the user omitted that key, while secretPreviews can otherwise only be empty
     */
    private function parseAllowlistsConfig(mixed $decodedValue): array
    {
        $allowlists = $this->requireObject($decodedValue, 'Config key "allowlists" must be an object.');

        // Only the two known allowlist sub-keys are supported.
        foreach (array_keys($allowlists) as $key) {
            if (!in_array($key, ['acceptedAbbreviations', 'secretPreviews'], true)) {
                throw new ConfigException(sprintf('Unknown config key "allowlists.%s".', $key));
            }
        }

        $acceptedAbbreviations = null;
        // Parse the naming allowlist only when the user set it.
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

        // Missing preserves the user's inherited configuration; presence becomes [] because shape validation already rejected every other value.
        $legacySecretPreviews = array_key_exists('secretPreviews', $allowlists) ? [] : null;

        return [
            'acceptedAbbreviations' => $acceptedAbbreviations,
            'secretPreviews' => $legacySecretPreviews,
        ];
    }

    /**
     * Decodes YAML config text into a root object, rejecting a non-.yaml file or a non-mapping document.
     *
     * @param string $contents - Raw file contents to parse as YAML.
     * @param string $path     - Source path; only its extension is read here, to gate the .yaml/.yml requirement.
     *
     * @return ConfigObject - the parsed YAML mapping; an empty document yields an empty array, a non-mapping throws.
     * @throws ConfigException When extension, YAML, root shape, or a legacy preview value is invalid for the user's configuration.
     */
    private function decodeConfig(string $contents, string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Only .yaml/.yml config files are accepted.
        if (!in_array($extension, ['yaml', 'yml'], true)) {
            throw new ConfigException(sprintf(
                                          'Config file must use a .yaml or .yml extension: %s',
                                          $path,
                                      ));
        }

        try {
            $shapePreservingConfig = Yaml::parse(
                $contents,
                Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_OBJECT_FOR_MAP,
            );
            $decoded = Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            // A user may have left an unmatched quote or invalid indentation; surface that parser detail as one config error before analysis.
            throw new ConfigException(sprintf('Invalid YAML config: %s', $exception->getMessage()), 0, $exception);
        }

        $this->assertLegacySecretPreviewsAreEmpty($shapePreservingConfig);

        return $this->requireObject($decoded, 'Config root must be an object.');
    }

    /**
     * Validates the retained secret-preview key before the user's analysis starts.
     * Parsing maps as objects distinguishes `{}` from `[]`: missing and exact `[]` are inert, while every other value fails safely.
     *
     * @param mixed $shapePreservingConfig - Shape-preserving YAML; null, scalar, and root lists are handled by root validation.
     *
     * @return void - Missing `allowlists.secretPreviews` or exact `[]` leaves the user's configuration unchanged.
     * @throws ConfigException When the key contains a scalar, null, map, blank entry, mixed list, or any non-empty list; the message never
     *                         echoes the configured value.
     */
    private function assertLegacySecretPreviewsAreEmpty(mixed $shapePreservingConfig): void
    {
        // A non-object root cannot contain a valid allowlists mapping; general root validation gives the user its dedicated error.
        if (!$shapePreservingConfig instanceof \stdClass) {
            return;
        }

        $rootConfig = get_object_vars($shapePreservingConfig);
        // Missing allowlists are inert; a malformed allowlists value is reported later by the normal section validator.
        if (!array_key_exists('allowlists', $rootConfig) || !$rootConfig['allowlists'] instanceof \stdClass) {
            return;
        }

        $allowlistConfig = get_object_vars($rootConfig['allowlists']);
        // A missing legacy key means the user has no preview-based configuration to migrate.
        if (!array_key_exists('secretPreviews', $allowlistConfig)) {
            return;
        }

        // Only the generated empty list is inert; any other shape must stop before it could hide a finding.
        if ($allowlistConfig['secretPreviews'] !== []) {
            throw new ConfigException(self::LEGACY_SECRET_PREVIEWS_ERROR);
        }
    }

    /**
     * Confirms a decoded config value is an object (a string-keyed map), normalising it or throwing.
     *
     * @param mixed  $decodedValue - Decoded value to check; an empty array passes, a list or scalar does not.
     * @param string $message      - Error text thrown when the value is not an object or has a non-string key.
     *
     * @return ConfigObject - the value as a string-keyed map with each entry normalised; an empty array passes through.
     */
    private function requireObject(mixed $decodedValue, string $message): array
    {
        // Reject anything that is not an object: a scalar, or a non-empty list.
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
     * Normalises one decoded config value, recursing into arrays and validating scalars.
     *
     * @param mixed $decodedValue - One decoded YAML node (array or scalar) to constrain to the supported shape.
     *
     * @return ConfigValue - the node narrowed to the supported shape: a recursively normalised array, or a validated scalar.
     */
    private function configValue(mixed $decodedValue): array|bool|float|int|object|string|null
    {
        if (is_array($decodedValue)) {
            return $this->configArray($decodedValue);
        }

        return $this->configScalar($decodedValue);
    }

    /**
     * Confirms a decoded leaf value is a YAML/JSON-compatible scalar (or null/object), or rejects it.
     *
     * @param mixed $decodedValue - Decoded leaf value expected to be a YAML/JSON scalar (bool, number, string, object, null).
     *
     * @return ConfigScalar - the same leaf passed through unchanged once confirmed to be a permitted scalar type.
     */
    private function configScalar(mixed $decodedValue): bool|float|int|object|string|null
    {
        // A permitted scalar - or null, or an object - passes straight through.
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_object($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            return $decodedValue;
        }

        throw new ConfigException('Config value must be YAML/JSON-compatible.');
    }

    /**
     * Normalises a top-level config array, recursing one level deeper into nested arrays.
     *
     * @param array<array-key, mixed> $decodedConfigValues - Decoded config subtree at the first supported nesting level.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>> - the same
     *                          keys with each value normalised, nested arrays recursed to depth 2.
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
     * Normalises a second-level config array, recursing one level deeper into nested arrays.
     *
     * @param array<array-key, mixed> $decodedConfigValues - Decoded config subtree at the second supported nesting level.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>> - the same keys with each value
     *                          normalised, nested arrays recursed to depth 3.
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
     * Normalises a third-level config array, recursing one level deeper into nested arrays.
     *
     * @param array<array-key, mixed> $decodedConfigValues - Decoded config subtree at the third supported nesting level.
     *
     * @return array<array-key, ConfigScalar|array<array-key, ConfigScalar>> - the same keys with each value normalised, nested arrays recursed to
     *                          depth 4.
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
     * Normalises the fourth (deepest allowed) config level, rejecting any array nested deeper still.
     *
     * @param array<array-key, mixed> $decodedConfigValues - Decoded config subtree at the final supported nesting level.
     *
     * @return array<array-key, ConfigScalar> - the same keys with every value as a validated scalar; a nested array here throws.
     */
    private function configArrayDepth4(array $decodedConfigValues): array
    {
        $normalizedConfigValues = [];

        foreach ($decodedConfigValues as $key => $decodedItem) {
            // A nested array here is deeper than gruff supports, so reject it.
            if (is_array($decodedItem)) {
                throw new ConfigException('Config value nesting is deeper than supported.');
            }

            $normalizedConfigValues[$key] = $this->configScalar($decodedItem);
        }

        return $normalizedConfigValues;
    }
}
