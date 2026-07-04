<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * One row of the analyser's verdict: a single problem a rule flagged in the user's code,
 * carrying its rule id, message, location, severity, and the quality pillar it counts against.
 * Every tally in `summary` and every line of `analyse` and `report` is one of these; a baseline row
 * (a `BaselineEntry`) groups several of them by count. Instances are immutable - rules build them during a scan and reporters only
 * read them. On top of the raw fields it derives two hashes on demand: `fingerprint()` for exact
 * identity and `stableIdentity()` for line-shift-resilient diffs, and it round-trips losslessly
 * through `toArray()` / `fromArray()` so a run survives the result cache and JSON reports.
 *
 * @phpstan-type FindingMetadataValue bool|float|int|string|null|array<array-key, bool|float|int|string|null>
 * @phpstan-type FindingMetadata array<string, FindingMetadataValue>
 * @phpstan-type FindingArray array{
 *     ruleId: string,
 *     message: string,
 *     file: string,
 *     line: int|null,
 *     endLine: int|null,
 *     column: int|null,
 *     symbol: string|null,
 *     severity: string,
 *     pillar: string,
 *     secondaryPillars: list<string>,
 *     tier: string,
 *     confidence: string,
 *     remediation: string|null,
 *     fingerprint: string,
 *     stableIdentity: string,
 *     metadata: FindingMetadata|object
 * }
 */
final readonly class Finding
{
    /**
     * Holds the fully-formed finding a rule emits during a scan; every field is public and
     * immutable, so reporters read the values straight through with no getters or setters.
     *
     * @param string          $ruleId - Stable rule identifier that produced the finding.
     * @param string          $message - Human-readable finding message.
     * @param string          $filePath - Display path for the affected file.
     * @param int|null        $line - Line where the problem sits; null when the finding is file-level rather than pinned to one line.
     * @param Severity        $severity - Severity used for reporting and exit gates.
     * @param Pillar          $pillar - Primary quality pillar for the finding.
     * @param RuleTier        $tier - Rule catalogue tier that owns the finding.
     * @param Confidence      $confidence - Confidence level assigned by the rule.
     * @param int|null        $endLine - Last line of a multi-line finding; null when it covers only the single start line.
     * @param int|null        $column - Column within the start line; null when the rule reported no column.
     * @param string|null     $symbol - Name of the function, method, or class the finding is about; null when it isn't tied to one named symbol.
     * @param string|null     $remediation - Short fix hint shown to the user; null when the rule offers no suggestion.
     * @param list<Pillar>    $secondaryPillars - Extra pillars the finding also counts against; empty when it touches only the primary pillar.
     * @param FindingMetadata $metadata - Machine-readable extras a reporter can surface; empty when the rule attached none.
     */
    public function __construct(
        public string     $ruleId,
        public string     $message,
        public string     $filePath,
        public ?int       $line,
        public Severity   $severity,
        public Pillar     $pillar,
        public RuleTier   $tier,
        public Confidence $confidence,
        public ?int       $endLine = null,
        public ?int       $column = null,
        public ?string    $symbol = null,
        public ?string    $remediation = null,
        public array      $secondaryPillars = [],
        public array      $metadata = [],
    ) {
    }

    /**
     * Flattens the finding into the plain array shape every reporter and the result cache serialise -
     * the rows behind `gruff-php analyse --format json`, `report`, and SARIF all start here. Both
     * hashes are recomputed on the way out, never read from a stored field.
     *
     * @return FindingArray - canonical report payload with fingerprint and stableIdentity recomputed, never stored; empty metadata serialises as an
     *                      object
     */
    public function toArray(): array
    {
        return [
            'ruleId'           => $this->ruleId,
            'message'          => $this->message,
            'file'             => $this->filePath,
            'line'             => $this->line,
            'endLine'          => $this->endLine,
            'column'           => $this->column,
            'symbol'           => $this->symbol,
            'severity'         => $this->severity->value,
            'pillar'           => $this->pillar->value,
            'secondaryPillars' => array_map(
                static fn(Pillar $pillar): string => $pillar->value,
                $this->secondaryPillars,
            ),
            'tier'             => $this->tier->value,
            'confidence'       => $this->confidence->value,
            'remediation'      => $this->remediation,
            'fingerprint'      => $this->fingerprint(),
            'stableIdentity'   => $this->stableIdentity(),
            // Empty metadata is emitted as a JSON object, not `[]`, so a consumer always sees `{}` for this field.
            'metadata'         => $this->metadata === [] ? (object)[] : $this->metadata,
        ];
    }

    /**
     * Rebuilds a finding from the array `toArray()` produced - the path back into a live object
     * when the result cache reloads a scan or a reporter reads a stored `analyse --format json` run.
     * Every field is coerced through a narrowing helper, so the string, int, and nullable slots survive a
     * truncated or hand-edited payload; the enum slots (severity, pillar, tier, confidence) still throw on
     * an unknown value, which the result-cache reader catches.
     *
     * The `fingerprint` and `stableIdentity` values are recomputed from the restored inputs, never
     * read from the payload, so the round-trip stays lossless.
     *
     * @param array<string, mixed> $serialized - Serialized finding produced by toArray().
     *
     * @return self - finding rebuilt from the payload; string/int/nullable fields degrade to safe defaults, while an unknown enum value (severity, pillar, tier, confidence) throws and the result-cache reader catches it
     */
    public static function fromArray(array $serialized): self
    {
        $secondaryPillars = [];
        $rawSecondary     = $serialized['secondaryPillars'] ?? [];
        // Only rebuild the extra-pillar list when the payload carried an array; a missing or garbled field just leaves it empty.
        if (is_array($rawSecondary)) {
            // Turn each stored secondary-pillar string back into its enum; the SARIF report lists them, so they must survive the round-trip intact.
            foreach ($rawSecondary as $pillarValue) {
                $secondaryPillars[] = Pillar::from(self::stringField($pillarValue));
            }
        }

        $rawMetadata = $serialized['metadata'] ?? [];
        $metadata    = [];
        // Rebuild the metadata map only from a genuine array; anything else means the reporter simply gets no extras rather than a type error.
        if (is_array($rawMetadata)) {
            // Copy each entry back, forcing keys to strings and narrowing values, so restored metadata matches the shape reporters expect.
            foreach ($rawMetadata as $metadataKey => $metadataValue) {
                $metadata[is_string($metadataKey) ? $metadataKey : (string)$metadataKey] = self::metadataValue($metadataValue);
            }
        }

        return new self(
            ruleId:           self::stringField($serialized['ruleId'] ?? null),
            message:          self::stringField($serialized['message'] ?? null),
            filePath:         self::stringField($serialized['file'] ?? null),
            line:             self::nullableInt($serialized['line'] ?? null),
            severity:         Severity::from(self::stringField($serialized['severity'] ?? null)),
            pillar:           Pillar::from(self::stringField($serialized['pillar'] ?? null)),
            tier:             RuleTier::from(self::stringField($serialized['tier'] ?? null)),
            confidence:       Confidence::from(self::stringField($serialized['confidence'] ?? null)),
            endLine:          self::nullableInt($serialized['endLine'] ?? null),
            column:           self::nullableInt($serialized['column'] ?? null),
            symbol:           self::nullableString($serialized['symbol'] ?? null),
            remediation:      self::nullableString($serialized['remediation'] ?? null),
            secondaryPillars: $secondaryPillars,
            metadata:         $metadata,
        );
    }

    /**
     * Coerces one decoded field to a plain string - used for every string-shaped slot: `ruleId`,
     * `message`, `file`, and the raw severity/pillar/tier/confidence values before they reach their enums.
     *
     * @param mixed $value - Raw value from a decoded payload.
     *
     * @return string - the value unchanged when it is a string, otherwise an empty string so an absent or wrong-typed field can't raise a type error
     */
    private static function stringField(mixed $value): string
    {
        // Anything that isn't a string (missing key, number, nested array) becomes blank; the plain string fields absorb that quietly, but an enum field handed a blank then throws in `::from()` upstream.
        return is_string($value) ? $value : '';
    }

    /**
     * Narrows one decoded metadata value to the scalar-or-flat-list shape a reporter can render,
     * so a rule that stashed something exotic in metadata can't break a report on the way back in.
     *
     * @param mixed $value - Raw decoded metadata value.
     *
     * @return bool|float|int|string|null|array<array-key, bool|float|int|string|null> - scalars and null pass through; arrays become a flat list
     *                                                     with non-scalar entries replaced by null; any other type collapses to null
     */
    private static function metadataValue(mixed $value): bool|float|int|string|null|array
    {
        // A scalar or null is already something a reporter can print, so hand it straight back untouched.
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        // A non-array, non-scalar value (an object, resource, or closure) has no safe printable form, so drop it to null.
        if (!is_array($value)) {
            return null;
        }

        $list = [];
        // Flatten one level: keep scalar entries and null anything deeper, so restored metadata never nests past what reporters expect.
        foreach ($value as $itemKey => $item) {
            $list[$itemKey] = is_bool($item) || is_int($item) || is_float($item) || is_string($item) || $item === null ? $item : null;
        }

        return $list;
    }

    /**
     * Coerces a decoded field to an int-or-null for `line`, `endLine`, and `column`, so a missing
     * or non-numeric position restores as "no location" rather than a misleading zero.
     *
     * @param mixed $value - Raw value from a decoded payload.
     *
     * @return int|null - the integer unchanged, or null for any non-int (numeric strings included) so absent line/column data stays absent
     */
    private static function nullableInt(mixed $value): ?int
    {
        // Only a real integer counts as a position; a string, float, or missing key restores as null, so the finding reads as having no line or column.
        return is_int($value) ? $value : null;
    }

    /**
     * Coerces a decoded field to a string-or-null for the optional `symbol` and `remediation`
     * slots, so an absent one restores as "not set" instead of an empty string.
     *
     * @param mixed $value - Raw value from a decoded payload.
     *
     * @return string|null - the value unchanged when it is a string, otherwise null so optional fields like symbol/remediation read as "not set"
     */
    private static function nullableString(mixed $value): ?string
    {
        // A non-string (or missing key) restores as null, keeping "no symbol" or "no remediation" distinct from a field that was genuinely blank.
        return is_string($value) ? $value : null;
    }

    /**
     * Derives the short hash that pins this finding to one exact spot - same rule, file, line, and
     * message. Reporters emit it as `fingerprint` for external tooling to match the exact same finding
     * across runs; baselines do not use it - they group on (file, ruleId, message) counts instead.
     *
     * @return string - 16-hex-char SHA-256 prefix over ruleId/file/line/endLine/column/symbol/message; wide enough to avoid collisions, short enough
     *                to store in baselines
     */
    public function fingerprint(): string
    {
        // JSON_INVALID_UTF8_SUBSTITUTE keeps hashing total: a finding whose message or path carries
        // invalid bytes hashes its substituted form instead of throwing before any reporter runs.
        $encoded = json_encode([
                                   'ruleId'  => $this->ruleId,
                                   'file'    => $this->filePath,
                                   'line'    => $this->line,
                                   'endLine' => $this->endLine,
                                   'column'  => $this->column,
                                   'symbol'  => $this->symbol,
                                   'message' => $this->message,
                               ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        return substr(hash('sha256', $encoded), 0, 16);
    }

    /**
     * Derives the hash that keeps a finding recognisable after unrelated edits shift it up or down
     * the file - the identity external diff tools and SARIF consumers use to say this is the same
     * problem as last run even when its line number moved.
     *
     * Keyed by `[ruleId, file, symbol, message]` when the finding carries a
     * symbol, or `[ruleId, file, message]` when symbol is null. Line / endLine
     * / column are intentionally excluded so two findings of the same rule on
     * the same symbol that shifted lines via unrelated edits resolve to the
     * same identity. `message` is included even when symbol is set so multiple
     * findings sharing one symbol (e.g. `docs.missing-param-tag` emitting one
     * finding per missing parameter, all under the same method name) stay
     * distinct in external diff tooling. Baselines match on grouped
     * (file, ruleId, message) counts rather than on either hash; this field is
     * informational for external diff tooling and SARIF consumers.
     *
     * @return string - 16-hex-char SHA-256 prefix that omits line/endLine/column, so the identity survives line shifts; informational for external
     *                diff tooling, not baseline matching
     */
    public function stableIdentity(): string
    {
        // A finding tied to a named symbol keys its identity on that symbol; a file-level finding with no symbol falls back to rule and message alone, so both still get a stable id.
        $payload = $this->symbol !== null
            ? ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'symbol' => $this->symbol, 'message' => $this->message]
            : ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'message' => $this->message];

        // $payload omits line/endLine/column, so this identity survives line shifts. Same 16-hex
        // width as fingerprint() so both read as one finding-id format to external diff tooling.
        // JSON_INVALID_UTF8_SUBSTITUTE mirrors fingerprint(): invalid bytes hash their substituted form.
        return substr(hash('sha256', json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)), 0, 16);
    }
}
