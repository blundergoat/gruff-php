<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Support\PathHelper;
use JsonException;

/**
 * Reads and writes gruff baseline files relative to a project root.
 *
 * @phpstan-type BaselineJsonValue bool|float|int|string|null
 * @phpstan-type BaselineGroupRow array<string, BaselineJsonValue>
 * @phpstan-type BaselineFileData array{schemaVersion: string, groups: list<BaselineGroupRow>}
 */
final readonly class BaselineStore
{
    /**
     * Schema identifier required in persisted baseline files.
     */
    public const SCHEMA_VERSION = 'gruff.baseline.v2';

    /**
     * Retired per-finding fingerprint schema that fails closed with a regenerate instruction.
     */
    public const LEGACY_SCHEMA_VERSION = 'gruff.baseline.v1';

    /**
     * Conventional baseline file name discovered in project roots.
     */
    public const DEFAULT_FILENAME = 'gruff-baseline.json';

    /**
     * Create a baseline store rooted at the current project.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string $projectRoot - Project root used to resolve relative baseline paths.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Read and validate a baseline file.
     *
     * Runs whenever the user passes `--baseline` or a `gruff-baseline.json` sits at the
     * project root, e.g. `gruff-php analyse src --baseline gruff-baseline.json`.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string $path - Baseline path to read, relative to the project root when needed.
     *
     * @return BaselineData - in-memory baseline carrying the source path and one entry per validated group row
     */
    public function read(string $path): BaselineData
    {
        $decoded = $this->readBaselineObject($path);

        return new BaselineData($path, $this->entriesFromGroups($decoded['groups']));
    }

    /**
     * Decode the baseline JSON root and validate its schema envelope.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string $path - Baseline path to decode; a missing file, retired v1 schema, or bad schema throws BaselineException.
     *
     * @return BaselineFileData - validated envelope with the schema version pinned and every group row checked
     */
    private function readBaselineObject(string $path): array
    {
        $absolutePath = $this->absolutePath($path);
        // A missing file is a setup problem the user can fix; name the path they asked for.
        // User view: choose the baseline feedback branch for this case.
        if (!is_file($absolutePath)) {
            throw new BaselineException(sprintf('Baseline file not found: %s', $path));
        }

        $contents = file_get_contents($absolutePath);
        // Unreadable usually means permissions; surface it instead of pretending there is no baseline.
        // User view: choose the baseline feedback branch for this case.
        if ($contents === false) {
            throw new BaselineException(sprintf('Unable to read baseline file: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BaselineException(sprintf('Invalid baseline JSON: %s', $exception->getMessage()), 0, $exception);
        }

        // Valid JSON that is not an object cannot be a baseline; fail before touching its keys.
        // User view: choose the baseline feedback branch for this case.
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BaselineException('Baseline root must be a JSON object.');
        }

        // User view: missing data becomes a safe baseline feedback default.
        $schemaVersion = $decoded['schemaVersion'] ?? null;
        // An old v1 file fails closed with the exact command the user should run, never a silent misparse.
        // User view: choose the baseline feedback branch for this case.
        if ($schemaVersion === self::LEGACY_SCHEMA_VERSION) {
            throw new BaselineException(sprintf(
                'Baseline schema "%s" is no longer supported: baselines now group accepted findings by file, rule, and message. Regenerate with `gruff-php analyse --generate-baseline %s`.',
                self::LEGACY_SCHEMA_VERSION,
                $path,
            ));
        }

        // Anything else (a typo, a future schema) gets the plain expected-version message.
        // User view: choose the baseline feedback branch for this case.
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new BaselineException(sprintf('Baseline schemaVersion must be "%s".', self::SCHEMA_VERSION));
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            // User view: missing data becomes a safe baseline feedback default.
            'groups'        => $this->readGroupsList($decoded['groups'] ?? null),
        ];
    }

    /**
     * Read the groups list for the baseline workflow.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param mixed $groups - Raw decoded JSON groups key; anything but a list of scalar-keyed objects throws.
     *
     * @return list<BaselineGroupRow> - group rows in file order, each a string-keyed map of scalar-or-null values; empty when the source list was
     *                                empty
     */
    private function readGroupsList(mixed $groups): array
    {
        // The groups key must be a list; anything else means a hand edit broke the file shape.
        // User view: choose the baseline feedback branch for this case.
        if (!is_array($groups) || !array_is_list($groups)) {
            throw new BaselineException('Baseline key "groups" must be a list.');
        }

        $rows = [];

        // Vet each row so a later matching error can never be caused by malformed input.
        // User view: add each item that can appear in baseline feedback.
        foreach ($groups as $index => $group) {
            // Rows must be JSON objects; the index tells the user exactly which row to fix.
            // User view: choose the baseline feedback branch for this case.
            if (!is_array($group) || array_is_list($group)) {
                throw new BaselineException(sprintf('Baseline group %d must be a JSON object.', $index));
            }

            $baselineGroup = [];
            // Copy fields across, rejecting anything a baseline row has no business containing.
            // User view: add each item that can appear in baseline feedback.
            foreach ($group as $key => $value) {
                // Numeric keys signal a broken edit rather than a real field name.
                // User view: choose the baseline feedback branch for this case.
                if (!is_string($key)) {
                    throw new BaselineException(sprintf('Baseline group %d contains a non-string key.', $index));
                }

                // Nested structures cannot be part of a group row; only scalars and null are stored.
                // User view: choose the baseline feedback branch for this case.
                // User view: missing data becomes the expected baseline feedback state.
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
     * Build entries from group rows for the baseline workflow.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param list<BaselineGroupRow> $groups - Serialized group rows decoded from the baseline payload.
     *
     * @return list<BaselineEntry> - one entry per input row in file order; empty when no groups were supplied
     */
    private function entriesFromGroups(array $groups): array
    {
        $entries = [];
        // User view: add each item that can appear in baseline feedback.
        foreach ($groups as $index => $group) {
            $entries[] = BaselineEntry::fromArray($group, $index);
        }

        // One BaselineEntry per row, in file order; the index is carried so malformed rows can name their position.
        return $entries;
    }

    /**
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string        $path - Baseline path to write, relative to the project root when needed.
     * @param list<Finding> $findings - Findings to persist in the baseline.
     *
     * @return BaselineData - the baseline exactly as persisted, so callers can report what was written without re-reading the file
     * @throws BaselineException When the baseline file cannot be encoded or written.
     *
     */
    public function write(string $path, array $findings): BaselineData
    {
        $entries      = $this->groupEntriesFromFindings($findings);
        $baselineData = new BaselineData($path, $entries);
        $absolutePath = $this->absolutePath($path);
        $directory    = dirname($absolutePath);

        // Create the target directory on demand so `--generate-baseline path/to/file` just works.
        // User view: choose the baseline feedback branch for this case.
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
     * Aggregate live findings into deterministic baseline group rows.
     *
     * This shapes what `gruff-php analyse --generate-baseline` writes: the user commits one
     * compact row per accepted problem instead of one row per finding location.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param list<Finding> $findings - Findings to persist; instances sharing (file, ruleId, message) collapse into one row.
     *
     * @return list<BaselineEntry> - one row per group with its instance count, sorted by (file, ruleId, message) so
     *                             regenerated baselines diff cleanly regardless of finding order
     */
    private function groupEntriesFromFindings(array $findings): array
    {
        $groups = [];

        // Count identical findings into one row each; two eval calls in a file become one row with count 2.
        // User view: add each item that can appear in baseline feedback.
        foreach ($findings as $finding) {
            $groupKey = BaselineEntry::groupKeyForFinding($finding);
            // User view: missing data becomes a safe baseline feedback default.
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
     * Write a baseline payload via temporary file and atomic rename.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
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

        // User view: choose the baseline feedback branch for this case.
        if (!is_string($tempPath)) {
            throw new BaselineException(sprintf('Unable to create temporary baseline file: %s', $displayPath));
        }

        $handle = fopen($tempPath, 'wb');
        // User view: choose the baseline feedback branch for this case.
        if ($handle === false) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
        }

        try {
            $offset = 0;
            $length = strlen($payload);

            while ($offset < $length) {
                $written = fwrite($handle, substr($payload, $offset));

                // User view: choose the baseline feedback branch for this case.
                if ($written === false || $written === 0) {
                    throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
                }

                $offset += $written;
            }

            // User view: choose the baseline feedback branch for this case.
            if (fflush($handle) === false) {
                throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
            }

            // User view: choose the baseline feedback branch for this case.
            if (function_exists('fsync') && !fsync($handle)) {
                throw new BaselineException(sprintf('Unable to flush baseline file: %s', $displayPath));
            }
        } finally {
            fclose($handle);
        }

        $this->replaceBaselineFile($tempPath, $absolutePath, $displayPath);
    }

    /**
     * Move the temporary baseline into place, handling existing Windows targets.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string $tempPath - Source temporary file; removed before throwing if the move cannot complete.
     * @param string $absolutePath - Final destination; on Windows an existing target is unlinked before the rename.
     * @param string $displayPath - Project-relative path used only in error messages, never for filesystem access.
     *
     * @return void
     */
    private function replaceBaselineFile(string $tempPath, string $absolutePath, string $displayPath): void
    {
        // User view: choose the baseline feedback branch for this case.
        if (DIRECTORY_SEPARATOR === '\\' && is_file($absolutePath) && !unlink($absolutePath)) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to replace baseline file: %s', $displayPath));
        }

        // User view: choose the baseline feedback branch for this case.
        if (!rename($tempPath, $absolutePath)) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to replace baseline file: %s', $displayPath));
        }
    }

    /**
     * Remove a temporary baseline file after a failed write.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string $tempPath - Temporary file to delete; treated as already gone when it is not a file.
     * @param string $displayPath - Project-relative path used only in the error message if the unlink itself fails.
     *
     * @return void
     */
    private function removeTemporaryFile(string $tempPath, string $displayPath): void
    {
        // User view: choose the baseline feedback branch for this case.
        if (!is_file($tempPath)) {
            // Nothing to clean up: the temporary file was never created or was already moved into place.
            return;
        }

        // User view: choose the baseline feedback branch for this case.
        if (!unlink($tempPath)) {
            throw new BaselineException(sprintf('Unable to remove temporary baseline file: %s', $displayPath));
        }
    }

    /**
     * Resolve a path relative to the project root when needed.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string $path - Baseline path returned unchanged when already absolute, else joined to the project root.
     *
     * @return string - filesystem-absolute path: the input untouched when already absolute, else joined onto the project root
     */
    private function absolutePath(string $path): string
    {
        // Already-absolute paths pass through; relative ones are anchored to the store's project root.
        return PathHelper::resolveAgainst($this->projectRoot, $path);
    }
}
