<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * Represents one rule finding with its location, severity, quality pillars, and optional metadata.
 * Instances are readonly records whose fields are available directly to callers.
 * `fingerprint()` includes location; `stableIdentity()` omits line and column positions for comparison across line shifts.
 *
 * `toArray()` serialises fields and both hashes; `fromArray()` restores fields without trusting stored hashes.
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
     * @param int|null        $endLine - Last line of the finding; null when no end line was supplied.
     * @param int|null        $column - Column within the start line; null when the rule reported no column.
     * @param string|null     $symbol - Rule-supplied symbol or label, including variables and mutators; null when none was supplied.
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
     * Serialises the finding fields, enum values, and both derived hashes for reports and caches.
     * Empty metadata is represented as an object so JSON encodes it as `{}`.
     *
     * @return FindingArray - Report payload with freshly computed hashes and an object for empty metadata.
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
            // Empty metadata must encode as `{}` to preserve the JSON object shape.
            'metadata'         => $this->metadata === [] ? (object)[] : $this->metadata,
        ];
    }

    /**
     * Restores a finding from a report payload, defaulting invalid scalar and container fields to empty values or null.
     * Missing or invalid required enum values and invalid secondary-pillar entries raise ValueError.
     * Stored hash fields are ignored; hashes are derived from the restored fields.
     *
     * @param array<string, mixed> $serialized - Serialized finding produced by toArray().
     *
     * @return self - Restored finding with hashes derived from its fields.
     */
    public static function fromArray(array $serialized): self
    {
        $secondaryPillars = [];
        $rawSecondary     = $serialized['secondaryPillars'] ?? [];

        if (is_array($rawSecondary)) {

            foreach ($rawSecondary as $pillarValue) {
                $secondaryPillars[] = Pillar::from(self::stringField($pillarValue));
            }
        }

        $rawMetadata = $serialized['metadata'] ?? [];
        $metadata    = [];

        if (is_array($rawMetadata)) {

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
     * Normalises an optional decoded field to text without coercing non-string JSON values.
     *
     * @param mixed $value - Raw value from a decoded payload.
     *
     * @return string - Original string, or an empty string for other types.
     */
    private static function stringField(mixed $value): string
    {

        return is_string($value) ? $value : '';
    }

    /**
     * Normalises metadata to scalars, null, or a one-level array while preserving array keys.
     * Unsupported top-level values and nested non-scalar entries become null.
     *
     * @param mixed $value - Raw decoded metadata value.
     *
     * @return bool|float|int|string|null|array<array-key, bool|float|int|string|null> - Preserves keys; nested or unsupported values become null.
     */
    private static function metadataValue(mixed $value): bool|float|int|string|null|array
    {

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }


        if (!is_array($value)) {
            return null;
        }

        $list = [];

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

        return is_string($value) ? $value : null;
    }

    /**
     * Derives a location-sensitive hash from the rule, file, positions, symbol, and message.
     * Baselines use a separate line-free identity rather than this hash.
     *
     * @return string - First 16 lowercase hexadecimal characters of the SHA-256 digest.
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
     * Hashes rule, file, message, and any non-null symbol for comparisons that survive line and column changes.
     * The message distinguishes findings on the same symbol; an empty symbol remains distinct from null.
     * Baselines use BaselineIdentity instead of this hash.
     *
     * @return string - First 16 lowercase hexadecimal characters of the position-independent SHA-256 digest.
     */
    public function stableIdentity(): string
    {

        $payload = $this->symbol !== null
            ? ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'symbol' => $this->symbol, 'message' => $this->message]
            : ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'message' => $this->message];



        // JSON_INVALID_UTF8_SUBSTITUTE mirrors fingerprint(): invalid bytes hash their substituted form.
        return substr(hash('sha256', json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)), 0, 16);
    }
}
