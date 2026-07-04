<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Support\PathHelper;
use JsonException;

/**
 * Turns the JSON report Infection writes after a mutation-testing run into the validated
 * `InfectionReport` gruff folds into a codebase's quality verdict. Reached when a user runs
 * `gruff-php analyse --infection-report=<path>` (optionally with `--infection-run` or a
 * `--mutation-baseline`): the file is read, then its `stats` block and every status section
 * (escaped, killed, timed out, and the rest) are checked key by key. The outcome is a complete
 * report or nothing - any malformed shape raises a `MutationReportException`, which the caller
 * downgrades to a single mutation-report-error note so one broken report never sinks the whole
 * analyse run.
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
     * `--infection-report=build/infection.json` lands inside their checkout rather than the filesystem root.
     *
     * @param string $projectRoot - Absolute project root that relative report paths are resolved against.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * The parser's front door: reads the report file the user pointed gruff at and hands back a fully
     * checked `InfectionReport`. Runs once per report whenever mutation analysis is enabled for an
     * `analyse` run, and once more for a `--mutation-baseline` when the user supplies one.
     *
     * @param string $path - Report path as the user supplied it, absolute or relative to the project root.
     *
     * @return InfectionReport - Fully validated report; only returned once every stats key and section row passed validation, so callers never see a
     *                         partial parse.
     * @throws MutationReportException When the report is missing, unreadable, invalid JSON, or structurally invalid.
     */
    public function parse(string $path): InfectionReport
    {
        $resolvedPath = $this->resolvePath($path);
        $contents     = file_get_contents($resolvedPath);

        // The file existed a moment ago but could not be read (permissions, or it vanished mid-run); name the report that failed.
        if ($contents === false) {
            throw new MutationReportException(sprintf('Unable to read Infection report: %s', $path));
        }

        // Decode strictly so a truncated or hand-edited report (say a run killed mid-write) fails loudly here, not deep in validation.
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // Invalid JSON is the user's report to fix, so wrap PHP's JSON-decoder message (Infection wrote the file but never parses it) with the offending path.
            throw new MutationReportException(sprintf('Invalid Infection JSON report "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }

        $decoded = $this->requireJsonObject($decoded, sprintf('Infection report "%s" must be a JSON object.', $path));

        $stats   = $this->parseStats($decoded, $path);
        $mutants = [];

        // Walk each Infection status bucket in turn (escaped, killed, timed out, and the rest) so every mutant lands under the status the user sees.
        foreach ($this->statusSections() as $section => $status) {
            $rows = $decoded[$section] ?? [];
            // A status key that isn't a JSON array means the report is malformed; refuse it rather than guess at the mutants inside.
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new MutationReportException(sprintf('Infection report "%s" section "%s" must be a JSON array.', $path, $section));
            }

            // Validate and convert every mutant row in this bucket, tagging each with its section and index for pinpoint error messages.
            foreach ($rows as $index => $row) {
                $mutants[] = $this->parseMutant($row, $status, sprintf('%s[%d]', $section, $index), $path);
            }
        }

        return new InfectionReport($this->displayPath($resolvedPath), $stats, $mutants);
    }

    /**
     * Turns the path the user typed into a concrete file and confirms it exists before any read is
     * attempted, so a wrong `--infection-report` fails with a clear "not found" instead of an empty read.
     *
     * @param string $path - Caller-supplied report path, absolute or relative to the project root.
     *
     * @return string - Canonical absolute path, or the unresolved candidate when realpath fails (e.g. broken symlink); the file is guaranteed to
     *                exist at return time.
     */
    private function resolvePath(string $path): string
    {
        $candidate = PathHelper::resolveAgainst($this->projectRoot, $path);

        // No file at the resolved path means the user pointed `--infection-report` somewhere wrong; stop with a plain not-found.
        if (!is_file($candidate)) {
            throw new MutationReportException(sprintf('Infection report not found: %s', $path));
        }

        $realPath = realpath($candidate);

        // Keep the unresolved candidate when realpath fails (e.g. broken symlink) instead of aborting.
        return $realPath === false ? $candidate : $realPath;
    }

    /**
     * Pulls the `stats` block out of the report and proves it carries the four headline numbers gruff
     * grades mutation testing on (total mutants, MSI, covered-code MSI, mutation coverage). This is where
     * a report from an incompatible Infection build is caught before its scores would reach the user.
     *
     * @param JsonObject $decoded - Decoded Infection report root object.
     * @param string     $path - Original report path, used only to label validation failures.
     *
     * @return array<string, int|float> - Report stats keyed by Infection metric name; guaranteed to include the four required MSI keys.
     */
    private function parseStats(array $decoded, string $path): array
    {
        $rawStats = $decoded['stats'] ?? null;
        // No `stats` object (or a JSON list where an object was expected) means these aren't Infection stats at all; reject the report.
        if (!is_array($rawStats) || array_is_list($rawStats)) {
            throw new MutationReportException(sprintf('Infection report "%s" must contain a "stats" object.', $path));
        }

        $stats = [];
        // Copy each metric across, but only once it looks like a real named numeric stat.
        foreach ($rawStats as $key => $statValue) {
            // A non-string metric name or a non-numeric value means the stats block is corrupt; refuse it rather than record a junk score.
            if (!is_string($key) || (!is_int($statValue) && !is_float($statValue))) {
                throw new MutationReportException(sprintf('Infection report "%s" contains a non-numeric stats value.', $path));
            }

            $stats[$key] = $statValue;
        }

        // Every headline metric gruff reports must be present; a report missing one would show the user a blank score.
        foreach (['totalMutantsCount', 'msi', 'coveredCodeMsi', 'mutationCodeCoverage'] as $requiredKey) {
            // Name the exact metric that's absent (e.g. `stats.msi`) so the user can see which figure their report lacks.
            if (!array_key_exists($requiredKey, $stats)) {
                throw new MutationReportException(sprintf('Infection report "%s" is missing stats.%s.', $path, $requiredKey));
            }
        }

        return $stats;
    }

    /**
     * Converts one raw row from a status section into the `InfectionMutant` the user eventually sees in
     * mutation output: its status, the file and line it touched, and the mutator that made the change.
     * Called once per row while `parse()` walks the report.
     *
     * @param mixed  $mutantRecord - Raw decoded row; must be a JSON object or validation rejects it.
     * @param string $status - Normalised mutant status already mapped from the section name.
     * @param string $location - Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string $path - Original report path, used only to label validation failures.
     *
     * @return InfectionMutant - One validated mutant carrying the normalised status, display file path, and start line; empty diff/processOutput
     *                         strings are collapsed to null so a blank and an absent value read the same to callers.
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
     * Fetches the nested `mutator` object from a mutant row - the record that says which mutation was
     * applied and where. A row missing it is malformed, so this doubles as a validation gate.
     *
     * @param JsonObject $mutantRecord - Already-validated mutant object to pull the "mutator" entry from.
     * @param string     $location - Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string     $path - Original report path, used only to label validation failures.
     *
     * @return JsonObject - The validated mutator sub-object for this mutant row.
     */
    private function requireMutatorObject(array $mutantRecord, string $location, string $path): array
    {
        $mutator = $mutantRecord['mutator'] ?? null;

        // A missing or non-object mutator key is a malformed report, so reuse the object guard to reject it.
        return $this->requireJsonObject($mutator, sprintf('Infection report "%s" mutant %s must contain a mutator object.', $path, $location));
    }

    /**
     * The report's object guard: accepts a decoded value only when it's a JSON object - a string-keyed
     * map, not a list or a scalar - and rebuilds it entry by entry. Reused everywhere a report node is
     * meant to be an object, so one bad node surfaces with the caller's own specific message.
     *
     * @param mixed  $decodedValue - Decoded value expected to be a string-keyed array, not a list or scalar.
     * @param string $message - Exception message thrown verbatim when the value is not an object.
     *
     * @return JsonObject - A freshly built string-keyed map containing only the shape-validated entries.
     */
    private function requireJsonObject(mixed $decodedValue, string $message): array
    {
        // Anything that isn't a keyed object - a scalar, or a JSON list - fails the shape the caller demanded here.
        if (!is_array($decodedValue) || array_is_list($decodedValue)) {
            throw new MutationReportException($message);
        }

        $result = [];
        // Rebuild the object one entry at a time so each value is narrowed to a supported JSON shape as it is copied.
        foreach ($decodedValue as $key => $item) {
            // A non-string key means an integer-keyed entry (a JSON object with numeric keys), not the string-keyed object the report needs; reject the whole node.
            if (!is_string($key)) {
                throw new MutationReportException($message);
            }

            $result[$key] = $this->jsonValue($item);
        }

        return $result;
    }

    /**
     * Routes a single decoded value down the right path: array values recurse through the depth-bounded
     * narrowing chain, while everything else must prove itself a permitted leaf scalar.
     *
     * @param mixed $decodedValue - One decoded value, routed to array narrowing or scalar validation by type.
     *
     * @return JsonValue - The value narrowed to the supported nested-array or leaf-scalar shape.
     */
    private function jsonValue(mixed $decodedValue): array|bool|float|int|string|null
    {
        // An array value can nest further, so hand it to the depth-bounded chain that walks each layer.
        if (is_array($decodedValue)) {
            return $this->jsonArray($decodedValue);
        }

        // A non-array value has to stand on its own as a leaf scalar, so validate it directly.
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
        // A recognised JSON scalar is exactly what we want here, so return it untouched.
        if (is_bool($decodedValue) || is_float($decodedValue) || is_int($decodedValue) || is_string($decodedValue) || $decodedValue === null) {
            return $decodedValue;
        }

        // Defensive guard: only scalars/null should reach here (arrays route away upstream), so a leftover value means the report wasn't the flat data gruff expects.
        throw new MutationReportException('Infection report contains a non-JSON value.');
    }

    /**
     * First rung of the nesting ladder: narrows a top-level report array, sending nested arrays one level
     * deeper and validating anything else as a scalar. Bounds how deep report data is allowed to nest.
     *
     * @param array<array-key, mixed> $values - Decoded JSON array at the first supported nesting level; empty when that report node held no entries.
     *
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar>>>> - the array
     *                          narrowed to the supported four-deep nesting shape.
     */
    private function jsonArray(array $values): array
    {
        $result = [];

        // At the first level, dispatch each entry: dig into a nested array, or validate a scalar in place.
        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->jsonArrayDepth2($item) : $this->jsonScalar($item);
        }

        return $result;
    }

    /**
     * Second rung: the same narrowing one level down, so a report may nest a value two arrays deep and
     * still be accepted. Keeps the recursion from wandering past what gruff can represent.
     *
     * @param array<array-key, mixed> $values - Decoded JSON array at the second supported nesting level; empty when that report node held no entries.
     *
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar|array<array-key, JsonScalar>>> - the second-level array narrowed to permit at
     *                          most three further nesting layers.
     */
    private function jsonArrayDepth2(array $values): array
    {
        $result = [];

        // Two levels in, keep dispatching: a deeper array recurses once more, a scalar is validated now.
        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->jsonArrayDepth3($item) : $this->jsonScalar($item);
        }

        return $result;
    }

    /**
     * Third rung: the last level that still permits a nested array beneath it - one more layer of the same
     * narrowing before the floor is reached.
     *
     * @param array<array-key, mixed> $values - Decoded JSON array at the third supported nesting level; empty when that report node held no entries.
     *
     * @return array<array-key, JsonScalar|array<array-key, JsonScalar>> - the third-level array narrowed to permit at most one further nested array.
     */
    private function jsonArrayDepth3(array $values): array
    {
        $result = [];

        // Three levels deep, one nesting layer remains: an array drops to the final level, a scalar validates here.
        foreach ($values as $key => $item) {
            $result[$key] = is_array($item) ? $this->jsonArrayDepth4($item) : $this->jsonScalar($item);
        }

        return $result;
    }

    /**
     * The floor of the ladder: at the fourth level every entry must be a flat scalar. A nested array here
     * is deeper than gruff supports, so it stops the parse rather than silently truncating the report.
     *
     * @param array<array-key, mixed> $values - Decoded JSON array at the final supported nesting level; empty when that report node held no entries.
     *
     * @return array<array-key, JsonScalar> - the deepest supported level, where every entry is a flat scalar.
     */
    private function jsonArrayDepth4(array $values): array
    {
        $result = [];

        // At the deepest allowed level, every entry has to bottom out as a scalar.
        foreach ($values as $key => $item) {
            // A further array means the report nests deeper than gruff can model; stop rather than drop the extra data.
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
     * @param JsonObject $mutator - Validated mutator object whose string field is being extracted.
     * @param string     $field - Mutator key to read, e.g. "mutatorName" or "originalFilePath".
     * @param string     $location - Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string     $path - Original report path, used only to label validation failures.
     *
     * @return string - The requested field's value, guaranteed non-empty; a missing or empty field throws rather than returning a blank string.
     */
    private function requireMutatorString(array $mutator, string $field, string $location, string $path): string
    {
        $fieldValue = $mutator[$field] ?? null;
        // Missing or blank where a real value is required, so reject it rather than report a mutant with an empty field.
        if (!is_string($fieldValue) || $fieldValue === '') {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s is missing mutator.%s.', $path, $location, $field));
        }

        return $fieldValue;
    }

    /**
     * Reads the optional source line a mutant sat on. Unlike the required fields, a genuinely absent line
     * is fine and yields null; only a present-but-wrong value is treated as a broken report.
     *
     * @param JsonObject $mutator - Validated mutator object that may carry an "originalStartLine" entry.
     * @param string     $location - Section-and-index label (e.g. "escaped[3]") used in error messages.
     * @param string     $path - Original report path, used only to label validation failures.
     *
     * @return int|null - The originalStartLine when present; null means the report simply omitted it (an expected miss, not a parse failure), while
     *                  a non-integer value throws.
     */
    private function optionalMutatorLine(array $mutator, string $location, string $path): ?int
    {
        $line = $mutator['originalStartLine'] ?? null;
        // A present line that isn't an integer is corrupt data; a missing line is allowed, so only the wrong-type case throws.
        if ($line !== null && !is_int($line)) {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s has a non-integer mutator.originalStartLine.', $path, $location));
        }

        // Null is a valid result here: it means the report omitted the start line, not that parsing failed.
        return $line;
    }

    /**
     * The lookup that maps Infection's raw section names to the human-readable statuses gruff prints,
     * turning `timeouted` into "timed out" and `killedByStaticAnalysis` into "killed by SA".
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
     * mutation output reads like `src/Foo.php` rather than a long absolute path.
     *
     * @param string $path - Absolute or canonical filesystem path to render for display.
     *
     * @return string - Project-relative path for readable output, falling back to the canonical absolute path when the file sits outside the project
     *                root.
     */
    private function displayPath(string $path): string
    {
        // Prefer a project-relative path for readable output; fall back to canonical when outside the root.
        return PathHelper::relativeToRoot($path, $this->projectRoot) ?? PathHelper::canonical($path);
    }
}
