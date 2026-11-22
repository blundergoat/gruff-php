<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\BaselineIdentity;
use GruffPhp\Results\Finding\DeclarationInventory;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Support\PathHelper;
use JsonException;

/**
 * Reads, writes, and migrates `gruff-baseline.json` using the family baseline v3 contract.
 * Use `analyse --generate-baseline` to record reviewed findings and `analyse --baseline` to apply that review.
 *
 * Older v2 files need `--migrate-baseline` because their groups use different identifiers.
 * Migration matches accepted counts against current findings and writes a separate v3 file.
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
     * Legacy row-container names checked for ambiguous array-valued payloads before PHP reads its `groups` list.
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
     * Runs whenever the user passes --baseline or a gruff-baseline.json sits at the project root.
     * A 0.5 file stops the run here with the migration command, so it is never applied under the wrong identity rule.
     *
     * @param string $path - Baseline path to read, relative to the project root when needed.
     *
     * @return BaselineData - Validated rows and sensitive counts with the supplied path and writing port.
     * @throws BaselineException - When the file is missing, unreadable, malformed, unsupported, or fails a v3 validation rule.
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

        // The retired layout stores no identity this port can translate, and --migrate-baseline reads only v2, so the
        // only route that works is a fresh baseline; the advice names --force because the default filename needs it.
        if ($schemaVersion === self::RETIRED_SCHEMA_VERSION) {
            throw new BaselineException(sprintf(
                'Baseline schema "%s" is no longer supported: baselines now store one line-free identity per reviewed finding, and a v1 file\'s reviews cannot be carried forward. Regenerate it from a reviewed scan with `gruff-php analyse --generate-baseline <new path>`, or in place with `gruff-php analyse --generate-baseline %s --force`.',
                self::RETIRED_SCHEMA_VERSION,
                $path,
            ));
        }


        // A baseline with another schema cannot match reviewed findings using the current identity rules.
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new BaselineException(sprintf('Baseline schemaVersion must be "%s".', self::SCHEMA_VERSION));
        }

        $toolLanguage = $decoded['toolLanguage'] ?? null;

        // A file naming no port, or an unknown one, cannot say whose identities it holds.
        if (!is_string($toolLanguage) || !in_array($toolLanguage, self::TOOL_LANGUAGES, true)) {
            throw new BaselineException(sprintf('Baseline toolLanguage must name one of %s.', implode(', ', self::TOOL_LANGUAGES)));
        }

        $generatedAt = $decoded['generatedAt'] ?? null;


        // A timestamp lets the caller trace when the accepted findings were recorded.
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
     * @throws BaselineException - When the file is not a well-formed 0.5 baseline.
     */
    public function readLegacy(string $path): array
    {
        return $this->legacyGroupsFrom($this->decodeObject($path), $path);
    }

    /**
     * Validates the decoded PHP v2 occurrence groups.
     *
     * @param array<string, mixed> $decoded - Legacy document.
     * @param string               $path    - Input path shown in errors.
     * @return list<array{file: string, ruleId: string, message: string, count: int}> - Reviewed occurrence budgets.
     */
    private function legacyGroupsFrom(array $decoded, string $path): array
    {
        // Only v2 is accepted for migration; v3 needs no migration and v1 reviews cannot be translated here.
        if (($decoded['schemaVersion'] ?? null) !== self::LEGACY_SCHEMA_VERSION) {

            $retiredAdvice = ($decoded['schemaVersion'] ?? null) === self::RETIRED_SCHEMA_VERSION
                ? ' A "' . self::RETIRED_SCHEMA_VERSION . '" file\'s reviews cannot be carried forward; regenerate it with `gruff-php analyse --generate-baseline <new path>`.'
                : '';

            throw new BaselineException(sprintf('Baseline %s is not a "%s" file, so there is nothing to migrate.%s', $path, self::LEGACY_SCHEMA_VERSION, $retiredAdvice));
        }

        $this->requireOneRowContainer($decoded, $path);

        $groups = $decoded['groups'] ?? null;

        // PHP's older format stores reviewed counts in a groups list.
        if (!is_array($groups) || !array_is_list($groups)) {
            throw new BaselineException('Baseline key "groups" must be a list.');
        }

        $legacyGroups = [];

        // Every row must carry the four fields the 0.5 writer produced, or the migration could carry a review it cannot place.
        foreach ($groups as $index => $group) {
            // Every reviewed group needs named fields because its position is used in migration errors.
            if (!is_array($group)) {
                throw new BaselineException(sprintf('Baseline group %d must be an object.', $index));
            }

            $file    = $group['file'] ?? null;
            $ruleId  = $group['ruleId'] ?? null;
            $message = $group['message'] ?? null;
            $count   = $group['count'] ?? null;

            // A legacy group must identify the exact file, rule, and message before its count can carry forward.
            if (!is_string($file) || !is_string($ruleId) || !is_string($message)) {
                throw new BaselineException(sprintf('Baseline group %d must carry string file, ruleId, and message fields.', $index));
            }

            // Reject zero, fractional, or absent counts because they cannot represent accepted occurrences.
            if (!is_int($count) || $count < 1) {
                throw new BaselineException(sprintf('Baseline group %d field "count" must be an integer of at least 1.', $index));
            }

            // A 0.5 group recorded under a retired id, e.g. "docs.missing-public-phpdoc", matches the finding the renamed rule now emits.
            $legacyGroups[] = ['file' => $file, 'ruleId' => RuleRegistry::canonicalRuleId($ruleId), 'message' => $message, 'count' => $count];
        }

        return $legacyGroups;
    }

    /**
     * Protects an existing default-named baseline unless it is valid v3 or overwrite is forced.
     *
     * @param string $outputPath  - Destination the user asked to generate, relative to the project root or absolute.
     * @param bool   $shouldForce - True when the user passed --force and means to overwrite the older file.
     *
     * @return void - Returns when this guard permits generation at the requested destination.
     * @throws BaselineException - When an unforced default-named file cannot be read as v3.
     */
    public function requireOverwritableDefaultPath(string $outputPath, bool $shouldForce): void
    {
        // Custom filenames and explicit force leave overwrite responsibility with the caller.
        if ($shouldForce || basename($outputPath) !== self::DEFAULT_FILENAME) {
            return;
        }

        $absolutePath = $this->absolutePath($outputPath);


        // A new default destination has no earlier review file to protect.
        if (!file_exists($absolutePath) && !is_link($absolutePath)) {
            return;
        }

        $contents = is_file($absolutePath) && is_readable($absolutePath) ? file_get_contents($absolutePath) : false;
        $decoded  = $contents === false ? null : json_decode($contents, true);
        $schema   = is_array($decoded) ? ($decoded['schemaVersion'] ?? null) : null;

        // Validate the entire current-format file before allowing callers to overwrite it without --force.
        if ($schema === self::SCHEMA_VERSION) {
            $this->read($outputPath);
            return;
        }

        // Preserve unreadable or malformed review history unless the caller explicitly chooses to overwrite it.
        if (!is_string($schema)) {
            throw new BaselineException(sprintf('Cannot verify the existing baseline %s. Choose a new output path or use --force to overwrite it.', $outputPath));
        }

        // Only a v2 file can be migrated; advising --migrate-baseline for any other schema sends the user to a refusal.
        $advice = $schema === self::LEGACY_SCHEMA_VERSION
            ? sprintf('Migrate it with `gruff-php analyse --migrate-baseline %s --generate-baseline <new path>`, or pass --force to overwrite it.', $outputPath)
            : sprintf('Its reviews cannot be carried forward, so regenerate it in place with `gruff-php analyse --generate-baseline %s --force`, or generate to a new path.', $outputPath);

        throw new BaselineException(sprintf(
            '%s is a "%s" baseline, not "%s"; generating over it would destroy the retreat path. %s',
            $outputPath,
            $schema,
            self::SCHEMA_VERSION,
            $advice,
        ));
    }

    /**
     * Rejects legacy input containing more than one array-valued recognised row container.
     * The names cover historical port layouts; PHP migration still requires its native "groups" list.
     *
     * @param array<string, mixed> $decoded - Decoded 0.5 root object.
     * @param string               $path    - Path the user named, repeated in the error so they can find the file.
     *
     * @return void - Returns when at most one recognised key contains an array.
     * @throws BaselineException - When multiple recognised row keys contain arrays.
     */
    private function requireOneRowContainer(array $decoded, string $path): void
    {
        $present = [];


        // Recognize the possible row containers to reject a document with competing sources of accepted counts.
        foreach (self::LEGACY_ROW_CONTAINERS as $container) {
            // Record only containers shaped like collections of baseline rows.
            if (is_array($decoded[$container] ?? null)) {
                $present[] = $container;
            }
        }

        // A file with two row containers cannot say unambiguously which findings were reviewed.
        if (count($present) > 1) {
            throw new BaselineException(
                sprintf('Baseline %s carries more than one row container (%s); a migration input must name exactly one.', $path, implode(', ', $present)),
            );
        }
    }

    /**
     * Writes the current findings out as a fresh v3 baseline and returns exactly what was persisted.
     *
     * Backs gruff-php analyse --generate-baseline path; the write is atomic, so an interrupted run never leaves a half-written file.
     *
     * @param string                    $path      - Baseline path to write, relative to the project root when needed.
     * @param list<Finding>             $findings  - Findings to record. Sensitive findings contribute to counts only.
     * @param DeclarationInventory|null $inventory - Scanned variable positions for stable ordinals; null ranks only the supplied findings.
     *
     * @return BaselineData - The baseline exactly as persisted, so callers can report what was written without re-reading the file.
     * @throws BaselineException - When a finding cannot be identified or the file cannot be encoded or written.
     */
    public function write(string $path, array $findings, ?DeclarationInventory $inventory = null): BaselineData
    {
        $document        = $this->documentFromFindings($findings, $inventory);
        $writtenBaseline = new BaselineData($path, BaselineIdentity::TOOL_LANGUAGE, $document['entries'], $document['sensitiveByRule']);

        $this->persist($path, $writtenBaseline);

        return $writtenBaseline;
    }

    /**
     * Carries a 0.5 baseline's reviews into a separate v3 file, leaving the original byte-identical.
     *
     * Backs gruff-php analyse --migrate-baseline old.json --generate-baseline new.json.
     * Identities are rebuilt from the current scan, never translated from 0.5 rows, because the five 0.5 shapes disagreed on identity.
     *
     * @param string                    $legacyPath - 0.5 baseline to read; never written to, renamed, or deleted.
     * @param string                    $outputPath - Where the v3 file goes; refused when it is the input by spelling, link, or inode.
     * @param list<Finding>             $findings   - Current findings to match by file, rule, and message up to the legacy count.
     * @param DeclarationInventory|null $inventory  - Scanned variable positions for stable ordinals; null ranks only the supplied findings.
     *
     * @return BaselineMigration - The written baseline plus how many findings were carried and how many were sensitive.
     * @throws BaselineException - When the paths are not distinct, the input is not a 0.5 file, or the input changed during the migration.
     */
    public function migrate(string $legacyPath, string $outputPath, array $findings, ?DeclarationInventory $inventory = null): BaselineMigration
    {
        $legacyAbsolute = $this->absolutePath($legacyPath);
        $outputAbsolute = $this->absolutePath($outputPath);

        $this->requireDistinctPaths(legacyAbsolute: $legacyAbsolute, outputAbsolute: $outputAbsolute, legacyPath: $legacyPath, outputPath: $outputPath);

        $before = file_get_contents($legacyAbsolute);

        // The input is the user's retreat path; if it cannot even be read there is nothing safe to carry forward.
        if ($before === false) {
            throw new BaselineException(sprintf('Unable to read baseline file: %s', $legacyPath));
        }

        $legacyGroups  = $this->legacyGroupsFrom($this->decodeObject($legacyPath, $before), $legacyPath);
        $remaining     = [];
        $totalFindings = count($findings);
        // Pool explicitly reviewed allowances for each legacy file, rule, and message.
        foreach ($legacyGroups as $group) {
            $key             = serialize([$group['file'], $group['ruleId'], $group['message']]);
            $remaining[$key] = min($totalFindings, ($remaining[$key] ?? 0) + min($totalFindings, $group['count']));
        }

        // Spend the reviewed counts on the earliest current occurrences, as the v2 matcher did.
        usort($findings, static fn (Finding $left, Finding $right): int =>
            [$left->line ?? PHP_INT_MAX, $left->column ?? 0] <=> [$right->line ?? PHP_INT_MAX, $right->column ?? 0]);
        $accepted = [];
        // Spend those allowances on current findings in source order so the same reviewed count carries forward.
        foreach ($findings as $finding) {
            $key            = serialize([$finding->filePath, $finding->ruleId, $finding->message]);
            $remainingCount = $remaining[$key] ?? 0;
            // Stop accepting this finding group when its previous review budget is used up.
            if ($remainingCount > 0) {
                $remaining[$key] = $remainingCount - 1;
                $accepted[]      = $finding;
            }
        }

        // If the original baseline changed during migration, the new file no longer represents a safe carry-forward.
        if (file_get_contents($legacyAbsolute) !== $before) {
            throw new BaselineException(sprintf('Migration changed the 0.5 input %s; the retreat path is no longer intact.', $legacyPath));
        }

        $writtenBaseline = $this->write($outputPath, $accepted, $inventory);

        return new BaselineMigration(writtenBaseline: $writtenBaseline, accepted: count($accepted), sensitiveCounted: $writtenBaseline->sensitiveTotal());
    }

    /**
     * Reads a baseline file and decodes its root for schema-specific validation.
     *
     * @param string      $path     - Baseline path to decode, relative to the project root when needed.
     * @param string|null $contents - Stable input bytes during migration. Null lets the reader load the file from disk.
     *
     * @return array<string, mixed> - The decoded non-list root array.
     * @throws BaselineException - When reading or decoding fails, or the decoded root is not a non-list array.
     */
    private function decodeObject(string $path, ?string $contents = null): array
    {
        $absolutePath = $this->absolutePath($path);


        // A missing baseline cannot provide a record of what the caller accepted.
        if (!is_file($absolutePath)) {
            throw new BaselineException(sprintf('Baseline file not found: %s', $path));
        }

        $contents ??= file_get_contents($absolutePath);


        // Unreadable review history must stop the operation before any findings can be treated as accepted.
        if ($contents === false) {
            throw new BaselineException(sprintf('Unable to read baseline file: %s', $path));
        }

        try {
            $decoded = json_decode($contents, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // A truncated or hand-edited JSON file must be corrected before it can suppress any findings.

            throw new BaselineException(sprintf('Invalid baseline JSON: %s', $exception->getMessage()), 0, $exception);
        }


        // A list or scalar lacks the version and occurrence fields the baseline reader needs.
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BaselineException('Baseline root must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded - Decoded root after the non-list check. */
        return $decoded;
    }

    /**
     * Reads occurrence rows, validating their fields and comparing each identity with its predecessor.
     *
     * @param mixed $occurrences - Raw decoded `occurrences` value.
     *
     * @return list<BaselineEntry> - Validated rows in input order; an empty occurrence list returns no rows.
     * @throws BaselineException - When the list or a row is invalid, or adjacent identities are equal or compare out of order.
     */
    private function entriesFromRows(mixed $occurrences): array
    {
        // Current baselines need an occurrences list even when the review accepted no findings.
        if (!is_array($occurrences) || !array_is_list($occurrences)) {
            throw new BaselineException('Baseline key "occurrences" must be a list.');
        }

        $entries  = [];
        $previous = '';

        // Validate every occurrence before any row can hide a reviewed finding.
        foreach ($occurrences as $index => $occurrenceRow) {
            // A row without named fields cannot carry an identity and accepted count.
            if (!is_array($occurrenceRow)) {
                throw new BaselineException(sprintf('Baseline occurrences[%d] must be an object.', $index));
            }

            /** @var BaselineRow $occurrenceRow - Row passed to fromArray() for field validation. */
            $validatedEntry = BaselineEntry::fromArray($occurrenceRow, $index);

            // Duplicate rows would split one identity's reviewed count across entries.
            if ($validatedEntry->identity === $previous) {
                throw new BaselineException(sprintf('Baseline occurrences[%d] duplicates identity %s; counts must be merged into one entry.', $index, $validatedEntry->identity));
            }

            // Require deterministic order so malformed or hand-edited inventories are rejected before they affect the scan.
            if ($validatedEntry->identity < $previous) {
                throw new BaselineException(sprintf('Baseline occurrences[%d] breaks the ascending identity order.', $index));
            }

            $entries[] = $validatedEntry;
            $previous  = $validatedEntry->identity;
        }

        return $entries;
    }

    /**
     * Validates the "sensitive" block, which must count without identifying.
     *
     * @param mixed $sensitive - Raw decoded "sensitive" value.
     *
     * @return array<string, int> - Sensitive counts by rule id.
     * @throws BaselineException - When fields or counts are invalid, identifying keys are present, or the total disagrees.
     */
    private function sensitiveCountsFrom(mixed $sensitive): array
    {
        // Sensitive findings have a separate count-only block in a current baseline.
        if (!is_array($sensitive) || array_is_list($sensitive)) {
            throw new BaselineException('Baseline key "sensitive" must be an object.');
        }

        // Eligibility is stored rather than assumed, so a file claiming otherwise fails loudly instead of being trusted.
        if (($sensitive['eligible'] ?? null) !== false) {
            throw new BaselineException('Baseline sensitive.eligible must be false.');
        }

        // These occurrence details could identify a sensitive finding, so the baseline rejects them instead of writing them.
        foreach (['identity', 'path', 'line', 'message', 'symbol', 'preview'] as $forbiddenKey) {
            // Reject fields that could identify individual sensitive matches instead of recording totals.
            if (array_key_exists($forbiddenKey, $sensitive)) {
                throw new BaselineException(sprintf('Baseline sensitive block carries forbidden key "%s".', $forbiddenKey));
            }
        }

        $counts = $sensitive['counts'] ?? null;

        // The caller must provide the sensitive counts even when the total is zero.
        if (!is_array($counts)) {
            throw new BaselineException('Baseline sensitive.counts must be an object.');
        }

        $byRule = $counts['byRule'] ?? null;

        // Per-rule counts provide the audit breakdown for the sensitive total.
        if (!is_array($byRule)) {
            throw new BaselineException('Baseline sensitive.counts.byRule must be an object.');
        }

        $validated = [];
        $summed    = 0;

        // Check each count before using it to reconcile the caller's baseline audit.
        foreach ($byRule as $ruleId => $count) {
            // A negative count or a non-string rule ID cannot describe observed sensitive findings.
            if (!is_string($ruleId) || !is_int($count) || $count < 0) {
                throw new BaselineException('Baseline sensitive.counts.byRule must map rule ids to non-negative integers.');
            }

            $validated[$ruleId] = $count;
            $summed += $count;
        }


        // Reject disagreeing totals so the baseline cannot misstate the number of sensitive findings.
        if (($counts['total'] ?? null) !== $summed) {
            throw new BaselineException(sprintf('Baseline sensitive.counts.total must equal the byRule sum of %d.', $summed));
        }

        return $validated;
    }

    /**
     * Turns the current findings into identity rows plus sensitive counts.
     *
     * @param list<Finding>             $findings  - The scan being recorded.
     * @param DeclarationInventory|null $inventory - Scanned variable positions for stable ordinals; null ranks only the supplied findings.
     *
     * @return array{entries: list<BaselineEntry>, sensitiveByRule: array<string, int>} - Rows ascending by identity, and sensitive counts by rule.
     * @throws BaselineException - When an ordinary finding cannot be identified.
     */
    private function documentFromFindings(array $findings, ?DeclarationInventory $inventory): array
    {
        $ordinals        = BaselineIdentity::assignOrdinals($findings, $inventory);
        $rowsByIdentity  = [];
        $sensitiveByRule = [];

        // Group accepted findings for writing while keeping sensitive findings in the count-only audit.
        foreach ($findings as $finding) {
            // A sensitive finding contributes a count and nothing that could name it.
            if (!BaselineIdentity::isEligible($finding)) {
                $sensitiveByRule[$finding->ruleId] = ($sensitiveByRule[$finding->ruleId] ?? 0) + 1;
                continue;
            }

            // A symbol carrying the ordinal separator cannot be named, so the finding stays out of the baseline
            // and keeps reporting, instead of aborting the whole generation.
            if (!BaselineIdentity::hasIdentity($finding)) {
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
     * @param string       $path            - Baseline path to write, relative to the project root when needed.
     * @param BaselineData $writtenBaseline - The baseline to persist.
     *
     * @return void - Writes the encoded baseline to the resolved destination.
     * @throws BaselineException - When the directory cannot be created or the file cannot be encoded or written.
     */
    private function persist(string $path, BaselineData $writtenBaseline): void
    {
        $absolutePath = $this->absolutePath($path);
        $directory    = dirname($absolutePath);


        // Create a requested parent directory when possible so a new baseline has somewhere safe to be written.
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new BaselineException(sprintf('Unable to create baseline directory: %s', $directory));
        }

        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'toolLanguage' => $writtenBaseline->toolLanguage,
            'generatedAt' => gmdate('c'),
            'occurrences' => array_map(static fn(BaselineEntry $entry): array => $entry->toArray(), $writtenBaseline->entries),
            'sensitive' => [
                'eligible' => false,
                'reason' => self::SENSITIVE_REASON,
                'counts' => ['total' => $writtenBaseline->sensitiveTotal(), 'byRule' => (object)$writtenBaseline->sensitiveByRule],
            ],
        ];

        try {
            // Invalid UTF-8 in a descriptive field is substituted rather than failing the write; the identity itself is hex and never affected.
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // Report an encoding failure before the destination is touched.
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
     * @param string $legacyPath     - Input path as the user typed it, for the message.
     * @param string $outputPath     - Output path as the user typed it, for the message.
     *
     * @return void - Returns when no spelling, resolved link, or inode makes the paths the same file.
     * @throws BaselineException - When the two paths name one file.
     */
    private function requireDistinctPaths(string $legacyAbsolute, string $outputAbsolute, string $legacyPath, string $outputPath): void
    {
        // Reusing the input path would overwrite the review history the caller is trying to migrate.
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
        $legacyStat = stat($legacyAbsolute);
        $outputStat = stat($outputAbsolute);
        $sameInode  = $legacyStat !== false && $outputStat !== false
            && $legacyStat['dev'] === $outputStat['dev'] && $legacyStat['ino'] === $outputStat['ino'];
        // Different spellings and hard links can still target the same original file.
        if (($legacyReal !== false && $legacyReal === $outputReal) || $sameInode) {
            throw new BaselineException(sprintf('Migration output path resolves to the input path: %s. Choose a separate output path.', $outputPath));
        }
    }

    /**
     * Writes a baseline payload crash-safely, streaming it to a temp file and renaming that into place.
     *
     * @param string $absolutePath - Final on-disk destination the temporary file is renamed onto.
     * @param string $payload      - Exact bytes to persist, written in full before the rename; partial writes raise.
     * @param string $displayPath  - Project-relative path used only in error messages, never for filesystem access.
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

            // Treat a failed disk flush as incomplete so a partial baseline cannot replace the current one.
            if (fflush($handle) === false) {
                throw new BaselineException(sprintf('Unable to write baseline file: %s', $displayPath));
            }

            // Force the bytes onto disk where the platform offers it, so a crash right after cannot lose the baseline.
            if (function_exists('fsync') && !fsync($handle)) {
                throw new BaselineException(sprintf('Unable to flush baseline file: %s', $displayPath));
            }
        } catch (BaselineException $exception) {
            // A full disk can interrupt a write. Remove the partial temporary file so the prior baseline stays usable.
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
     * @param string $tempPath    - Temporary file created for an attempted baseline write.
     * @param string $displayPath - User-supplied destination shown only in the error message.
     *
     * @return void - Returns when no temporary regular file remains to remove.
     * @throws BaselineException - When the temp file exists and cannot be removed.
     */
    private function removeTemporaryFile(string $tempPath, string $displayPath): void
    {
        // An unusable temporary file can prevent a safe retry, so report when it cannot be removed.
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
