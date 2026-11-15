<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Support\PathHelper;
use JsonException;

/**
 * Validates Infection JSON statistics and mutant records before they contribute to mutation analysis.
 * Used for the supplied report and an optional mutation baseline.
 *
 * Invalid reports throw MutationReportException. MutationAnalysisBuilder records a mutation-report-error diagnostic
 * and omits the mutation result instead of returning partially validated data.
 *
 * @phpstan-type JsonScalar bool|float|int|string|null
 * @phpstan-type JsonValue JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key,
 *               JsonScalar>>>>
 * @phpstan-type JsonObject array<string, JsonValue>
 */
final readonly class InfectionReportParser
{
    /**
     * Binds the parser to the project root every report path is resolved against, so a user's relative
     * --infection-report=build/infection.json lands inside their checkout rather than the filesystem root.
     *
     * @param string $projectRoot - Absolute project root that relative report paths are resolved against.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Reads and validates the report file supplied for mutation analysis or a mutation baseline.
     * Returns an InfectionReport only after validating the statistics and every mutant.
     *
     * @param string $path - Report path as the user supplied it, absolute or relative to the project root.
     *
     * @return InfectionReport - Validated statistics and mutants; a validation failure throws before any report is returned.
     * @throws MutationReportException - When the report is missing, unreadable, invalid JSON, or structurally invalid.
     */
    public function parse(string $path): InfectionReport
    {
        $resolvedPath = $this->resolvePath($path);
        $contents     = file_get_contents($resolvedPath);

        // The file existed a moment ago but could not be read (permissions, or it vanished mid-run); name the report that failed.
        if ($contents === false) {
            throw new MutationReportException(sprintf('Unable to read Infection report: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // Include the report path with the decoder error so the caller can identify which input failed.
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
     * Turns the path the user typed into a concrete file and confirms it exists before any read is
     * attempted, so a wrong --infection-report fails with a clear "not found" instead of a generic read failure.
     *
     * @param string $path - Caller-supplied report path, absolute or relative to the project root.
     *
     * @return string - Canonical path when available; otherwise the resolved candidate that passed the file check.
     */
    private function resolvePath(string $path): string
    {
        $candidate = PathHelper::resolveAgainst($this->projectRoot, $path);

        // The resolved path is not an accessible regular file, so report the caller-supplied path as not found.
        if (!is_file($candidate)) {
            throw new MutationReportException(sprintf('Infection report not found: %s', $path));
        }

        $realPath = realpath($candidate);

        return $realPath === false ? $candidate : $realPath;
    }

    /**
     * Requires numeric total mutant count, MSI, covered-code MSI, and mutation-coverage values before scoring or reporting.
     * Preserves any other numeric statistics from the report.
     *
     * @param JsonObject $decoded - Decoded Infection report root object.
     * @param string     $path    - Original report path, used only to label validation failures.
     *
     * @return array<string, int|float> - Required mutation metrics and any other numeric report stats, keyed by metric name.
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
     * Converts one raw status-section row into a validated mutant used for report totals and survivor output.
     *
     * @param mixed  $mutantRecord - Raw decoded row; must be a JSON object or validation rejects it.
     * @param string $status       - Normalised mutant status already mapped from the section name.
     * @param string $location     - Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string $path         - Original report path, used only to label validation failures.
     *
     * @return InfectionMutant - Validated mutant; absent, empty, or non-string diff and processOutput values become null.
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
     * Fetches the nested mutator object from a mutant row - the record that says which mutation was
     * applied and where. A row missing it is malformed, so this doubles as a validation gate.
     *
     * @param JsonObject $mutantRecord - Already-validated mutant object to pull the "mutator" entry from.
     * @param string     $location     - Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string     $path         - Original report path, used only to label validation failures.
     *
     * @return JsonObject - The validated mutator sub-object for this mutant row.
     */
    private function requireMutatorObject(array $mutantRecord, string $location, string $path): array
    {
        $mutator = $mutantRecord['mutator'] ?? null;

        return $this->requireJsonObject($mutator, sprintf('Infection report "%s" mutant %s must contain a mutator object.', $path, $location));
    }

    /**
     * The report's object guard: accepts a decoded value only when it's a JSON object - a string-keyed
     * map, not a list or a scalar - and rebuilds it entry by entry. Reused everywhere a report node is
     * meant to be an object, so one bad node surfaces with the caller's own specific message.
     *
     * @param mixed  $decodedValue - Decoded value expected to be a string-keyed array, not a list or scalar.
     * @param string $message      - Exception message thrown verbatim when the value is not an object.
     *
     * @return JsonObject - A freshly built string-keyed map containing only the shape-validated entries.
     */
    private function requireJsonObject(mixed $decodedValue, string $message): array
    {
        if (!is_array($decodedValue) || array_is_list($decodedValue)) {
            throw new MutationReportException($message);
        }

        $result = [];
        foreach ($decodedValue as $key => $item) {
            // Numeric JSON object keys may decode as integers; report objects require named fields.
            if (!is_string($key)) {
                throw new MutationReportException($message);
            }

            $result[$key] = $this->jsonValue($item);
        }

        return $result;
    }

    /**
     * Narrows a decoded value to a supported scalar or an array bounded by the report's nesting limit.
     *
     * @param mixed $decodedValue - One decoded value, routed to array narrowing or scalar validation by type.
     *
     * @return JsonValue - The value narrowed to the supported nested-array or leaf-scalar shape.
     */
    private function jsonValue(mixed $decodedValue): array|bool|float|int|string|null
    {
        if (is_array($decodedValue)) {
            return $this->jsonArray($decodedValue);
        }

        return $this->jsonScalar($decodedValue);
    }

    /**
     * The leaf check every value ultimately reaches: passes bools, ints, floats, strings, and null
     * straight through, and rejects anything else as not valid report JSON.
     *
     * @param mixed $decodedValue - Value that must already be a bool, int, float, string, or null; else throws.
     *
     * @return JsonScalar - The value unchanged once proven to be a permitted bool, int, float, string, or null.
     */
    private function jsonScalar(mixed $decodedValue): bool|float|int|string|null
    {
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            return $decodedValue;
        }

        throw new MutationReportException('Infection report contains a non-JSON value.');
    }

    /**
     * Narrows a report array and rejects data nested beyond four array levels.
     *
     * @param array<array-key, mixed> $values - Decoded JSON array at the first supported nesting level; empty when that report node held no entries.
     *
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar>>>> - the array
     *                          narrowed to the supported four-deep nesting shape.
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
     * Narrows the second array level while allowing two further array levels beneath it.
     *
     * @param array<array-key, mixed> $values - Decoded JSON array at the second supported nesting level; empty when that report node held no entries.
     *
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar>>> - Up to two further array levels.
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
     * Narrows the third array level while allowing one final nested array.
     *
     * @param array<array-key, mixed> $values - Decoded JSON array at the third supported nesting level; empty when that report node held no entries.
     *
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar>> - the third-level array narrowed to permit at most one further nested array.
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
     * Narrows the final supported array level; another nested array raises a report error.
     *
     * @param array<array-key, mixed> $values - Decoded JSON array at the final supported nesting level; empty when that report node held no entries.
     *
     * @return array<array-key, JsonScalar> - the deepest supported level, where every entry is a flat scalar.
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
     * Reads one required string field off a mutator - its name, or the file it changed - insisting it be
     * present and non-empty so the user never sees a mutant labelled with a blank mutator or path.
     *
     * @param JsonObject $mutator  - Validated mutator object whose string field is being extracted.
     * @param string     $field    - Mutator key to read, e.g. "mutatorName" or "originalFilePath".
     * @param string     $location - Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string     $path     - Original report path, used only to label validation failures.
     *
     * @return string - The requested field's value, guaranteed non-empty; a missing or empty field throws rather than returning a blank string.
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
     * Reads the optional source line; absent or null fields stay null, while any other non-integer value rejects the report.
     *
     * @param JsonObject $mutator  - Validated mutator object that may carry an "originalStartLine" entry.
     * @param string     $location - Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string     $path     - Original report path, used only to label validation failures.
     *
     * @return int|null - Source line when supplied; null for an absent or null field, while any other non-integer value throws.
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
     * The lookup that maps Infection's raw section names to the human-readable statuses gruff prints,
     * turning "timeouted" into "timed out" and "killedByStaticAnalysis" into "killed by SA".
     *
     * @return array<string, string> - Map from Infection JSON section name to the human-facing status gruff reports.
     */
    private function statusSections(): array
    {
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
     * Shortens a stored file path for display, preferring a path relative to the user's project root so
     * mutation output reads like src/Foo.php rather than a long absolute path.
     *
     * @param string $path - Absolute or canonical filesystem path to render for display.
     *
     * @return string - Project-relative display path, or the canonical path when the file is outside the root.
     */
    private function displayPath(string $path): string
    {
        return PathHelper::relativeToRoot($path, $this->projectRoot) ?? PathHelper::canonical($path);
    }
}
