<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

use GruffPhp\Finding\Finding;
use JsonException;

/**
 * Reads and writes gruff baseline files relative to a project root.
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
        $findings = $this->readFindingsList($decoded);

        return new BaselineData($path, $this->entriesFromFindings($findings));
    }

    /**
     * @return array<mixed>
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

        return $decoded;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<mixed>
     */
    private function readFindingsList(array $decoded): array
    {
        $findings = $decoded['findings'] ?? null;
        if (!is_array($findings) || !array_is_list($findings)) {
            throw new BaselineException('Baseline key "findings" must be a list.');
        }

        return $findings;
    }

    /**
     * @param list<mixed> $findings
     * @return list<BaselineEntry>
     */
    private function entriesFromFindings(array $findings): array
    {
        $entries = [];
        foreach ($findings as $index => $finding) {
            if (!is_array($finding) || array_is_list($finding)) {
                throw new BaselineException(sprintf('Baseline finding %d must be a JSON object.', $index));
            }

            /** @var array<string, mixed> $finding The preceding guards prove each decoded list item is a JSON object. */
            $entries[] = BaselineEntry::fromArray($finding, $index);
        }

        return $entries;
    }

    /**
     * @param string $path Baseline path to write, relative to the project root when needed.
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
        $data = new BaselineData($path, $entries);
        $absolutePath = $this->absolutePath($path);
        $directory = dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new BaselineException(sprintf('Unable to create baseline directory: %s', $directory));
        }

        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'generatedAt' => gmdate('c'),
            'findings' => array_map(
                static fn (BaselineEntry $entry): array => $entry->toArray(),
                $entries,
            ),
        ];

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BaselineException(sprintf('Unable to encode baseline: %s', $exception->getMessage()), 0, $exception);
        }

        $this->writeAtomically($absolutePath, $json . PHP_EOL, $path);

        return $data;
    }

    /**
     * Write a baseline payload via temporary file and atomic rename.
     *
     * @return void No return value.
     */
    private function writeAtomically(string $absolutePath, string $payload, string $displayPath): void
    {
        $directory = dirname($absolutePath);
        $tempPath = tempnam($directory, 'gruff-baseline-');

        if (!is_string($tempPath)) {
            throw new BaselineException(sprintf('Unable to create temporary baseline file: %s', $displayPath));
        }

        $handle = fopen($tempPath, 'wb');
        if ($handle === false) {
            @unlink($tempPath);
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

        if (!rename($tempPath, $absolutePath)) {
            @unlink($tempPath);
            throw new BaselineException(sprintf('Unable to replace baseline file: %s', $displayPath));
        }
    }

    /**
     * Resolve a path relative to the project root when needed.
     *
     * @return string Absolute path.
     */
    private function absolutePath(string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($this->projectRoot, '/') . '/' . $path;
    }
}
