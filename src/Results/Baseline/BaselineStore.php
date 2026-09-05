<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\BaselineIdentity;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Support\PathHelper;
use JsonException;

/**
 * Reads, writes, and migrates the user's `gruff-baseline.json` under the family's baseline v3 contract.
 *
 * `analyse --generate-baseline` writes a file here; every later `analyse --baseline` reads it back through this class.
 * A 0.5 file (`gruff.baseline.v2`) fails closed with the migration command instead of being misread, because its rows were keyed differently.
 * `migrate()` is the only reader of that shape: it re-identifies the reviewed findings from the current scan and writes a separate v3 file.
 *
 * @phpstan-type BaselineJsonValue bool|float|int|string|null
 * @phpstan-type BaselineRow array<string, BaselineJsonValue>
 * @phpstan-type LegacyGroup array{file: string, ruleId: string, message: string, count: int}
 */
final readonly class BaselineStore
{
    /**
     * Schema tag every current baseline file must carry; a file that lacks it is rejected on read.
     */
    public const SCHEMA_VERSION = 'gruff.baseline.v3';

    /**
     * The 0.5 layout; only `migrate()` reads it, and `read()` points the user at that command.
     */
    public const LEGACY_SCHEMA_VERSION = 'gruff.baseline.v2';

    /**
     * The retired 0.4 layout; nothing reads it, so the user is asked to regenerate.
     */
    public const RETIRED_SCHEMA_VERSION = 'gruff.baseline.v1';

    /**
     * Conventional file name looked for at a project root when the user gives no explicit `--baseline` path.
     */
    public const DEFAULT_FILENAME = 'gruff-baseline.json';

    /**
     * The three keys the five 0.5 writers used for their row list; a file naming two of them cannot be read alike twice.
     */
    private const LEGACY_ROW_CONTAINERS = ['findings', 'groups', 'entries'];

    /**
     * The five ports a baseline may name; a typo cannot invent a sixth.
     */
    private const TOOL_LANGUAGES = ['go', 'php', 'py', 'rs', 'ts'];

    /**
     * Stored beside the sensitive counts so a reader learns why no sensitive occurrence has a row.
     */
    private const SENSITIVE_REASON = 'Sensitive findings are never baseline-eligible; a stored identity would be a durable suppression of a secret.';

    /**
     * Binds the store to one project so every relative baseline path resolves against the same root.
     *
     * @param string $projectRoot - Absolute project root that relative baseline paths are resolved against.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Loads and validates a v3 baseline, handing back its reviewed rows.
     *
     * Runs whenever the user passes `--baseline` or a `gruff-baseline.json` sits at the project root.
     * A 0.5 file stops the run here with the migration command, so it is never applied under the wrong identity rule.
     *
     * @param string $path - Baseline path to read, relative to the project root when needed.
     *
     * @return BaselineData - In-memory baseline carrying the source path, the writing port, one entry per validated row, and the sensitive counts.
     * @throws BaselineException When the file is missing, unreadable, malformed, a 0.5 or retired layout, or fails any v3 rule.
     */
    public function read(string $path): BaselineData
    {
        $decoded       = $this->decodeObject($path);
        $schemaVersion = $decoded['schemaVersion'] ?? null;

        // A 0.5 file gets the exact command that carries its reviews forward, never a silent misparse.
        if ($schemaVersion === self::LEGACY_SCHEMA_VERSION) {
            throw new BaselineException(sprintf(
                'Baseline schema "%s" is a 0.5 baseline. Migrate it to a separate file with `gruff-php analyse --migrate-baseline %s --generate-baseline <new path>`; the original is preserved.',
                self::LEGACY_SCHEMA_VERSION,
                $path,
            ));
        }

        // The retired 0.4 layout has no migration path, so the user regenerates from a reviewed scan.
        if ($schemaVersion === self::RETIRED_SCHEMA_VERSION) {
            throw new BaselineException(sprintf(
                'Baseline schema "%s" is no longer supported: baselines now store one line-free identity per reviewed finding. Regenerate with `gruff-php analyse --generate-baseline %s`.',
                self::RETIRED_SCHEMA_VERSION,
                $path,
            ));
        }

        // Anything else (a typo, a future schema) gets the plain expected-version message.
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new BaselineException(sprintf('Baseline schemaVersion must be "%s".', self::SCHEMA_VERSION));
        }

        $toolLanguage = $decoded['toolLanguage'] ?? null;

        // A file naming no port, or an unknown one, cannot say whose identities it holds.
        if (!is_string($toolLanguage) || !in_array($toolLanguage, self::TOOL_LANGUAGES, true)) {
            throw new BaselineException(sprintf('Baseline toolLanguage must name one of %s.', implode(', ', self::TOOL_LANGUAGES)));
        }

        $generatedAt = $decoded['generatedAt'] ?? null;

        // The timestamp is for readers only, but its absence marks a file no generator wrote.
        if (!is_string($generatedAt) || $generatedAt === '') {
            throw new BaselineException('Baseline generatedAt must be a non-empty timestamp.');
        }

        return new BaselineData(
            path:            $path,
            toolLanguage:    $toolLanguage,
            entries:         $this->entriesFromRows($decoded['occurrences'] ?? null),
            sensitiveByRule: $this->sensitiveCountsFrom($decoded['sensitive'] ?? null),
        );
    }

    /**
     * Reads a 0.5 baseline's reviewed groups for migration and nothing else.
     *
     * @param string $path - 0.5 baseline path, relative to the project root when needed.
     *
     * @return list<LegacyGroup> - Reviewed groups in file order; empty when the 0.5 file reviewed nothing.
     * @throws BaselineException When the file is not a well-formed 0.5 baseline.
     */
    public function readLegacy(string $path): array
    {
        $decoded = $this->decodeObject($path);

        // Only the 0.5 layout is migratable; a v3 file needs no migration and a retired one has no rows to carry.
        if (($decoded['schemaVersion'] ?? null) !== self::LEGACY_SCHEMA_VERSION) {
            throw new BaselineException(sprintf('Baseline %s is not a "%s" file, so there is nothing to migrate.', $path, self::LEGACY_SCHEMA_VERSION));
        }

        $this->requireOneRowContainer($decoded, $path);

        $groups = $decoded['groups'] ?? null;

        if (!is_array($groups) || !array_is_list($groups)) {
            throw new BaselineException('Baseline key "groups" must be a list.');
        }

        $legacyGroups = [];

        // Every row must carry the four fields the 0.5 writer produced, or the migration could carry a review it cannot place.
        foreach ($groups as $index => $group) {
            if (!is_array($group)) {
                throw new BaselineException(sprintf('Baseline group %d must be an object.', $index));
            }

            $file    = $group['file'] ?? null;
            $ruleId  = $group['ruleId'] ?? null;
            $message = $group['message'] ?? null;
            $count   = $group['count'] ?? null;

            if (!is_string($file) || !is_string($ruleId) || !is_string($message)) {
                throw new BaselineException(sprintf('Baseline group %d must carry string file, ruleId, and message fields.', $index));
            }

            if (!is_int($count) || $count < 1) {
                throw new BaselineException(sprintf('Baseline group %d field "count" must be an integer of at least 1.', $index));
            }

            $legacyGroups[] = ['file' => $file, 'ruleId' => $ruleId, 'message' => $message, 'count' => $count];
        }

        return $legacyGroups;
    }

    /**
     * Refuses to write a baseline over a 0.5 file at the shared default path.
     *
     * All five ports write and auto-discover the same filename, so without this an ordinary upgrade-then-generate
     * destroys the 0.5 baseline that is the user's documented retreat path, before they know they need it.
     * Regenerating v3 over v3 is not destructive, because v3 is what the tool now reads.
     *
     * @param string $outputPath - Destination the user asked to generate, relative to the project root or absolute.
     * @param bool   $shouldForce - True when the user passed --force and means to overwrite the older file.
     *
     * @return void
     * @throws BaselineException When the default path already holds a file this version would not read.
     */
    public function requireOverwritableDefaultPath(string $outputPath, bool $shouldForce): void
    {
        // Any other destination is the user's own choice of file, and any v3 file is what this version already reads.
        if ($shouldForce || basename($outputPath) !== self::DEFAULT_FILENAME) {
            return;
        }

        $absolutePath = $this->absolutePath($outputPath);

        // Nothing there to protect, which is the ordinary first-generate case.
        if (!is_file($absolutePath)) {
            return;
        }

        $contents = file_get_contents($absolutePath);
        $decoded  = $contents === false ? null : json_decode($contents, true);
        $schema   = is_array($decoded) ? ($decoded['schemaVersion'] ?? null) : null;

        if (!is_string($schema) || $schema === self::SCHEMA_VERSION) {
            return;
        }

        throw new BaselineException(sprintf(
            '%s is a "%s" baseline, not "%s"; generating over it would destroy the retreat path. Migrate it with '
            . '`gruff-php analyse --migrate-baseline %s --generate-baseline <new path>`, or pass --force to overwrite it.',
            $outputPath,
            $schema,
            self::SCHEMA_VERSION,
            $outputPath,
        ));
    }

    /**
     * Refuses a 0.5 input that names more than one recognised row container, which no two ports would read alike.
     *
     * The five 0.5 writers used three container keys: gruff-go and gruff-py wrote `findings`, gruff-php wrote `groups`,
     * and gruff-rs and gruff-ts wrote `entries`. A file carrying two of them migrates differently in different ports.
     *
     * @param array<string, mixed> $decoded - Decoded 0.5 root object.
     * @param string               $path - Path the user named, repeated in the error so they can find the file.
     *
     * @return void
     * @throws BaselineException When two or more of the three container keys are present.
     */
    private function requireOneRowContainer(array $decoded, string $path): void
    {
        $present = [];

        // One container is the supported case; two is an ambiguity nothing can resolve, so the file is refused.
        foreach (self::LEGACY_ROW_CONTAINERS as $container) {
            if (is_array($decoded[$container] ?? null)) {
                $present[] = $container;
            }
        }

        if (count($present) > 1) {
            throw new BaselineException(
                sprintf('Baseline %s carries more than one row container (%s); a migration input must name exactly one.', $path, implode(', ', $present)),
            );
        }
    }

    /**
     * Writes the current findings out as a fresh v3 baseline and returns exactly what was persisted.
     *
     * Backs `gruff-php analyse --generate-baseline path`; the write is atomic, so an interrupted run never leaves a half-written file.
     *
     * @param string        $path - Baseline path to write, relative to the project root when needed.
     * @param list<Finding> $findings - Findings to record; ordinary ones become identity rows with counts, sensitive ones become counts only.
     *
     * @return BaselineData - The baseline exactly as persisted, so callers can report what was written without re-reading the file.
     * @throws BaselineException When a finding cannot be identified or the file cannot be encoded or written.
     */
    public function write(string $path, array $findings): BaselineData
    {
        $document        = $this->documentFromFindings($findings);
        $writtenBaseline = new BaselineData($path, BaselineIdentity::TOOL_LANGUAGE, $document['entries'], $document['sensitiveByRule']);

        $this->persist($path, $writtenBaseline);

        return $writtenBaseline;
    }

    /**
     * Carries a 0.5 baseline's reviews into a separate v3 file, leaving the original byte-identical.
     *
     * Backs `gruff-php analyse --migrate-baseline old.json --generate-baseline new.json`.
     * Identities are rebuilt from the current scan, never translated from 0.5 rows, because the five 0.5 shapes disagreed on identity.
     *
     * @param string        $legacyPath - 0.5 baseline to read; never written to, renamed, or deleted.
     * @param string        $outputPath - Where the v3 file goes; refused when it is the input by spelling, link, or inode.
     * @param list<Finding> $findings - The current scan; a finding is carried across when a 0.5 row matches its file, rule, and message.
     *
     * @return BaselineMigration - The written baseline plus how many findings were carried and how many were sensitive.
     * @throws BaselineException When the paths are not distinct, the input is not a 0.5 file, or the input changed during the migration.
     */
    public function migrate(string $legacyPath, string $outputPath, array $findings): BaselineMigration
    {
        $legacyAbsolute = $this->absolutePath($legacyPath);
        $outputAbsolute = $this->absolutePath($outputPath);

        $this->requireDistinctPaths(legacyAbsolute: $legacyAbsolute, outputAbsolute: $outputAbsolute, legacyPath: $legacyPath, outputPath: $outputPath);

        $before = file_get_contents($legacyAbsolute);

        // The input is the user's retreat path; if it cannot even be read there is nothing safe to carry forward.
        if ($before === false) {
            throw new BaselineException(sprintf('Unable to read baseline file: %s', $legacyPath));
        }

        $legacyGroups = $this->readLegacy($legacyPath);
        $accepted     = [];

        // A current finding is carried across only when a 0.5 row covers its file, rule, and message; a shape that recorded less is not made to mean more.
        foreach ($findings as $finding) {
            foreach ($legacyGroups as $group) {
                if ($group['file'] === $finding->filePath && $group['ruleId'] === $finding->ruleId && $group['message'] === $finding->message) {
                    $accepted[] = $finding;
                    break;
                }
            }
        }

        $writtenBaseline = $this->write($outputPath, $accepted);

        // Prove the retreat path survived rather than assume it.
        if (file_get_contents($legacyAbsolute) !== $before) {
            throw new BaselineException(sprintf('Migration changed the 0.5 input %s; the retreat path is no longer intact.', $legacyPath));
        }

        return new BaselineMigration(writtenBaseline: $writtenBaseline, accepted: count($accepted), sensitiveCounted: $writtenBaseline->sensitiveTotal());
    }

    /**
     * Decodes a baseline file's JSON root, stopping on anything a generator would not have written.
     *
     * @param string $path - Baseline path to decode, relative to the project root when needed.
     *
     * @return array<string, mixed> - The decoded root object.
     * @throws BaselineException When the file is missing, unreadable, not JSON, or not an object.
     */
    private function decodeObject(string $path): array
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
            $decoded = json_decode($contents, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // A merge conflict marker or a truncated write is the usual cause; the JSON error names the byte.
            throw new BaselineException(sprintf('Invalid baseline JSON: %s', $exception->getMessage()), 0, $exception);
        }

        // Valid JSON that is not an object cannot be a baseline; fail before touching its keys.
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BaselineException('Baseline root must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded A non-list array decoded from an object has string keys. */
        return $decoded;
    }

    /**
     * Validates the `occurrences` list and turns it into rows, refusing anything that could expire, leak, or reorder.
     *
     * @param mixed $occurrences - Raw decoded `occurrences` value.
     *
     * @return list<BaselineEntry> - Rows in file order, which the file keeps ascending by identity.
     * @throws BaselineException When the list is missing, a row is invalid, or identities repeat or are out of order.
     */
    private function entriesFromRows(mixed $occurrences): array
    {
        if (!is_array($occurrences) || !array_is_list($occurrences)) {
            throw new BaselineException('Baseline key "occurrences" must be a list.');
        }

        $entries  = [];
        $previous = '';

        foreach ($occurrences as $index => $occurrenceRow) {
            if (!is_array($occurrenceRow)) {
                throw new BaselineException(sprintf('Baseline occurrences[%d] must be an object.', $index));
            }

            /** @var BaselineRow $occurrenceRow Scalar-or-null values are what the writer emits; fromArray re-checks each one it reads. */
            $validatedEntry = BaselineEntry::fromArray($occurrenceRow, $index);

            // A repeated identity would split one count across two rows, and an out-of-order file was not written by a generator.
            if ($validatedEntry->identity === $previous) {
                throw new BaselineException(sprintf('Baseline occurrences[%d] duplicates identity %s; counts must be merged into one entry.', $index, $validatedEntry->identity));
            }

            if ($validatedEntry->identity < $previous) {
                throw new BaselineException(sprintf('Baseline occurrences[%d] breaks the ascending identity order.', $index));
            }

            $entries[] = $validatedEntry;
            $previous  = $validatedEntry->identity;
        }

        return $entries;
    }

    /**
     * Validates the `sensitive` block, which must count without identifying.
     *
     * @param mixed $sensitive - Raw decoded `sensitive` value.
     *
     * @return array<string, int> - Sensitive counts by rule id.
     * @throws BaselineException When the block claims eligibility, carries an identifying key, or its total disagrees with its rows.
     */
    private function sensitiveCountsFrom(mixed $sensitive): array
    {
        if (!is_array($sensitive) || array_is_list($sensitive)) {
            throw new BaselineException('Baseline key "sensitive" must be an object.');
        }

        // Eligibility is stored rather than assumed, so a file claiming otherwise fails loudly instead of being trusted.
        if (($sensitive['eligible'] ?? null) !== false) {
            throw new BaselineException('Baseline sensitive.eligible must be false.');
        }

        // Any occurrence-level key under sensitive is a way a secret's location could reach disk.
        foreach (['identity', 'path', 'line', 'message', 'symbol', 'preview'] as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $sensitive)) {
                throw new BaselineException(sprintf('Baseline sensitive block carries forbidden key "%s".', $forbiddenKey));
            }
        }

        $counts = $sensitive['counts'] ?? null;

        if (!is_array($counts)) {
            throw new BaselineException('Baseline sensitive.counts must be an object.');
        }

        $byRule = $counts['byRule'] ?? null;

        if (!is_array($byRule)) {
            throw new BaselineException('Baseline sensitive.counts.byRule must be an object.');
        }

        $validated = [];
        $summed    = 0;

        foreach ($byRule as $ruleId => $count) {
            if (!is_string($ruleId) || !is_int($count) || $count < 0) {
                throw new BaselineException('Baseline sensitive.counts.byRule must map rule ids to non-negative integers.');
            }

            $validated[$ruleId] = $count;
            $summed            += $count;
        }

        // A total that disagrees with its rows means the file was edited by hand after it was written.
        if (($counts['total'] ?? null) !== $summed) {
            throw new BaselineException(sprintf('Baseline sensitive.counts.total must equal the byRule sum of %d.', $summed));
        }

        return $validated;
    }

    /**
     * Turns the current findings into identity rows plus sensitive counts.
     *
     * @param list<Finding> $findings - The scan being recorded.
     *
     * @return array{entries: list<BaselineEntry>, sensitiveByRule: array<string, int>} - Rows ascending by identity, and sensitive counts by rule.
     * @throws BaselineException When an ordinary finding cannot be identified.
     */
    private function documentFromFindings(array $findings): array
    {
        $ordinals        = BaselineIdentity::assignOrdinals($findings);
        $rowsByIdentity  = [];
        $sensitiveByRule = [];

        foreach ($findings as $finding) {
            // A sensitive finding contributes a count and nothing that could name it.
            if (!BaselineIdentity::isEligible($finding)) {
                $sensitiveByRule[$finding->ruleId] = ($sensitiveByRule[$finding->ruleId] ?? 0) + 1;
                continue;
            }

            $ordinal  = $ordinals[spl_object_id($finding)] ?? 0;
            $identity = BaselineIdentity::identityOf($finding, $ordinal);
            $existing = $rowsByIdentity[$identity] ?? null;

            // A second occurrence of one identity raises its count; it never becomes a second row.
            $rowsByIdentity[$identity] = $existing instanceof BaselineEntry
                ? new BaselineEntry($existing->identity, $existing->count + 1, $existing->ruleId, $existing->path, $existing->subject)
                : new BaselineEntry(
                    identity: $identity,
                    count:    1,
                    ruleId:   $finding->ruleId,
                    path:     $finding->filePath,
                    subject:  BaselineIdentity::subject($finding, $ordinal),
                );
        }

        ksort($rowsByIdentity, SORT_STRING);
        ksort($sensitiveByRule, SORT_STRING);

        return ['entries' => array_values($rowsByIdentity), 'sensitiveByRule' => $sensitiveByRule];
    }

    /**
     * Encodes a baseline as the v3 document and writes it atomically.
     *
     * @param string       $path - Baseline path to write, relative to the project root when needed.
     * @param BaselineData $writtenBaseline - The baseline to persist.
     *
     * @return void
     * @throws BaselineException When the directory cannot be created or the file cannot be encoded or written.
     */
    private function persist(string $path, BaselineData $writtenBaseline): void
    {
        $absolutePath = $this->absolutePath($path);
        $directory    = dirname($absolutePath);

        // Create the target directory on demand so `--generate-baseline path/to/file` just works.
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new BaselineException(sprintf('Unable to create baseline directory: %s', $directory));
        }

        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'toolLanguage'  => $writtenBaseline->toolLanguage,
            'generatedAt'   => gmdate('c'),
            'occurrences'   => array_map(static fn(BaselineEntry $entry): array => $entry->toArray(), $writtenBaseline->entries),
            'sensitive'     => [
                'eligible' => false,
                'reason'   => self::SENSITIVE_REASON,
                'counts'   => ['total' => $writtenBaseline->sensitiveTotal(), 'byRule' => (object)$writtenBaseline->sensitiveByRule],
            ],
        ];

        try {
            // Invalid UTF-8 in a descriptive field is substituted rather than failing the write; the identity itself is hex and never affected.
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BaselineException(sprintf('Unable to encode baseline: %s', $exception->getMessage()), 0, $exception);
        }

        $this->writeAtomically($absolutePath, $json . PHP_EOL, $path);
    }

    /**
     * Refuses an output that is the input by spelling, resolved link target, or inode.
     *
     * A spelling check alone lets a symlink turn an out-of-place migration into an in-place one and destroy the retreat path.
     *
     * @param string $legacyAbsolute - Resolved input path.
     * @param string $outputAbsolute - Resolved output path.
     * @param string $legacyPath - Input path as the user typed it, for the message.
     * @param string $outputPath - Output path as the user typed it, for the message.
     *
     * @return void
     * @throws BaselineException When the two paths name one file.
     */
    private function requireDistinctPaths(string $legacyAbsolute, string $outputAbsolute, string $legacyPath, string $outputPath): void
    {
        if ($legacyAbsolute === $outputAbsolute) {
            throw new BaselineException(sprintf('Migration input and output path are the same file: %s. Choose a separate output path.', $legacyPath));
        }

        // A missing output is the ordinary case: nothing exists to collide with.
        if (!file_exists($outputAbsolute)) {
            return;
        }

        $legacyReal = realpath($legacyAbsolute);
        $outputReal = realpath($outputAbsolute);

        // A link that resolves to the input, or two names for one inode, is the same file under another spelling.
        if (($legacyReal !== false && $legacyReal === $outputReal) || fileinode($legacyAbsolute) === fileinode($outputAbsolute)) {
            throw new BaselineException(sprintf('Migration output path resolves to the input path: %s. Choose a separate output path.', $outputPath));
        }
    }

    /**
     * Writes a baseline payload crash-safely, streaming it to a temp file and renaming that into place.
     *
     * @param string $absolutePath - Final on-disk destination the temporary file is renamed onto.
     * @param string $payload - Exact bytes to persist, written in full before the rename; partial writes raise.
     * @param string $displayPath - Project-relative path used only in error messages, never for filesystem access.
     *
     * @return void
     * @throws BaselineException When the temp file cannot be created, written, flushed, or renamed.
     */
    private function writeAtomically(string $absolutePath, string $payload, string $displayPath): void
    {
        $directory = dirname($absolutePath);
        $tempPath  = tempnam($directory, 'gruff-baseline-');

        // `tempnam` hands back false when the directory cannot take a new file; stop before touching the real baseline.
        if (!is_string($tempPath)) {
            throw new BaselineException(sprintf('Unable to create temporary baseline file: %s', $displayPath));
        }

        $handle = fopen($tempPath, 'wb');

        // No writable handle means nothing to stream into, so clear the stub temp file and report it.
        if ($handle === false) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
        }

        try {
            $offset = 0;
            $length = strlen($payload);

            // One `fwrite` may flush only part of a large baseline, so keep writing until every byte is out.
            while ($offset < $length) {
                $written = fwrite($handle, substr($payload, $offset));

                // A false or zero-byte write means the stream stalled; abort rather than leave a truncated baseline.
                if ($written === false || $written === 0) {
                    throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
                }

                $offset += $written;
            }

            if (fflush($handle) === false) {
                throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
            }

            // Force the bytes onto disk where the platform offers it, so a crash right after cannot lose the baseline.
            if (function_exists('fsync') && !fsync($handle)) {
                throw new BaselineException(sprintf('Unable to flush baseline file: %s', $displayPath));
            }
        } catch (BaselineException $exception) {
            fclose($handle);
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw $exception;
        }

        fclose($handle);

        // The rename is the atomic step: the real file is either the old baseline or the complete new one, never a partial write.
        if (!rename($tempPath, $absolutePath)) {
            $this->removeTemporaryFile($tempPath, $displayPath);
            throw new BaselineException(sprintf('Unable to replace baseline file: %s', $displayPath));
        }
    }

    /**
     * Removes a temp file left behind by a failed write, so a retry does not find the directory littered.
     *
     * @param string $tempPath - Temp file to remove.
     * @param string $displayPath - Project-relative path used only in the error message.
     *
     * @return void
     * @throws BaselineException When the temp file exists and cannot be removed.
     */
    private function removeTemporaryFile(string $tempPath, string $displayPath): void
    {
        if (is_file($tempPath) && !unlink($tempPath)) {
            throw new BaselineException(sprintf('Unable to remove temporary baseline file for: %s', $displayPath));
        }
    }

    /**
     * Anchors a relative baseline path to the store's project root; absolute paths pass through.
     *
     * @param string $path - Baseline path as the user typed it.
     *
     * @return string - Absolute path.
     */
    private function absolutePath(string $path): string
    {
        return PathHelper::resolveAgainst($this->projectRoot, $path);
    }
}
