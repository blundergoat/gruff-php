<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use GruffPhp\Support\PathHelper;
use JsonException;

/**
 * Parses Infection JSON reports into gruff mutation report objects.
 *
 * @phpstan-type JsonScalar bool|float|int|string|null
 * @phpstan-type JsonValue JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key,
 *               JsonScalar>>>>
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
     *
     * @return InfectionReport - fully validated report; only returned once every stats key and section row passed validation, so callers never see a
     *                         partial parse
     * @throws MutationReportException When the report is missing, unreadable, invalid JSON, or structurally invalid.
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

        // Assemble the report only after every section validated; partial reports are never returned.
        return new InfectionReport($this->displayPath($resolvedPath), $stats, $mutants);
    }

    /**
     * Resolve and validate a report path.
     *
     * @param string $path Caller-supplied report path, absolute or relative to the project root.
     *
     * @return string - canonical absolute path, or the unresolved candidate when realpath fails (e.g. broken symlink); the file is guaranteed to
     *                exist at return time
     */
    private function resolvePath(string $path): string
    {
        $candidate = PathHelper::resolveAgainst($this->projectRoot, $path);

        if (!is_file($candidate)) {
            throw new MutationReportException(sprintf('Infection report not found: %s', $path));
        }

        $realPath = realpath($candidate);

        // Keep the unresolved candidate when realpath fails (e.g. broken symlink) instead of aborting.
        return $realPath === false ? $candidate : $realPath;
    }

    /**
     * Parse stats for the mutation report parser.
     *
     * @param JsonObject $decoded
     * @param string     $path Original report path, used only to label validation failures.
     *
     * @return array<string, int|float> - report stats keyed by Infection metric name; guaranteed to include the four required MSI keys
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

        // Hand back the numeric stats only once all four required MSI keys are confirmed present.
        return $stats;
    }

    /**
     * Parse one mutant row from an Infection status section.
     *
     * @param mixed  $mutantRecord Raw decoded row; must be a JSON object or validation rejects it.
     * @param string $status       Normalised mutant status already mapped from the section name.
     * @param string $location     Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string $path         Original report path, used only to label validation failures.
     *
     * @return InfectionMutant - one validated mutant carrying the normalised status, display file path, and start line; empty diff/processOutput
     *                         strings are collapsed to null
     */
    private function parseMutant(mixed $mutantRecord, string $status, string $location, string $path): InfectionMutant
    {
        $mutantRecord  = $this->requireJsonObject($mutantRecord, sprintf('Infection report "%s" mutant %s must be a JSON object.', $path, $location));
        $mutator       = $this->requireMutatorObject($mutantRecord, $location, $path);
        $diff          = $mutantRecord['diff'] ?? null;
        $processOutput = $mutantRecord['processOutput'] ?? null;

        // Empty diff/processOutput strings collapse to null so callers can treat absent and blank alike.
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
     * @param JsonObject $mutantRecord Already-validated mutant object to pull the "mutator" entry from.
     * @param string     $location     Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string     $path         Original report path, used only to label validation failures.
     *
     * @return JsonObject - the validated mutator sub-object for this mutant row
     */
    private function requireMutatorObject(array $mutantRecord, string $location, string $path): array
    {
        $mutator = $mutantRecord['mutator'] ?? null;

        // A missing or non-object mutator key is a malformed report, so reuse the object guard to reject it.
        return $this->requireJsonObject($mutator, sprintf('Infection report "%s" mutant %s must contain a mutator object.', $path, $location));
    }

    /**
     * Validate that a decoded Infection value is an object-like array.
     *
     * @param mixed  $decodedValue Decoded value expected to be a string-keyed array, not a list or scalar.
     * @param string $message      Exception message thrown verbatim when the value is not an object.
     *
     * @return JsonObject - a freshly built string-keyed map containing only the shape-validated entries
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

        // Return a freshly built map so only string-keyed, shape-validated entries reach the caller.
        return $result;
    }

    /**
     * Normalise one decoded Infection JSON value.
     *
     * @param mixed $decodedValue One decoded value, routed to array narrowing or scalar validation by type.
     *
     * @return JsonValue - the value narrowed to the supported nested-array or leaf-scalar shape
     */
    private function jsonValue(mixed $decodedValue): array|bool|float|int|string|null
    {
        if (is_array($decodedValue)) {
            // Arrays recurse through the depth-bounded narrowing chain.
            return $this->jsonArray($decodedValue);
        }

        // Everything else must validate as a leaf scalar before it is accepted.
        return $this->jsonScalar($decodedValue);
    }

    /**
     * Validate scalar Infection JSON values after decoding.
     *
     * @param mixed $decodedValue Value that must already be a bool, int, float, string, or null; else throws.
     *
     * @return JsonScalar - the value unchanged once proven to be a permitted bool, int, float, string, or null
     */
    private function jsonScalar(mixed $decodedValue): bool|float|int|string|null
    {
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            // Pass the value straight through once it is proven to be a permitted JSON scalar.
            return $decodedValue;
        }

        throw new MutationReportException('Infection report contains a non-JSON value.');
    }

    /**
     * Keep decoded mutation-report values within the supported nested JSON shape.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar>>>> - the array
     *                          narrowed to the supported four-deep nesting shape
     */
    private function jsonArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->jsonArrayDepth2($item) : $this->jsonScalar($item);
        }

        // Top-level array narrowed to the supported four-deep JSON shape; deeper nesting is rejected later.
        return $result;
    }

    /**
     * Keep second-level mutation-report values within the supported JSON shape.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar>>> - the second-level array narrowed to permit at
     *                          most three further nesting layers
     */
    private function jsonArrayDepth2(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->jsonArrayDepth3($item) : $this->jsonScalar($item);
        }

        // Second level narrowed; its return type permits one fewer nesting layer than the top-level shape.
        return $result;
    }

    /**
     * Keep third-level mutation-report values within the supported JSON shape.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar>> - the third-level array narrowed to permit at most one further nested array
     */
    private function jsonArrayDepth3(array $values): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->jsonArrayDepth4($item) : $this->jsonScalar($item);
        }

        // Third level narrowed; the next recursion is the last that may still hold a nested array.
        return $result;
    }

    /**
     * Keep fourth-level mutation-report values as scalar JSON values.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, JsonScalar> - the deepest supported level, where every entry is a flat scalar
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

        // Deepest supported level: every entry is now a scalar, since any array here already threw.
        return $result;
    }

    /**
     * @param JsonObject $mutator  Validated mutator object whose string field is being extracted.
     * @param string     $field    Mutator key to read, e.g. "mutatorName" or "originalFilePath".
     * @param string     $location Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string     $path     Original report path, used only to label validation failures.
     *
     * @return string - the requested field's value, guaranteed non-empty; a missing or empty field throws rather than returning a blank string
     */
    private function requireMutatorString(array $mutator, string $field, string $location, string $path): string
    {
        $fieldValue = $mutator[$field] ?? null;
        if (!is_string($fieldValue) || $fieldValue === '') {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s is missing mutator.%s.', $path, $location, $field));
        }

        // Reached only past the guard, so the value is a guaranteed non-empty string for the mutant record.
        return $fieldValue;
    }

    /**
     * @param JsonObject $mutator  Validated mutator object that may carry an "originalStartLine" entry.
     * @param string     $location Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string     $path     Original report path, used only to label validation failures.
     *
     * @return int|null - the originalStartLine when present; null means the report simply omitted it (an expected miss, not a parse failure), while
     *                  a non-integer value throws
     */
    private function optionalMutatorLine(array $mutator, string $location, string $path): ?int
    {
        $line = $mutator['originalStartLine'] ?? null;
        if ($line !== null && !is_int($line)) {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s has a non-integer mutator.originalStartLine.', $path, $location));
        }

        // Null is a valid result here: it means the report omitted the start line, not that parsing failed.
        return $line;
    }

    /**
     * Map Infection status section keys to normalised mutant statuses.
     *
     * @return array<string, string> - map from Infection JSON section name to the human-facing status gruff reports
     */
    private function statusSections(): array
    {
        // Keys are Infection's JSON section names; values are the human-facing statuses gruff reports.
        return [
            'escaped'                => 'escaped',
            'timeouted'              => 'timed out',
            'killed'                 => 'killed',
            'killedByStaticAnalysis' => 'killed by SA',
            'errored'                => 'error',
            'syntaxErrors'           => 'syntax error',
            'uncovered'              => 'not covered',
            'ignored'                => 'ignored',
        ];
    }

    /**
     * Convert an absolute report path to a project-relative display path when possible.
     *
     * @param string $path Absolute or canonical filesystem path to render for display.
     *
     * @return string - project-relative path for readable output, falling back to the canonical absolute path when the file sits outside the project
     *                root
     */
    private function displayPath(string $path): string
    {
        // Prefer a project-relative path for readable output; fall back to canonical when outside the root.
        return PathHelper::relativeToRoot($path, $this->projectRoot) ?? PathHelper::canonical($path);
    }
}
