<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

/**
 * Carries a 0.5 configuration forward to the 0.6 schema, line by line.
 * The per-command exit gate moves from minimumSeverity to failOn; allowlists.secretPreviews is removed.
 * FAMILY-CONTRACT.md section 5 requires unconditional category markers, so the removed setting has no effect.
 *
 * Line-oriented rewriting copies unaffected lines verbatim without parsing and re-rendering the configuration.
 */
final class ConfigMigrator
{
    /** The one schema version this build reads; a migrated file naming another would be refused on the next run. */
    private const SCHEMA_VERSION = ConfigLoader::SCHEMA_VERSION;

    /**
     * Rewrite one configuration's text for the current schema.
     *
     * @param string $original - The whole 0.5 configuration file, as written.
     *
     * @return array{text: string, changes: list<string>} - Migrated text and change descriptions; text is $original when changes is empty.
     */
    public static function migrate(string $original): array
    {
        $lines    = explode("\n", $original);
        $migrated = [];
        $changes  = [];
        $index    = 0;
        $total    = count($lines);

        while ($index < $total) {
            $skipped = self::removedKeyBlockLength($lines, $index);

            // Removing the legacy key also discards its contiguous indented values.
            if ($skipped > 0) {
                $changes[] = sprintf('line %d: allowlists.secretPreviews removed; section 5 makes category markers unconditional', $index + 1);
                $index    += $skipped;
                self::dropEmptiedParent($migrated, $lines, $index);
                continue;
            }

            $renamed = self::renamedGateLine($lines, $index);


            if ($renamed !== null) {
                $changes[]  = sprintf('line %d: minimumSeverity: renamed to failOn:, the key that gates the exit code in 0.6', $index + 1);
                $migrated[] = $renamed;
                $index++;
                continue;
            }

            $migrated[] = $lines[$index];
            $index++;
        }

        return self::withSchemaVersion($migrated, $changes, $original);
    }

    /**
     * Report how many lines the removed redaction key occupies, counting any block indented beneath it.
     *
     * @param list<string> $lines - The whole file, split on newlines.
     * @param int          $index - The line being considered.
     *
     * @return int - the number of lines to drop, or 0 when this line is not the removed key
     */
    private static function removedKeyBlockLength(array $lines, int $index): int
    {
        // Only the nested allowlists entry is removed; no port ever wrote a root key of that name. The regex
        // requires leading whitespace, so it matches the nested key and never a root one.
        if (preg_match('/^(\s+)secretPreviews\s*:/', $lines[$index], $match) !== 1) {
            return 0;
        }

        $indent = strlen($match[1]);
        $length = 1;

        while (isset($lines[$index + $length]) && self::isDeeperThan($lines[$index + $length], $indent)) {
            $length++;
        }

        return $length;
    }

    /**
     * Rewrites a bare old gate key only when the next line is more deeply indented.
     *
     * @param list<string> $lines - The whole file, split on newlines.
     * @param int          $index - The line being considered.
     *
     * @return string|null - Rewritten key line, or null unless a bare key is followed by a more deeply indented line.
     */
    private static function renamedGateLine(array $lines, int $index): ?string
    {
        // Comments and keys with same-line values stay unchanged; the regex isolates bare keys for the indented-block check below.
        if (preg_match('/^(\s*)minimumSeverity\s*:\s*(.*)$/', $lines[$index], $match) !== 1 || trim($match[2]) !== '') {
            return null;
        }

        $indent = strlen($match[1]);

        // An empty block would be a key with nothing under it, which the loader reads as neither shape.
        return isset($lines[$index + 1]) && self::isDeeperThan($lines[$index + 1], $indent) ? $match[1] . 'failOn:' : null;
    }

    /**
     * Drop the block header the removal just emptied, because a key with nothing under it is not a valid mapping.
     *
     * Only a header whose last child was removed is dropped: if anything still belongs to the block, the header
     * stays exactly as the user wrote it.
     *
     * @param list<string> $migrated - The lines kept so far, whose tail may now be an emptied header; modified in place.
     * @param list<string> $lines    - The whole input, read ahead to see whether the block has any child left.
     * @param int          $index    - The first input line after the removed block.
     *
     * @return void - Nothing; the emptied header is removed from $migrated as a side effect.
     */
    private static function dropEmptiedParent(array &$migrated, array $lines, int $index): void
    {
        $header = $migrated === [] ? '' : $migrated[count($migrated) - 1];

        // A header is a bare `key:` with no value; the regex refuses a comment, a value, and an empty tail line.
        if (preg_match('/^\s*[^\s#][^:]*:\s*$/', $header) !== 1) {
            return;
        }

        $headerIndent = strlen($header) - strlen(ltrim($header));

        // A block that still has a child is not empty, so its header stays.
        if (isset($lines[$index]) && self::isDeeperThan($lines[$index], $headerIndent)) {
            return;
        }

        array_pop($migrated);
    }

    /**
     * Report whether a line belongs to a block opened at the given indent, so a blank line inside one does not end it.
     *
     * @param string $line   - One line of the file.
     * @param int    $indent - The indent the block was opened at.
     *
     * @return bool - true when the line is indented deeper than the block's own key
     */
    private static function isDeeperThan(string $line, int $indent): bool
    {
        return trim($line) !== '' && strlen($line) - strlen(ltrim($line)) > $indent;
    }

    /**
     * Pins the schema version, inserting it at the top when the 0.5 file has no top-level schemaVersion key.
     *
     * @param list<string> $lines    - The migrated lines so far.
     * @param list<string> $changes  - The rewrites applied so far.
     * @param string       $original - The file as written, returned unchanged when nothing needed rewriting.
     *
     * @return array{text: string, changes: list<string>} - Version-pinned text and accumulated changes; text is $original when changes is empty.
     */
    private static function withSchemaVersion(array $lines, array $changes, string $original): array
    {
        $pinned   = sprintf('schemaVersion: "%s"', self::SCHEMA_VERSION);
        $existing = null;

        foreach ($lines as $position => $line) {
            // Only an unindented schemaVersion key is eligible, which avoids rewriting an indented key with the same name.
            if (preg_match('/^schemaVersion\s*:/', $line) === 1) {
                $existing = $position;
                break;
            }
        }

        if ($existing === null) {
            $changes[] = sprintf('line 1: schemaVersion added as %s; every 0.6 loader requires it', self::SCHEMA_VERSION);
            array_unshift($lines, $pinned);
        } elseif (trim($lines[$existing]) !== $pinned) {
            $changes[] = sprintf('line %d: schemaVersion pinned to %s', $existing + 1, self::SCHEMA_VERSION);
            $lines[$existing] = $pinned;
        }

        return $changes === [] ? ['text' => $original, 'changes' => []] : ['text' => implode("\n", $lines), 'changes' => $changes];
    }
}
