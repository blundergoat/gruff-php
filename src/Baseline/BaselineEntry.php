<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

use GruffPhp\Finding\Finding;

/**
 * Represents one persisted baseline fingerprint and its source metadata.
 */
final readonly class BaselineEntry
{
    /**
     * Capture the stable fields used to match a finding against a baseline.
     *
     * @param string $fingerprint Stable finding fingerprint used for baseline matching.
     * @param string $ruleId Rule identifier that produced the finding.
     * @param string $filePath Display path recorded for the finding.
     * @param int|null $line Source line recorded for the finding, when known.
     * @param string|null $symbol Symbol recorded for the finding, when available.
     * @param string $message Finding message preserved for stale-entry reporting.
     */
    public function __construct(
        public string $fingerprint,
        public string $ruleId,
        public string $filePath,
        public ?int $line,
        public ?string $symbol,
        public string $message,
    ) {
    }

    /**
     * Create a baseline entry from a live analysis finding.
     *
     * @param Finding $finding Live analysis finding to persist in the baseline.
     * @return self Baseline entry carrying the finding fingerprint and identity.
     */
    public static function fromFinding(Finding $finding): self
    {
        return new self(
            fingerprint: $finding->fingerprint(),
            ruleId: $finding->ruleId,
            filePath: $finding->filePath,
            line: $finding->line,
            symbol: $finding->symbol,
            message: $finding->message,
        );
    }

    /**
     * @param array<string, mixed> $data Serialized baseline row decoded from JSON.
     * @param int $index Zero-based baseline entry position for error messages.
     * @throws BaselineException When required fields are missing or malformed.
     * @return self Baseline entry decoded from serialized baseline data.
     */
    public static function fromArray(array $data, int $index): self
    {
        foreach (['fingerprint', 'ruleId', 'file', 'message'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                throw new BaselineException(sprintf('Baseline finding %d must include non-empty "%s".', $index, $key));
            }
        }

        $line = $data['line'] ?? null;
        if ($line !== null && !is_int($line)) {
            throw new BaselineException(sprintf('Baseline finding %d field "line" must be an integer or null.', $index));
        }

        $symbol = $data['symbol'] ?? null;
        if ($symbol !== null && !is_string($symbol)) {
            throw new BaselineException(sprintf('Baseline finding %d field "symbol" must be a string or null.', $index));
        }

        return new self(
            fingerprint: $data['fingerprint'],
            ruleId: $data['ruleId'],
            filePath: $data['file'],
            line: $line,
            symbol: $symbol,
            message: $data['message'],
        );
    }

    /**
     * @return array{fingerprint: string, ruleId: string, file: string, line: int|null, symbol: string|null, message: string}
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'ruleId' => $this->ruleId,
            'file' => $this->filePath,
            'line' => $this->line,
            'symbol' => $this->symbol,
            'message' => $this->message,
        ];
    }
}
