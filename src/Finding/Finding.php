<?php

declare(strict_types=1);

namespace GruffPhp\Finding;

/**
 * Represents one analyzer finding with reporting and fingerprint metadata.
 */
final readonly class Finding
{
    /**
     * @param list<Pillar> $secondaryPillars
     * @param array<string, mixed> $metadata
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
     * @return array{
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
     *     metadata: array<string, mixed>|object
     * }
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
        ], JSON_THROW_ON_ERROR);

        return substr(hash('sha256', $encoded), 0, 16);
    }
}
