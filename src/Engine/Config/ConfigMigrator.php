<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Converts 0.5 YAML settings for `gruff-php migrate-config` after an upgrade.
 *
 * Moves legacy command thresholds to `failOn` and drops the retired setting for secret previews.
 * Returns the text and a change list so the command can preview the migration or write a new file.
 */
final class ConfigMigrator
{
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

        return ['text' => $changes === [] ? $original : Yaml::dump($config, 10, 4), 'changes' => $changes];
    }
}
