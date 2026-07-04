<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Support\PathHelper;
use JsonException;

/**
 * Reads and writes the `gruff-baseline.json` file that records findings a user has accepted as known debt.
 *
 * A baseline lets a team adopt gruff on a messy codebase without drowning in pre-existing findings:
 * `gruff-php analyse --generate-baseline` writes one compact row per accepted problem, and later scans
 * read it back so those known issues stay silent while genuinely new ones still surface. This store owns
 * both halves - schema-validating and decoding an existing file on read, and atomically writing a fresh
 * one on generate - and fails loudly with a fix-it message whenever a baseline is missing, hand-edited
 * into an invalid shape, or written in the retired v1 layout.
 *
 * @phpstan-type BaselineJsonValue bool|float|int|string|null
 * @phpstan-type BaselineGroupRow array<string, BaselineJsonValue>
 * @phpstan-type BaselineFileData array{schemaVersion: string, groups: list<BaselineGroupRow>}
 */
final readonly class BaselineStore
{
    /**
     * Schema tag every current baseline file must carry; a file that lacks it is rejected on read.
     */
    public const SCHEMA_VERSION = 'gruff.baseline.v2';

    /**
     * The retired v1 layout; reading one fails closed with a regenerate instruction rather than misparsing it.
     */
    public const LEGACY_SCHEMA_VERSION = 'gruff.baseline.v1';

    /**
     * Conventional file name looked for at a project root when the user gives no explicit `--baseline` path.
     */
    public const DEFAULT_FILENAME = 'gruff-baseline.json';

    /**
     * Binds the store to one project so every relative baseline path resolves against the same root.
     *
     * @param string $projectRoot - Absolute project root that relative baseline paths are resolved against.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Loads and validates a baseline file, handing back its accepted findings as in-memory entries.
     *
     * Runs whenever the user passes `--baseline` or a `gruff-baseline.json` sits at the project
     * root, e.g. `gruff-php analyse src --baseline gruff-baseline.json`; a bad or outdated file
     * stops the run here with a fix-it message instead of silently ignoring the baseline.
     *
     * @param string $path - Baseline path to read, relative to the project root when needed.
     *
     * @return BaselineData - in-memory baseline carrying the source path and one entry per validated group row.
     */
    public function read(string $path): BaselineData
    {
        $decoded = $this->readBaselineObject($path);

        return new BaselineData($path, $this->entriesFromGroups($decoded['groups']));
    }

    /**
     * Decodes the baseline file's JSON root and checks its schema envelope before any rows are trusted.
     *
     * The gate `read()` leans on: a missing file, unreadable file, non-object root, retired v1 layout,
     * or wrong schema version each stops here with a message the user can act on.
     *
     * @param string $path - Baseline path to decode; a missing file, retired v1 schema, or bad schema throws BaselineException.
     *
     * @return BaselineFileData - validated envelope with the schema version pinned and every group row checked.
     */
    private function readBaselineObject(string $path): array
    {
        $absolutePath = $this->absolutePath($path);
        // A missing file is a setup problem the user can fix; name the path they asked for.
        if (!is_file($absolutePath)) {
            throw new BaselineException(sprintf('Baseline file not found: %s', $path));
        }

        $contents = file_get_contents($absolutePath);
        // Unreadable usually means permissions; surface it instead of pretending there is no baseline.
        if ($contents === false) {
            throw new BaselineException(sprintf('Unable to read baseline file: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BaselineException(sprintf('Invalid baseline JSON: %s', $exception->getMessage()), 0, $exception);
        }

        // Valid JSON that is not an object cannot be a baseline; fail before touching its keys.
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BaselineException('Baseline root must be a JSON object.');
        }

        $schemaVersion = $decoded['schemaVersion'] ?? null;
        // An old v1 file fails closed with the exact command the user should run, never a silent misparse.
        if ($schemaVersion === self::LEGACY_SCHEMA_VERSION) {
            throw new BaselineException(sprintf(
                'Baseline schema "%s" is no longer supported: baselines now group accepted findings by file, rule, and message. Regenerate with `gruff-php analyse --generate-baseline %s`.',
                self::LEGACY_SCHEMA_VERSION,
                $path,
            ));
        }

        // Anything else (a typo, a future schema) gets the plain expected-version message.
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new BaselineException(sprintf('Baseline schemaVersion must be "%s".', self::SCHEMA_VERSION));
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'groups'        => $this->readGroupsList($decoded['groups'] ?? null),
        ];
    }

    /**
     * Reads and vets the `groups` array, so a hand-edited baseline can never feed malformed rows into matching.
     *
     * @param mixed $groups - Raw decoded JSON groups key; anything but a list of scalar-keyed objects throws.
     *
     * @return list<BaselineGroupRow> - group rows in file order, each a string-keyed map of scalar-or-null values; empty when the source list was
     *                                empty.
     */
    private function readGroupsList(mixed $groups): array
    {
        // The groups key must be a list; anything else means a hand edit broke the file shape.
        if (!is_array($groups) || !array_is_list($groups)) {
            throw new BaselineException('Baseline key "groups" must be a list.');
        }

        $rows = [];

        // Vet each row so a later matching error can never be caused by malformed input.
        foreach ($groups as $index => $group) {
            // Rows must be JSON objects; the index tells the user exactly which row to fix.
            if (!is_array($group) || array_is_list($group)) {
                throw new BaselineException(sprintf('Baseline group %d must be a JSON object.', $index));
            }

            $baselineGroup = [];
            // Copy fields across, rejecting anything a baseline row has no business containing.
            foreach ($group as $key => $value) {
                // Numeric keys signal a broken edit rather than a real field name.
                if (!is_string($key)) {
                    throw new BaselineException(sprintf('Baseline group %d contains a non-string key.', $index));
                }

                // Nested structures cannot be part of a group row; only scalars and null are stored.
                if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value) && $value !== null) {
                    throw new BaselineException(sprintf('Baseline group %d field "%s" must be a scalar or null.', $index, $key));
                }

                $baselineGroup[$key] = $value;
            }

            $rows[] = $baselineGroup;
        }

        return $rows;
    }

    /**
     * Turns validated group rows into `BaselineEntry` objects the rest of the run can match findings against.
     *
     * @param list<BaselineGroupRow> $groups - Serialized group rows decoded from the baseline payload.
     *
     * @return list<BaselineEntry> - one entry per input row in file order; empty when no groups were supplied.
     */
    private function entriesFromGroups(array $groups): array
    {
        $entries = [];
        // Turn every validated row into a `BaselineEntry`, handing `fromArray` the row's index so a bad row can still name its own position.
        foreach ($groups as $index => $group) {
            $entries[] = BaselineEntry::fromArray($group, $index);
        }

        // Entries come back in the file's own order - nothing is re-sorted here, so the baseline reads back as it was written.
        return $entries;
    }

    /**
     * Writes the accepted findings out as a fresh baseline file and returns exactly what was persisted.
     *
     * Backs `gruff-php analyse --generate-baseline path`; the write is atomic, so an interrupted run
     * never leaves a half-written `gruff-baseline.json` for the user to untangle.
     *
     * @param string        $path - Baseline path to write, relative to the project root when needed.
     * @param list<Finding> $findings - Findings to persist; instances sharing (file, ruleId, message) collapse into one counted row.
     *
     * @return BaselineData - the baseline exactly as persisted, so callers can report what was written without re-reading the file.
     * @throws BaselineException When the baseline file cannot be encoded or written.
     */
    public function write(string $path, array $findings): BaselineData
    {
        $entries      = $this->groupEntriesFromFindings($findings);
        $baselineData = new BaselineData($path, $entries);
        $absolutePath = $this->absolutePath($path);
        $directory    = dirname($absolutePath);

        // Create the target directory on demand so `--generate-baseline path/to/file` just works.
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new BaselineException(sprintf('Unable to create baseline directory: %s', $directory));
        }

        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'generatedAt'   => gmdate('c'),
            'groups'        => array_map(
                static fn(BaselineEntry $baselineEntry): array => $baselineEntry->toArray(),
                $entries,
            ),
        ];

        try {
            // JSON_INVALID_UTF8_SUBSTITUTE keeps persisted values symmetric with BaselineEntry's group-key
            // substitution, so a finding whose message carried invalid bytes still matches after a round trip.
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BaselineException(sprintf('Unable to encode baseline: %s', $exception->getMessage()), 0, $exception);
        }

        $this->writeAtomically($absolutePath, $json . PHP_EOL, $path);

        return $baselineData;
    }

    /**
     * Collapses live findings into deterministic baseline rows, one per accepted problem.
     *
     * This shapes what `gruff-php analyse --generate-baseline` writes: the user commits one compact
     * row per problem with an instance count, instead of one row per finding location.
     *
     * @param list<Finding> $findings - Findings to persist; instances sharing (file, ruleId, message) collapse into one row.
     *
     * @return list<BaselineEntry> - one row per group with its instance count, sorted by (file, ruleId, message) so
     *                             regenerated baselines diff cleanly regardless of finding order; empty when no findings were supplied.
     */
    private function groupEntriesFromFindings(array $findings): array
    {
        $groups = [];

        // Count identical findings into one row each; two eval calls in a file become one row with count 2.
        foreach ($findings as $finding) {
            $groupKey = BaselineEntry::groupKeyForFinding($finding);
            $existing = $groups[$groupKey] ?? null;

            $groups[$groupKey] = $existing instanceof BaselineEntry
                ? new BaselineEntry($existing->filePath, $existing->ruleId, $existing->message, $existing->count + 1)
                : new BaselineEntry(
                    filePath: $finding->filePath,
                    ruleId:   $finding->ruleId,
                    message:  $finding->message,
                    count:    1,
                );
        }

        $entries = array_values($groups);
        usort(
            $entries,
            static fn(BaselineEntry $left, BaselineEntry $right): int => [$left->filePath, $left->ruleId, $left->message]
                <=> [$right->filePath, $right->ruleId, $right->message],
        );

        return $entries;
    }

    /**
     * Writes a baseline payload crash-safely, streaming it to a temp file and renaming that into place.
     *
     * @param string $absolutePath - Final on-disk destination the temporary file is renamed onto.
     * @param string $payload - Exact bytes to persist, written in full before the rename; partial writes raise.
     * @param string $displayPath - Project-relative path used only in error messages, never for filesystem access.
     *
     * @return void
     */
    private function writeAtomically(string $absolutePath, string $payload, string $displayPath): void
    {
        $directory = dirname($absolutePath);
        $tempPath  = tempnam($directory, 'gruff-baseline-');

        // `tempnam` hands back false when the directory can't take a new file; stop before writing over the real baseline.
        if (!is_string($tempPath)) {
            throw new BaselineException(sprintf('Unable to create temporary baseline file: %s', $displayPath));
        }

        $handle = fopen($tempPath, 'wb');
        // No writable handle means there is nothing to stream into, so clear the stub temp file and report it.
        if ($handle === false) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
        }

        try {
            $offset = 0;
            $length = strlen($payload);

            // Loop the writes because one `fwrite` may only flush part of a large baseline before returning.
            while ($offset < $length) {
                $written = fwrite($handle, substr($payload, $offset));

                // A false or zero-byte write means the stream stalled; abort rather than leave a truncated baseline.
                if ($written === false || $written === 0) {
                    throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
                }

                $offset += $written;
            }

            // Flush PHP's own buffer so every byte has reached the operating system before we trust the file.
            if (fflush($handle) === false) {
                throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
            }

            // Force the bytes onto physical disk where the platform offers it, so a crash right after can't lose the baseline.
            if (function_exists('fsync') && !fsync($handle)) {
                throw new BaselineException(sprintf('Unable to flush baseline file: %s', $displayPath));
            }
        } finally {
            fclose($handle);
        }

        $this->replaceBaselineFile($tempPath, $absolutePath, $displayPath);
    }

    /**
     * Swaps the finished temp file onto the real baseline path, clearing an existing Windows target first.
     *
     * @param string $tempPath - Source temporary file; removed before throwing if the move cannot complete.
     * @param string $absolutePath - Final destination; on Windows an existing target is unlinked before the rename.
     * @param string $displayPath - Project-relative path used only in error messages, never for filesystem access.
     *
     * @return void
     */
    private function replaceBaselineFile(string $tempPath, string $absolutePath, string $displayPath): void
    {
        // On Windows `rename` refuses to overwrite, so delete the previous baseline first before swapping the new one in.
        if (DIRECTORY_SEPARATOR === '\\' && is_file($absolutePath) && !unlink($absolutePath)) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to replace baseline file: %s', $displayPath));
        }

        // If the atomic rename fails the new baseline never lands; drop the temp file so no half-written copy is left behind.
        if (!rename($tempPath, $absolutePath)) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to replace baseline file: %s', $displayPath));
        }
    }

    /**
     * Deletes the temp file left behind when a write fails, so a broken run leaves no litter in the project.
     *
     * @param string $tempPath - Temporary file to delete; treated as already gone when it is not a file.
     * @param string $displayPath - Project-relative path used only in the error message if the unlink itself fails.
     *
     * @return void
     */
    private function removeTemporaryFile(string $tempPath, string $displayPath): void
    {
        // Nothing to clean up when the temporary file was never created, or was already renamed into place.
        if (!is_file($tempPath)) {
            return;
        }

        // The temp file is still on disk but refuses to delete; surface the leftover litter rather than swallow it silently.
        if (!unlink($tempPath)) {
            throw new BaselineException(sprintf('Unable to remove temporary baseline file: %s', $displayPath));
        }
    }

    /**
     * Resolves a baseline path against the project root, so users can pass either a relative or an absolute path.
     *
     * @param string $path - Baseline path returned unchanged when already absolute, else joined to the project root.
     *
     * @return string - filesystem-absolute path: the input untouched when already absolute, else joined onto the project root.
     */
    private function absolutePath(string $path): string
    {
        // Already-absolute paths pass through; relative ones are anchored to the store's project root.
        return PathHelper::resolveAgainst($this->projectRoot, $path);
    }
}
