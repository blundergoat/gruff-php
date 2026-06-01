<?php

declare(strict_types=1);

namespace GruffPhp\Finding;

/**
 * Represents one analyzer finding with reporting and fingerprint metadata.
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
     * @param string          $ruleId - Stable rule identifier that produced the finding.
     * @param string          $message - Human-readable finding message.
     * @param string          $filePath - Display path for the affected file.
     * @param int|null        $line - Start line for the finding, when known.
     * @param Severity        $severity - Severity used for reporting and exit gates.
     * @param Pillar          $pillar - Primary quality pillar for the finding.
     * @param RuleTier        $tier - Rule catalogue tier that owns the finding.
     * @param Confidence      $confidence - Confidence level assigned by the rule.
     * @param int|null        $endLine - End line for multi-line findings, when known.
     * @param int|null        $column - Start column for the finding, when known.
     * @param string|null     $symbol - Symbol associated with the finding, when available.
     * @param string|null     $remediation - Suggested remediation text, when available.
     * @param list<Pillar>    $secondaryPillars - Additional quality pillars touched by the finding.
     * @param FindingMetadata $metadata - Machine-readable rule metadata for reporters.
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
     * Serialise the finding for report payloads.
     *
     * @return FindingArray - canonical report payload with fingerprint and stableIdentity recomputed, never stored; empty metadata serialises as an
     *                      object
     */
    public function toArray(): array
    {
        // Derived fingerprint/identity are recomputed here, never stored, so the payload stays canonical.
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
            'metadata'         => $this->metadata === [] ? (object)[] : $this->metadata,
        ];
    }

    /**
     * Reconstruct a finding from its serialized array form (the inverse of toArray()).
     *
     * The derived `fingerprint` / `stableIdentity` fields are recomputed from the
     * restored inputs, never read from the payload, so a round-trip is lossless.
     *
     * @param array<string, mixed> $serialized - Serialized finding produced by toArray().
     *
     * @return self - finding rebuilt from the payload, with every field coerced through a narrowing helper so a malformed payload yields safe
     *              defaults rather than throwing
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

        // Every field is coerced through a narrowing helper so a malformed payload can't construct a bad finding.
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
     * @param mixed $value - Raw value from a decoded payload.
     *
     * @return string - the value unchanged when it is a string, otherwise an empty string so an absent or wrong-typed field can't raise a type error
     */
    private static function stringField(mixed $value): string
    {
        // A wrong-typed or absent field collapses to an empty string rather than raising a type error.
        return is_string($value) ? $value : '';
    }

    /**
     * Narrow a decoded metadata value to the supported scalar/list shape.
     *
     * @param mixed $value - Raw decoded metadata value.
     *
     * @return bool|float|int|string|null|array<array-key, bool|float|int|string|null> - scalars and null pass through; arrays become a flat list
     *                                                     with non-scalar entries replaced by null; any other type collapses to null
     */
    private static function metadataValue(mixed $value): bool|float|int|string|null|array
    {
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            // Scalars and null are already in the supported shape and pass through untouched.
            return $value;
        }

        if (!is_array($value)) {
            // Objects, resources, and closures have no safe metadata representation, so drop them to null.
            return null;
        }

        $list = [];
        foreach ($value as $itemKey => $item) {
            $list[$itemKey] = is_bool($item) || is_int($item) || is_float($item) || is_string($item) || $item === null ? $item : null;
        }

        // Each element is individually narrowed; non-scalar entries become null so the list stays flat.
        return $list;
    }

    /**
     * @param mixed $value - Raw value from a decoded payload.
     *
     * @return int|null - the integer unchanged, or null for any non-int (numeric strings included) so absent line/column data stays absent
     */
    private static function nullableInt(mixed $value): ?int
    {
        // Non-integers (including numeric strings) become null so absent line/column data stays absent.
        return is_int($value) ? $value : null;
    }

    /**
     * @param mixed $value - Raw value from a decoded payload.
     *
     * @return string|null - the value unchanged when it is a string, otherwise null so optional fields like symbol/remediation read as "not set"
     */
    private static function nullableString(mixed $value): ?string
    {
        // Non-strings become null so optional fields like symbol/remediation read as "not set".
        return is_string($value) ? $value : null;
    }

    /**
     * Build the stable short hash used to identify equivalent findings.
     *
     * @return string - 16-hex-char SHA-256 prefix over ruleId/file/line/endLine/column/symbol/message; wide enough to avoid collisions, short enough
     *                to store in baselines
     */
    public function fingerprint(): string
    {
        $encoded = json_encode([
                                   'ruleId' => $this->ruleId,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                'file' => $this->filePath,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                'line' => $this->line,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                'endLine' => $this->endLine,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                'column' => $this->column,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                'symbol' => $this->symbol,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                'message' => $this->message,
                               ], JSON_THROW_ON_ERROR);

        // Truncate to a 16-hex-char digest: short enough to store, wide enough to avoid finding collisions.
        return substr(hash('sha256', $encoded), 0, 16);
    }

    /**
     * Build a line-insensitive identity for line-shift-resilient diffs.
     *
     * Keyed by `[ruleId, file, symbol, message]` when the finding carries a
     * symbol, or `[ruleId, file, message]` when symbol is null. Line / endLine
     * / column are intentionally excluded so two findings of the same rule on
     * the same symbol that shifted lines via unrelated edits resolve to the
     * same identity. `message` is included even when symbol is set so multiple
     * findings sharing one symbol (e.g. `docs.missing-param-tag` emitting one
     * finding per missing parameter, all under the same method name) stay
     * distinct in external diff tooling. For baseline matching, callers should
     * still use {@see fingerprint()}; this field is informational for external
     * diff tooling.
     *
     * @return string - 16-hex-char SHA-256 prefix that omits line/endLine/column, so the identity survives line shifts; informational for external
     *                diff tooling, not baseline matching
     */
    public function stableIdentity(): string
    {
        $payload = $this->symbol !== null
            ? ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'symbol' => $this->symbol, 'message' => $this->message]
            : ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'message' => $this->message];

        // $payload omits line/endLine/column, so this identity survives line shifts. Same 16-hex
        // width as fingerprint() so both read as one finding-id format to external diff tooling.
        return substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
