<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use GruffPhp\Support\PathHelper;
use JsonException;

/**
 * Parses Infection JSON reports into gruff mutation report objects.
 *
 * @phpstan-type JsonScalar bool|float|int|string|null
 * @phpstan-type JsonValue JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar>>>>
 * @phpstan-type JsonObject array<string, JsonValue>
 */
final readonly class InfectionReportParser
{
    /**
     * Create a parser that resolves report paths relative to the project root.
     *
     * @param string $projectRoot Project root used to resolve report paths.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Parse an Infection JSON report from disk.
     *
     * @param string $path Infection report path to read.
     * @throws MutationReportException When the report is missing, unreadable, invalid JSON, or structurally invalid.
     * @return InfectionReport Parsed mutation report.
     */
    public function parse(string $path): InfectionReport
    {
        $resolvedPath = $this->resolvePath($path);
        $contents     = file_get_contents($resolvedPath);

        if ($contents === false) {
            throw new MutationReportException(sprintf('Unable to read Infection report: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MutationReportException(sprintf('Invalid Infection JSON report "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }

        $decoded = $this->requireJsonObject($decoded, sprintf('Infection report "%s" must be a JSON object.', $path));

        $stats   = $this->parseStats($decoded, $path);
        $mutants = [];

        foreach ($this->statusSections() as $section => $status) {
            $rows = $decoded[$section] ?? [];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new MutationReportException(sprintf('Infection report "%s" section "%s" must be a JSON array.', $path, $section));
            }

            foreach ($rows as $index => $row) {
                $mutants[] = $this->parseMutant($row, $status, sprintf('%s[%d]', $section, $index), $path);
            }
        }

        return new InfectionReport($this->displayPath($resolvedPath), $stats, $mutants);
    }

    /**
     * Resolve and validate a report path.
     *
     * @return string Absolute report path when realpath is available.
     */
    private function resolvePath(string $path): string
    {
        $candidate = PathHelper::resolveAgainst($this->projectRoot, $path);

        if (!is_file($candidate)) {
            throw new MutationReportException(sprintf('Infection report not found: %s', $path));
        }

        $realPath = realpath($candidate);

        return $realPath === false ? $candidate : $realPath;
    }

    /**
     * @param JsonObject $decoded
     * @return array<string, int|float>
     */
    private function parseStats(array $decoded, string $path): array
    {
        $rawStats = $decoded['stats'] ?? null;
        if (!is_array($rawStats) || array_is_list($rawStats)) {
            throw new MutationReportException(sprintf('Infection report "%s" must contain a "stats" object.', $path));
        }

        $stats = [];
        foreach ($rawStats as $key => $statValue) {
            if (!is_string($key) || (!is_int($statValue) && !is_float($statValue))) {
                throw new MutationReportException(sprintf('Infection report "%s" contains a non-numeric stats value.', $path));
            }

            $stats[$key] = $statValue;
        }

        foreach (['totalMutantsCount', 'msi', 'coveredCodeMsi', 'mutationCodeCoverage'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $stats)) {
                throw new MutationReportException(sprintf('Infection report "%s" is missing stats.%s.', $path, $requiredKey));
            }
        }

        return $stats;
    }

    /**
     * Parse one mutant row from an Infection status section.
     *
     * @return InfectionMutant Parsed mutant record.
     */
    private function parseMutant(mixed $mutantRecord, string $status, string $location, string $path): InfectionMutant
    {
        $mutantRecord  = $this->requireJsonObject($mutantRecord, sprintf('Infection report "%s" mutant %s must be a JSON object.', $path, $location));
        $mutator       = $this->requireMutatorObject($mutantRecord, $location, $path);
        $diff          = $mutantRecord['diff'] ?? null;
        $processOutput = $mutantRecord['processOutput'] ?? null;

        return new InfectionMutant(
            status:        $status,
            filePath:      $this->displayPath($this->requireMutatorString($mutator, 'originalFilePath', $location, $path)),
            line:          $this->optionalMutatorLine($mutator, $location, $path),
            mutator:       $this->requireMutatorString($mutator, 'mutatorName', $location, $path),
            diff:          is_string($diff) && $diff !== '' ? $diff : null,
            processOutput: is_string($processOutput) && $processOutput !== '' ? $processOutput : null,
        );
    }

    /**
     * Extract and validate the mutator object from one mutant row.
     *
     * @param JsonObject $mutantRecord
     * @return JsonObject
     */
    private function requireMutatorObject(array $mutantRecord, string $location, string $path): array
    {
        $mutator = $mutantRecord['mutator'] ?? null;
        return $this->requireJsonObject($mutator, sprintf('Infection report "%s" mutant %s must contain a mutator object.', $path, $location));
    }

    /**
     * Validate that a decoded Infection value is an object-like array.
     *
     * @return JsonObject
     */
    private function requireJsonObject(mixed $decodedValue, string $message): array
    {
        if (!is_array($decodedValue) || array_is_list($decodedValue)) {
            throw new MutationReportException($message);
        }

        $result = [];
        foreach ($decodedValue as $key => $item) {
            if (!is_string($key)) {
                throw new MutationReportException($message);
            }

            $result[$key] = $this->jsonValue($item);
        }

        return $result;
    }

    /**
     * Normalise one decoded Infection JSON value.
     *
     * @return JsonValue
     */
    private function jsonValue(mixed $decodedValue): array|bool|float|int|string|null
    {
        if (is_array($decodedValue)) {
            return $this->jsonArray($decodedValue);
        }

        return $this->jsonScalar($decodedValue);
    }

    /**
     * Validate scalar Infection JSON values after decoding.
     *
     * @return JsonScalar
     */
    private function jsonScalar(mixed $decodedValue): bool|float|int|string|null
    {
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            return $decodedValue;
        }

        throw new MutationReportException('Infection report contains a non-JSON value.');
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar>>>>
     */
    private function jsonArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->jsonArrayDepth2($item) : $this->jsonScalar($item);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar>>>
     */
    private function jsonArrayDepth2(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->jsonArrayDepth3($item) : $this->jsonScalar($item);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar>>
     */
    private function jsonArrayDepth3(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->jsonArrayDepth4($item) : $this->jsonScalar($item);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, JsonScalar>
     */
    private function jsonArrayDepth4(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            if (is_array($item)) {
                throw new MutationReportException('Infection report nesting is deeper than supported.');
            }

            $result[$key] = $this->jsonScalar($item);
        }

        return $result;
    }

    /**
     * @param JsonObject $mutator
     *
     * @return string Required mutator field value.
     */
    private function requireMutatorString(array $mutator, string $field, string $location, string $path): string
    {
        $fieldValue = $mutator[$field] ?? null;
        if (!is_string($fieldValue) || $fieldValue === '') {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s is missing mutator.%s.', $path, $location, $field));
        }

        return $fieldValue;
    }

    /**
     * @param JsonObject $mutator
     *
     * @return int|null Original start line, or null when absent.
     */
    private function optionalMutatorLine(array $mutator, string $location, string $path): ?int
    {
        $line = $mutator['originalStartLine'] ?? null;
        if ($line !== null && !is_int($line)) {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s has a non-integer mutator.originalStartLine.', $path, $location));
        }

        return $line;
    }

    /**
     * @return array<string, string>
     */
    private function statusSections(): array
    {
        return [
            'escaped' => 'escaped',
            'timeouted' => 'timed out',
            'killed' => 'killed',
            'killedByStaticAnalysis' => 'killed by SA',
            'errored' => 'error',
            'syntaxErrors' => 'syntax error',
            'uncovered' => 'not covered',
            'ignored' => 'ignored',
        ];
    }

    /**
     * Convert an absolute report path to a project-relative display path when possible.
     *
     * @return string Display path for report metadata.
     */
    private function displayPath(string $path): string
    {
        return PathHelper::relativeToRoot($path, $this->projectRoot) ?? PathHelper::canonical($path);
    }
}
