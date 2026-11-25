<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Rules\RuleRegistry;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Converts a 0.5 `.gruff-php.yaml` or `.gruff.yaml` file to the 0.6.0 config format when a user runs `gruff-php migrate-config`.
 *
 * Typical path: a project hits a config-error on 0.6.0 and runs `gruff-php migrate-config --config .gruff-php.yaml --output .gruff-php.next.yaml`.
 *
 * The migrator updates only recognized legacy settings while leaving custom rules and unknown typos untouched:
 * - Adds `schemaVersion: gruff-php.config.v0.1` when absent and moves per-command `minimumSeverity` maps to `failOn`.
 * - Drops the retired `allowlists.secretPreviews` key and strips verified retired rule IDs from `rules:` and `selection:`.
 * - Renames legacy rule aliases such as `docs.missing-public-phpdoc` to `docs.missing-phpdoc`, stopping if both keys already exist.
 */
final class ConfigMigrator
{
    /**
     * Verified rule IDs retired in ADR-006, ADR-014, ADR-018, ADR-023, ADR-026, ADR-027, ADR-034, and 0.6.0.
     * Kept explicit so migration strips known retired IDs without silently deleting a user's mistyped rule name.
     *
     * @var array<string, true>
     */
    private const RETIRED_RULE_IDS = [
        'complexity.npath' => true,
        'dead-code.unused-internal-class' => true,
        'dead-code.unused-internal-constant' => true,
        'dead-code.unused-internal-function' => true,
        'dead-code.unused-private-property' => true,
        'design.god-method' => true,
        'design.single-implementor-interface' => true,
        'docs.continue-comment' => true,
        'docs.stale-param-tag' => true,
        'modernisation.constructor-promotion-candidate' => true,
        'modernisation.enum-candidate' => true,
        'modernisation.first-class-callable-candidate' => true,
        'modernisation.forbidden-global-access' => true,
        'modernisation.match-expression-candidate' => true,
        'modernisation.mixed-type-overuse' => true,
        'modernisation.named-argument-opportunity' => true,
        'modernisation.phpdoc-mixed-overuse' => true,
        'naming.boolean-prefix' => true,
        'naming.generic-method' => true,
        'naming.hungarian-notation' => true,
        'naming.negative-boolean' => true,
        'naming.parameter-type-name' => true,
        'naming.suffix-hungarian' => true,
        'security.dependency-composer-unpinned' => true,
        'security.github-actions-risky-workflow' => true,
        'security.insecure-random' => true,
        'security.path-traversal-file-access' => true,
        'security.sensitive-data-logging' => true,
        'security.variable-include' => true,
        'security.weak-crypto' => true,
        'sensitive-data.hardcoded-env-value' => true,
        'sensitive-data.high-entropy-string' => true,
        'sensitive-data.phi-pattern' => true,
        'sensitive-data.url-credentials' => true,
        'size.average-method-length' => true,
        'test-quality.conditional-logic' => true,
        'test-quality.eager-test' => true,
        'test-quality.excessive-mocking' => true,
        'test-quality.extends-production-class' => true,
        'test-quality.magic-number-assertion' => true,
        'test-quality.mock-only-test' => true,
        'test-quality.mock-without-expectation' => true,
        'test-quality.multiple-aaa-cycles' => true,
        'test-quality.mystery-guest' => true,
        'test-quality.setup-bloat' => true,
        'test-quality.skipped-without-reason' => true,
        'test-quality.sleep-in-test' => true,
        'test-quality.tautological-type-assertion' => true,
        'test-quality.test-method-too-long' => true,
        'test-quality.trivial-assertion' => true,
        'test-quality.unused-mock' => true,
        'waste.empty-class' => true,
        'waste.one-line-method' => true,
        'waste.redundant-variable' => true,
        'waste.unused-parameter' => true,
    ];

    /**
     * Returns the verified retired rule IDs that migration strips from legacy configs.
     *
     * @return list<string> - Retired rule identifiers in sorted order.
     */
    public static function retiredRuleIds(): array
    {
        return array_keys(self::RETIRED_RULE_IDS);
    }

    /**
     * Migrates a 0.5 YAML config string to the 0.6.0 schema while preserving unaffected user settings.
     *
     * @param string $original - Raw YAML text read from the user's config file; an empty string is rejected because it is not a mapping.
     *
     * @return array{text: string, changes: list<string>} - Migrated YAML and change lines; an empty change list preserves original bytes verbatim.
     * @throws ConfigException - When the YAML is invalid, uses an unknown schemaVersion, or contains conflicting legacy and current settings.
     */
    public static function migrate(string $original): array
    {
        try {
            $config = Yaml::parse($original, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException) {
            // For example, a user broke indentation or left an unclosed quote in `.gruff-php.yaml`; omit raw text so legacy previews cannot leak.
            throw new ConfigException('Invalid YAML in migration input.');
        }

        // A project config must be a YAML mapping of named sections; a bare scalar, empty file, or top-level list cannot be migrated.
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            throw new ConfigException('Migration input must be a YAML mapping.');
        }

        $changes = [];

        // A 0.5 config without a schemaVersion receives the 0.6.0 marker so ConfigLoader accepts the migrated output.
        if (!array_key_exists('schemaVersion', $config)) {
            $config    = ['schemaVersion' => ConfigLoader::SCHEMA_VERSION] + $config;
            $changes[] = 'Add the required schemaVersion.';
        } elseif ($config['schemaVersion'] !== ConfigLoader::SCHEMA_VERSION) {
            // Refuse an unfamiliar schemaVersion rather than rewriting keys whose meaning may belong to another format.
            throw new ConfigException(sprintf('Unsupported schemaVersion in migration input. Expected "%s".', ConfigLoader::SCHEMA_VERSION));
        }

        $legacyExitThresholds = $config['minimumSeverity'] ?? null;

        // In 0.5, a per-command minimumSeverity map set exit gates; in 0.6.0, scalar minimumSeverity is the display floor and failOn sets gates.
        if (is_array($legacyExitThresholds)) {
            // If the user already started migrating by adding failOn alongside the old map, stop so they can reconcile both thresholds manually.
            if (array_key_exists('failOn', $config)) {
                throw new ConfigException('Migration input sets both a legacy minimumSeverity mapping and failOn. Combine them before migrating.');
            }

            $config['failOn'] = $legacyExitThresholds;
            unset($config['minimumSeverity']);
            $changes[] = 'Move the minimumSeverity command gates to failOn.';
        }

        $allowlists = $config['allowlists'] ?? null;

        // Drop the removed allowlists.secretPreviews key so the migrated file does not trip the 0.6.0 loader error.
        if (is_array($allowlists) && array_key_exists('secretPreviews', $allowlists)) {
            unset($allowlists['secretPreviews']);
            // Remove the allowlists block completely when secretPreviews was its only entry, avoiding an empty section.
            if ($allowlists === []) {
                unset($config['allowlists']);
            } else {
                $config['allowlists'] = $allowlists;
            }
            $changes[] = 'Remove allowlists.secretPreviews. Sensitive findings always use fixed redaction markers.';
        }

        $config = self::migrateRules($config, $changes);
        $config = self::migrateSelection($config, $changes);

        return [
            'text' => $changes === [] ? $original : Yaml::dump($config, 10, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE),
            'changes' => $changes,
        ];
    }

    /**
     * Strips verified retired rule blocks and renames legacy aliases under `rules:`, leaving mistyped rule IDs intact for normal validation.
     *
     * @param array<array-key, mixed> $config  - Decoded config mapping being migrated; missing or non-map `rules` passes through unchanged.
     * @param list<string>            $changes - Human-readable migration summary lines appended in place for CLI output.
     *
     * @return array<array-key, mixed> - Updated config mapping with retired overrides removed and legacy aliases renamed.
     * @throws ConfigException - When both a legacy alias and its replacement key exist under `rules:`.
     */
    private static function migrateRules(array $config, array &$changes): array
    {
        $rules = $config['rules'] ?? null;
        // Skip when the config defines no per-rule overrides or uses a non-map shape that ConfigLoader should diagnose.
        if (!is_array($rules) || ($rules !== [] && array_is_list($rules))) {
            return $config;
        }

        $migratedRules = [];
        $removedCount  = 0;

        // Inspect each configured rule override in user-authored order so untouched rules keep their exact position.
        foreach ($rules as $ruleId => $ruleConfig) {
            // Preserve unexpected non-string keys untouched so normal config validation reports them accurately.
            if (!is_string($ruleId)) {
                $migratedRules[$ruleId] = $ruleConfig;
                continue;
            }

            // Drop verified retired 0.5 rule overrides that would otherwise make 0.6.0 reject the upgraded file.
            if (isset(self::RETIRED_RULE_IDS[$ruleId])) {
                $removedCount++;
                continue;
            }

            $canonicalId = RuleRegistry::canonicalRuleId($ruleId);
            // Rename a retired alias such as docs.missing-public-phpdoc to its 0.6.0 rule ID when no conflict exists.
            if ($canonicalId !== $ruleId) {
                // Stop if both the old alias and the new ID are configured, so neither set of user options is silently discarded.
                if (array_key_exists($canonicalId, $rules)) {
                    throw new ConfigException(sprintf(
                        'Migration input sets both legacy "%s" and "%s" under rules. Combine them before migrating.',
                        $ruleId,
                        $canonicalId,
                    ));
                }

                $migratedRules[$canonicalId] = $ruleConfig;
                $changes[]                   = sprintf('Rename legacy rule "%s" to "%s" under rules.', $ruleId, $canonicalId);
                continue;
            }

            $migratedRules[$ruleId] = $ruleConfig;
        }

        // Report how many retired rule blocks were stripped so the user can review the migration summary before applying it.
        if ($removedCount > 0) {
            $changes[] = sprintf('Remove %d retired rule override(s) from rules.', $removedCount);
        }

        // Remove the rules section completely if every entry in it was retired, rather than emitting an empty block.
        if ($migratedRules === [] && $removedCount > 0) {
            unset($config['rules']);
        } else {
            $config['rules'] = $migratedRules;
        }

        return $config;
    }

    /**
     * Strips verified retired rule IDs and renames legacy aliases inside `selection.rules` and `selection.excludeRules`.
     *
     * @param array<array-key, mixed> $config  - Decoded config mapping being migrated; missing or non-map `selection` passes through unchanged.
     * @param list<string>            $changes - Human-readable migration summary lines appended in place for CLI output.
     *
     * @return array<array-key, mixed> - Updated config mapping with migrated selection lists.
     */
    private static function migrateSelection(array $config, array &$changes): array
    {
        $selection = $config['selection'] ?? null;
        // Skip when the config has no selection block or uses a non-map shape that ConfigLoader should diagnose.
        if (!is_array($selection) || ($selection !== [] && array_is_list($selection))) {
            return $config;
        }

        // Migrate both explicit inclusion and exclusion rule lists using the same retirement and alias rules.
        foreach (['rules', 'excludeRules'] as $selectionKey) {
            $configuredRuleIds = $selection[$selectionKey] ?? null;
            // Skip absent or malformed list entries so normal config validation reports structural mistakes.
            if (!is_array($configuredRuleIds) || !array_is_list($configuredRuleIds)) {
                continue;
            }

            $migratedRuleIds          = self::migrateSelectionList($configuredRuleIds, $selectionKey, $changes);
            $hasOtherIncludeSelectors = self::hasNonEmptyList($selection['tiers'] ?? null) || self::hasNonEmptyList($selection['pillars'] ?? null);
            // Leaving selection.rules empty with no tier or pillar filter causes RuleSelection to run every rule in the registry.
            if ($selectionKey === 'rules' && $configuredRuleIds !== [] && $migratedRuleIds === [] && !$hasOtherIncludeSelectors) {
                throw new ConfigException(
                    'Every rule in selection.rules was retired in 0.6.0; leaving selection.rules empty would widen the scan to every rule. Choose replacement rules or remove selection.rules explicitly before migrating.',
                );
            }

            $selection[$selectionKey] = $migratedRuleIds;
        }

        $config['selection'] = $selection;

        return $config;
    }

    /**
     * Reports whether a decoded config entry is a non-empty list, so selection migration knows when another include filter remains active.
     *
     * @param mixed $value - Raw decoded value from `selection.tiers` or `selection.pillars`; null or empty means no fallback filter is set.
     *
     * @return bool - True when the entry holds at least one list item; false when absent, empty, or not a list.
     */
    private static function hasNonEmptyList(mixed $value): bool
    {
        return is_array($value) && $value !== [] && array_is_list($value);
    }

    /**
     * Filters retired rule IDs and renames legacy aliases in one `selection` rule list without dropping unknown typos.
     *
     * @param list<mixed>  $configuredRuleIds - Raw rule-list entries from `selection.rules` or `selection.excludeRules`; empty returns empty.
     * @param string       $selectionKey      - Key name (`rules` or `excludeRules`) echoed in migration summary lines.
     * @param list<string> $changes           - Human-readable migration summary lines appended in place for CLI output.
     *
     * @return list<mixed> - Migrated rule list in original order with duplicate renamed IDs collapsed; empty when all entries were retired.
     */
    private static function migrateSelectionList(array $configuredRuleIds, string $selectionKey, array &$changes): array
    {
        $migratedRuleIds = [];
        $removedCount    = 0;

        // Walk the user's selected rule list in order, stripping retired IDs and renaming aliases without hiding typos.
        foreach ($configuredRuleIds as $ruleId) {
            // Preserve non-string items untouched so ConfigLoader can point out the bad YAML value.
            if (!is_string($ruleId)) {
                $migratedRuleIds[] = $ruleId;
                continue;
            }

            // Drop verified retired rule IDs that no longer exist in the 0.6.0 registry.
            if (isset(self::RETIRED_RULE_IDS[$ruleId])) {
                $removedCount++;
                continue;
            }

            $canonicalId = RuleRegistry::canonicalRuleId($ruleId);
            // Replace a legacy alias with its current rule ID and avoid duplicating it if the current ID is also listed.
            if ($canonicalId !== $ruleId) {
                $changes[] = sprintf('Rename legacy rule "%s" to "%s" in selection.%s.', $ruleId, $canonicalId, $selectionKey);
                // Keep only one copy if the user already listed the replacement ID elsewhere in the same selection list.
                if (!in_array($canonicalId, $migratedRuleIds, true)) {
                    $migratedRuleIds[] = $canonicalId;
                }
                continue;
            }

            // Keep active and unrecognized rule IDs once in original order so typos still fail loud during config load.
            if (!in_array($ruleId, $migratedRuleIds, true)) {
                $migratedRuleIds[] = $ruleId;
            }
        }

        // Summarize retired removals for this list so the caller sees exactly what `migrate-config` cleaned up.
        if ($removedCount > 0) {
            $changes[] = sprintf('Remove %d retired rule id(s) from selection.%s.', $removedCount, $selectionKey);
        }

        return $migratedRuleIds;
    }
}
