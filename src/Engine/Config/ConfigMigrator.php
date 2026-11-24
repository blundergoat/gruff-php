<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Rules\RuleRegistry;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Converts 0.5 YAML settings for `gruff-php migrate-config` after an upgrade.
 *
 * Moves legacy command thresholds to `failOn`, drops retired preview and rule settings, and renames legacy rule aliases.
 * Returns the text and a change list so the command can preview the migration or write a new file.
 */
final class ConfigMigrator
{
    /**
     * Verified rule IDs retired in ADR-006, ADR-014, ADR-018, ADR-023, ADR-026, ADR-027, ADR-034, and 0.6.0.
     * Kept explicit so migration removes known retired IDs without silently deleting mistyped unknown rule names.
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
     * Migrates the YAML mapping while preserving unaffected configuration values.
     *
     * @param string $original - Complete source configuration.
     * @return array{text: string, changes: list<string>} - Migrated YAML, or original bytes when already current.
     * @throws ConfigException - When the source cannot be migrated safely.
     */
    public static function migrate(string $original): array
    {
        try {
            $config = Yaml::parse($original, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException) {
            // A malformed legacy preview list can appear in Symfony's YAML error text, so give a diagnostic without quoting it.
            throw new ConfigException('Invalid YAML in migration input.');
        }

        // A legacy config needs named settings. A scalar or top-level list cannot be migrated as a project config.
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            throw new ConfigException('Migration input must be a YAML mapping.');
        }

        $changes = [];

        // A legacy file without a schema marker gets the marker needed by the current loader.
        if (!array_key_exists('schemaVersion', $config)) {
            $config    = ['schemaVersion' => ConfigLoader::SCHEMA_VERSION] + $config;
            $changes[] = 'Add the required schemaVersion.';
        } elseif ($config['schemaVersion'] !== ConfigLoader::SCHEMA_VERSION) {
            // Leave unknown schema versions alone because their settings may have different meanings.
            throw new ConfigException(sprintf('Unsupported schemaVersion in migration input. Expected "%s".', ConfigLoader::SCHEMA_VERSION));
        }

        $legacyExitThresholds = $config['minimumSeverity'] ?? null;

        // A command-by-command minimumSeverity map is legacy. A scalar value already expresses a display floor.
        if (is_array($legacyExitThresholds)) {
            // Ask the caller to reconcile both sets of exit thresholds rather than choosing one silently.
            if (array_key_exists('failOn', $config)) {
                throw new ConfigException('Migration input sets both a legacy minimumSeverity mapping and failOn. Combine them before migrating.');
            }

            $config['failOn'] = $legacyExitThresholds;
            unset($config['minimumSeverity']);
            $changes[] = 'Move the minimumSeverity command gates to failOn.';
        }

        $allowlists = $config['allowlists'] ?? null;

        // Remove the retired preview setting so a migrated file cannot request raw secret previews.
        if (is_array($allowlists) && array_key_exists('secretPreviews', $allowlists)) {
            unset($allowlists['secretPreviews']);
            // Drop the empty allowlists section when the preview setting was its only entry.
            if ($allowlists === []) {
                unset($config['allowlists']);
            } else {
                $config['allowlists'] = $allowlists;
            }
            $changes[] = 'Remove allowlists.secretPreviews. Sensitive findings always use fixed redaction markers.';
        }

        $config = self::migrateRules($config, $changes);
        $config = self::migrateSelection($config, $changes);

        return ['text' => $changes === [] ? $original : Yaml::dump($config, 10, 4), 'changes' => $changes];
    }

    /**
     * Strips verified retired rule blocks and renames legacy rule aliases under `rules:`, leaving unknown typos intact.
     *
     * @param array<array-key, mixed> $config  - Decoded config mapping being migrated.
     * @param list<string>            $changes - Human-readable migration summary lines appended in place.
     *
     * @return array<array-key, mixed> - Updated config mapping.
     * @throws ConfigException - When both a legacy alias and its replacement exist under `rules:`.
     */
    private static function migrateRules(array $config, array &$changes): array
    {
        $rules = $config['rules'] ?? null;
        if (!is_array($rules) || ($rules !== [] && array_is_list($rules))) {
            return $config;
        }

        $migrated     = [];
        $removedCount = 0;

        foreach ($rules as $ruleId => $ruleConfig) {
            if (!is_string($ruleId)) {
                $migrated[$ruleId] = $ruleConfig;
                continue;
            }

            if (isset(self::RETIRED_RULE_IDS[$ruleId])) {
                $removedCount++;
                continue;
            }

            $canonicalId = RuleRegistry::canonicalRuleId($ruleId);
            if ($canonicalId !== $ruleId) {
                // Avoid silently overwriting explicit settings when both legacy and replacement keys exist.
                if (array_key_exists($canonicalId, $rules)) {
                    throw new ConfigException(sprintf(
                        'Migration input sets both legacy "%s" and "%s" under rules. Combine them before migrating.',
                        $ruleId,
                        $canonicalId,
                    ));
                }

                $migrated[$canonicalId] = $ruleConfig;
                $changes[]              = sprintf('Rename legacy rule "%s" to "%s" under rules.', $ruleId, $canonicalId);
                continue;
            }

            $migrated[$ruleId] = $ruleConfig;
        }

        if ($removedCount > 0) {
            $changes[] = sprintf('Remove %d retired rule override(s) from rules.', $removedCount);
        }

        if ($migrated === [] && $removedCount > 0) {
            unset($config['rules']);
        } else {
            $config['rules'] = $migrated;
        }

        return $config;
    }

    /**
     * Strips verified retired rule IDs and renames legacy aliases in `selection.rules` and `selection.excludeRules`.
     *
     * @param array<array-key, mixed> $config  - Decoded config mapping being migrated.
     * @param list<string>            $changes - Human-readable migration summary lines appended in place.
     *
     * @return array<array-key, mixed> - Updated config mapping.
     */
    private static function migrateSelection(array $config, array &$changes): array
    {
        $selection = $config['selection'] ?? null;
        if (!is_array($selection) || ($selection !== [] && array_is_list($selection))) {
            return $config;
        }

        foreach (['rules', 'excludeRules'] as $selectionKey) {
            $entries = $selection[$selectionKey] ?? null;
            if (!is_array($entries) || !array_is_list($entries)) {
                continue;
            }

            $selection[$selectionKey] = self::migrateSelectionList($entries, $selectionKey, $changes);
        }

        $config['selection'] = $selection;

        return $config;
    }

    /**
     * Filters retired rule IDs and renames legacy aliases in one `selection` rule list without dropping unknown typos.
     *
     * @param list<mixed>  $entries      - Raw rule-list entries from `selection.rules` or `selection.excludeRules`.
     * @param string       $selectionKey - Key name (`rules` or `excludeRules`) used in migration messages.
     * @param list<string> $changes      - Human-readable migration summary lines appended in place.
     *
     * @return list<mixed> - Migrated rule list in original order without duplicate renamed IDs.
     */
    private static function migrateSelectionList(array $entries, string $selectionKey, array &$changes): array
    {
        $migrated     = [];
        $removedCount = 0;

        foreach ($entries as $ruleId) {
            if (!is_string($ruleId)) {
                $migrated[] = $ruleId;
                continue;
            }

            if (isset(self::RETIRED_RULE_IDS[$ruleId])) {
                $removedCount++;
                continue;
            }

            $canonicalId = RuleRegistry::canonicalRuleId($ruleId);
            if ($canonicalId !== $ruleId) {
                $changes[] = sprintf('Rename legacy rule "%s" to "%s" in selection.%s.', $ruleId, $canonicalId, $selectionKey);
                if (!in_array($canonicalId, $migrated, true)) {
                    $migrated[] = $canonicalId;
                }
                continue;
            }

            if (!in_array($ruleId, $migrated, true)) {
                $migrated[] = $ruleId;
            }
        }

        if ($removedCount > 0) {
            $changes[] = sprintf('Remove %d retired rule id(s) from selection.%s.', $removedCount, $selectionKey);
        }

        return $migrated;
    }
}
