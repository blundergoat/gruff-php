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
     * @param string          $ruleId           Stable rule identifier that produced the finding.
     * @param string          $message          Human-readable finding message.
     * @param string          $filePath         Display path for the affected file.
     * @param int|null        $line             Start line for the finding, when known.
     * @param Severity        $severity         Severity used for reporting and exit gates.
     * @param Pillar          $pillar           Primary quality pillar for the finding.
     * @param RuleTier        $tier             Rule catalogue tier that owns the finding.
     * @param Confidence      $confidence       Confidence level assigned by the rule.
     * @param int|null        $endLine          End line for multi-line findings, when known.
     * @param int|null        $column           Start column for the finding, when known.
     * @param string|null     $symbol           Symbol associated with the finding, when available.
     * @param string|null     $remediation      Suggested remediation text, when available.
     * @param list<Pillar>    $secondaryPillars Additional quality pillars touched by the finding.
     * @param FindingMetadata $metadata         Machine-readable rule metadata for reporters.
     */
    public function __construct(
        public string $ruleId,
        public string $message,
        public string $filePath,
        public ?int $line,
        public Severity $severity,
        public Pillar $pillar,
        public RuleTier $tier,
        public Confidence $confidence,
        public ?int $endLine = null,
        public ?int $column = null,
        public ?string $symbol = null,
        public ?string $remediation = null,
        public array $secondaryPillars = [],
        public array $metadata = [],
    ) {
    }

    /**
     * Serialise the finding for report payloads.
     *
     * @return FindingArray
     */
    public function toArray(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'message' => $this->message,
            'file' => $this->filePath,
            'line' => $this->line,
            'endLine' => $this->endLine,
            'column' => $this->column,
            'symbol' => $this->symbol,
            'severity' => $this->severity->value,
            'pillar' => $this->pillar->value,
            'secondaryPillars' => array_map(
                static fn (Pillar $pillar): string => $pillar->value,
                $this->secondaryPillars,
            ),
            'tier' => $this->tier->value,
            'confidence' => $this->confidence->value,
            'remediation' => $this->remediation,
            'fingerprint' => $this->fingerprint(),
            'stableIdentity' => $this->stableIdentity(),
            'metadata' => $this->metadata === [] ? (object) [] : $this->metadata,
        ];
    }

    /**
     * Build the stable short hash used to identify equivalent findings.
     *
     * @return string Sixteen-character SHA-256 prefix for the finding identity.
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
     * @return string Sixteen-character SHA-256 prefix for the line-insensitive identity.
     */
    public function stableIdentity(): string
    {
        $payload = $this->symbol !== null
            ? ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'symbol' => $this->symbol, 'message' => $this->message]
            : ['ruleId' => $this->ruleId, 'file' => $this->filePath, 'message' => $this->message];

        return substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
