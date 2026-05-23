<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

use GruffPhp\Finding\Finding;
use GruffPhp\Support\PathHelper;
use JsonException;

/**
 * Reads and writes gruff baseline files relative to a project root.
 *
 * @phpstan-type BaselineJsonValue bool|float|int|string|null
 * @phpstan-type BaselineFindingRow array<string, BaselineJsonValue>
 * @phpstan-type BaselineFileData array{schemaVersion: string, findings: list<BaselineFindingRow>}
 */
final readonly class BaselineStore
{
    /**
     * Schema identifier required in persisted baseline files.
     */
    public const SCHEMA_VERSION = 'gruff.baseline.v1';

    /**
     * Conventional baseline file name discovered in project roots.
     */
    public const DEFAULT_FILENAME = 'gruff-baseline.json';

    /**
     * Create a baseline store rooted at the current project.
     *
     * @param string $projectRoot Project root used to resolve relative baseline paths.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Read and validate a baseline file.
     *
     * @param string $path Baseline path to read, relative to the project root when needed.
     * @return BaselineData Parsed baseline data.
     */
    public function read(string $path): BaselineData
    {
        $decoded = $this->readBaselineObject($path);

        return new BaselineData($path, $this->entriesFromFindings($decoded['findings']));
    }

    /**
     * Decode the baseline JSON root and validate its schema envelope.
     *
     * @return BaselineFileData
     */
    private function readBaselineObject(string $path): array
    {
        $absolutePath = $this->absolutePath($path);
        if (!is_file($absolutePath)) {
            throw new BaselineException(sprintf('Baseline file not found: %s', $path));
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new BaselineException(sprintf('Unable to read baseline file: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BaselineException(sprintf('Invalid baseline JSON: %s', $exception->getMessage()), 0, $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BaselineException('Baseline root must be a JSON object.');
        }

        if (($decoded['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new BaselineException(sprintf('Baseline schemaVersion must be "%s".', self::SCHEMA_VERSION));
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'findings' => $this->readFindingsList($decoded['findings'] ?? null),
        ];
    }

    /**
     * Read findings list for the baseline workflow.
     *
     * @return list<BaselineFindingRow>
     */
    private function readFindingsList(mixed $findings): array
    {
        if (!is_array($findings) || !array_is_list($findings)) {
            throw new BaselineException('Baseline key "findings" must be a list.');
        }

        $rows = [];

        foreach ($findings as $index => $finding) {
            if (!is_array($finding) || array_is_list($finding)) {
                throw new BaselineException(sprintf('Baseline finding %d must be a JSON object.', $index));
            }

            $baselineFinding = [];
            foreach ($finding as $key => $value) {
                if (!is_string($key)) {
                    throw new BaselineException(sprintf('Baseline finding %d contains a non-string key.', $index));
                }

                if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value) && $value !== null) {
                    throw new BaselineException(sprintf('Baseline finding %d field "%s" must be a scalar or null.', $index, $key));
                }

                $baselineFinding[$key] = $value;
            }

            $rows[] = $baselineFinding;
        }

        return $rows;
    }

    /**
     * Build entries from findings for the baseline workflow.
     *
     * @param list<BaselineFindingRow> $findings
     * @return list<BaselineEntry>
     */
    private function entriesFromFindings(array $findings): array
    {
        $entries = [];
        foreach ($findings as $index => $finding) {
            $entries[] = BaselineEntry::fromArray($finding, $index);
        }

        return $entries;
    }

    /**
     * @param string        $path     Baseline path to write, relative to the project root when needed.
     * @param list<Finding> $findings Findings to persist in the baseline.
     * @throws BaselineException When the baseline file cannot be encoded or written.
     *
     * @return BaselineData Data written to disk.
     */
    public function write(string $path, array $findings): BaselineData
    {
        $entries = array_map(
            static fn (Finding $finding): BaselineEntry => BaselineEntry::fromFinding($finding),
            $findings,
        );
        $baselineData = new BaselineData($path, $entries);
        $absolutePath = $this->absolutePath($path);
        $directory    = dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new BaselineException(sprintf('Unable to create baseline directory: %s', $directory));
        }

        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'generatedAt' => gmdate('c'),
            'findings' => array_map(
                static fn (BaselineEntry $baselineEntry): array => $baselineEntry->toArray(),
                $entries,
            ),
        ];

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BaselineException(sprintf('Unable to encode baseline: %s', $exception->getMessage()), 0, $exception);
        }

        $this->writeAtomically($absolutePath, $json . PHP_EOL, $path);

        return $baselineData;
    }

    /**
     * Write a baseline payload via temporary file and atomic rename.
     *
     * @return void
     */
    private function writeAtomically(string $absolutePath, string $payload, string $displayPath): void
    {
        $directory = dirname($absolutePath);
        $tempPath  = tempnam($directory, 'gruff-baseline-');

        if (!is_string($tempPath)) {
            throw new BaselineException(sprintf('Unable to create temporary baseline file: %s', $displayPath));
        }

        $handle = fopen($tempPath, 'wb');
        if ($handle === false) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
        }

        try {
            $offset = 0;
            $length = strlen($payload);

            while ($offset < $length) {
                $written = fwrite($handle, substr($payload, $offset));

                if ($written === false || $written === 0) {
                    throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
                }

                $offset += $written;
            }

            if (fflush($handle) === false) {
                throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
            }

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
     * @return void
     */
    private function replaceBaselineFile(string $tempPath, string $absolutePath, string $displayPath): void
    {
        if (DIRECTORY_SEPARATOR === '\\' && is_file($absolutePath) && !unlink($absolutePath)) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to replace baseline file: %s', $displayPath));
        }

        if (!rename($tempPath, $absolutePath)) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to replace baseline file: %s', $displayPath));
        }
    }

    /**
     * Remove a temporary baseline file after a failed write.
     *
     * @return void
     */
    private function removeTemporaryFile(string $tempPath, string $displayPath): void
    {
        if (!is_file($tempPath)) {
            return;
        }

        if (!unlink($tempPath)) {
            throw new BaselineException(sprintf('Unable to remove temporary baseline file: %s', $displayPath));
        }
    }

    /**
     * Resolve a path relative to the project root when needed.
     *
     * @return string Absolute path.
     */
    private function absolutePath(string $path): string
    {
        return PathHelper::resolveAgainst($this->projectRoot, $path);
    }
}
