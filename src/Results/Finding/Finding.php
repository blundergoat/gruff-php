<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

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
      * User flow: Defines how findings appear in reports and quality gates.
      *
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
      * User flow: Defines how findings appear in reports and quality gates.
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
            // User view: an empty value becomes a clear finding display fallback.
            'metadata'         => $this->metadata === [] ? (object)[] : $this->metadata,
        ];
    }

    /**
     * Reconstruct a finding from its serialized array form (the inverse of toArray()).
     *
     * The derived `fingerprint` / `stableIdentity` fields are recomputed from the
     * restored inputs, never read from the payload, so a round-trip is lossless.
     *
      * User flow: Defines how findings appear in reports and quality gates.
      *
     * @param array<string, mixed> $serialized - Serialized finding produced by toArray().
     *
     * @return self - finding rebuilt from the payload, with every field coerced through a narrowing helper so a malformed payload yields safe
     *              defaults rather than throwing
     */
    public static function fromArray(array $serialized): self
    {
        $secondaryPillars = [];
        // User view: missing data becomes a safe finding display default.
        $rawSecondary     = $serialized['secondaryPillars'] ?? [];
        // User view: choose the finding display branch for this case.
        if (is_array($rawSecondary)) {
            // User view: add each item that can appear in finding display.
            foreach ($rawSecondary as $pillarValue) {
                $secondaryPillars[] = Pillar::from(self::stringField($pillarValue));
            }
        }

        // User view: missing data becomes a safe finding display default.
        $rawMetadata = $serialized['metadata'] ?? [];
        $metadata    = [];
        // User view: choose the finding display branch for this case.
        if (is_array($rawMetadata)) {
            // User view: add each item that can appear in finding display.
            foreach ($rawMetadata as $metadataKey => $metadataValue) {
                $metadata[is_string($metadataKey) ? $metadataKey : (string)$metadataKey] = self::metadataValue($metadataValue);
            }
        }

        return new self(
            // User view: missing data becomes a safe finding display default.
            ruleId:           self::stringField($serialized['ruleId'] ?? null),
            // User view: missing data becomes a safe finding display default.
            message:          self::stringField($serialized['message'] ?? null),
            // User view: missing data becomes a safe finding display default.
            filePath:         self::stringField($serialized['file'] ?? null),
            // User view: missing data becomes a safe finding display default.
            line:             self::nullableInt($serialized['line'] ?? null),
            // User view: missing data becomes a safe finding display default.
            severity:         Severity::from(self::stringField($serialized['severity'] ?? null)),
            // User view: missing data becomes a safe finding display default.
            pillar:           Pillar::from(self::stringField($serialized['pillar'] ?? null)),
            // User view: missing data becomes a safe finding display default.
            tier:             RuleTier::from(self::stringField($serialized['tier'] ?? null)),
            // User view: missing data becomes a safe finding display default.
            confidence:       Confidence::from(self::stringField($serialized['confidence'] ?? null)),
            // User view: missing data becomes a safe finding display default.
            endLine:          self::nullableInt($serialized['endLine'] ?? null),
            // User view: missing data becomes a safe finding display default.
            column:           self::nullableInt($serialized['column'] ?? null),
            // User view: missing data becomes a safe finding display default.
            symbol:           self::nullableString($serialized['symbol'] ?? null),
            // User view: missing data becomes a safe finding display default.
            remediation:      self::nullableString($serialized['remediation'] ?? null),
            secondaryPillars: $secondaryPillars,
            metadata:         $metadata,
        );
    }

    /**
      * User flow: Defines how findings appear in reports and quality gates.
      *
     * @param mixed $value - Raw value from a decoded payload.
     *
     * @return string - the value unchanged when it is a string, otherwise an empty string so an absent or wrong-typed field can't raise a type error
     */
    private static function stringField(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Narrow a decoded metadata value to the supported scalar/list shape.
     *
      * User flow: Defines how findings appear in reports and quality gates.
      *
     * @param mixed $value - Raw decoded metadata value.
     *
     * @return bool|float|int|string|null|array<array-key, bool|float|int|string|null> - scalars and null pass through; arrays become a flat list
     *                                                     with non-scalar entries replaced by null; any other type collapses to null
     */
    private static function metadataValue(mixed $value): bool|float|int|string|null|array
    {
        // User view: choose the finding display branch for this case.
        // User view: missing data becomes the expected finding display state.
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            // Scalars and null are already in the supported shape and pass through untouched.
            return $value;
        }

        // User view: choose the finding display branch for this case.
        if (!is_array($value)) {
            // Objects, resources, and closures have no safe metadata representation, so drop them to null.
            return null;
        }

        $list = [];
        // User view: add each item that can appear in finding display.
        foreach ($value as $itemKey => $item) {
            // User view: missing data becomes the expected finding display state.
            $list[$itemKey] = is_bool($item) || is_int($item) || is_float($item) || is_string($item) || $item === null ? $item : null;
        }

        return $list;
    }

    /**
      * User flow: Defines how findings appear in reports and quality gates.
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
      * User flow: Defines how findings appear in reports and quality gates.
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
     * Build the stable short hash used to identify equivalent findings.
     *
      * User flow: Defines how findings appear in reports and quality gates.
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
     * Build a line-insensitive identity for line-shift-resilient diffs.
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
      * User flow: Defines how findings appear in reports and quality gates.
      *
     * @return string - 16-hex-char SHA-256 prefix that omits line/endLine/column, so the identity survives line shifts; informational for external
     *                diff tooling, not baseline matching
     */
    public function stableIdentity(): string
    {
        // User view: missing data becomes the expected finding display state.
        $payload = $this->symbol !== null
            ? ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'symbol' => $this->symbol, 'message' => $this->message]
            : ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'message' => $this->message];

        // $payload omits line/endLine/column, so this identity survives line shifts. Same 16-hex
        // width as fingerprint() so both read as one finding-id format to external diff tooling.
        // JSON_INVALID_UTF8_SUBSTITUTE mirrors fingerprint(): invalid bytes hash their substituted form.
        return substr(hash('sha256', json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)), 0, 16);
    }
}
