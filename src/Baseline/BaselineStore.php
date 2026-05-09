<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

use GruffPhp\Finding\Finding;
use JsonException;

final readonly class BaselineStore
{
    public const SCHEMA_VERSION = 'gruff.baseline.v1';
    public const DEFAULT_FILENAME = 'gruff-baseline.json';

    public function __construct(private string $projectRoot)
    {
    }

    public function read(string $path): BaselineData
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

        $findings = $decoded['findings'] ?? null;
        if (!is_array($findings) || !array_is_list($findings)) {
            throw new BaselineException('Baseline key "findings" must be a list.');
        }

        $entries = [];
        foreach ($findings as $index => $finding) {
            if (!is_array($finding) || array_is_list($finding)) {
                throw new BaselineException(sprintf('Baseline finding %d must be a JSON object.', $index));
            }

            /** @var array<string, mixed> $finding */
            $entries[] = BaselineEntry::fromArray($finding, $index);
        }

        return new BaselineData($path, $entries);
    }

    /**
     * @param list<Finding> $findings
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

        if (file_put_contents($absolutePath, $json . PHP_EOL) === false) {
            throw new BaselineException(sprintf('Unable to write baseline file: %s', $path));
        }

        return $data;
    }

    private function absolutePath(string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($this->projectRoot, '/') . '/' . $path;
    }
}
