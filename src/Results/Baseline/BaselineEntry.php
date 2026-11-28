<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * One reviewed row of the user's committed `gruff-baseline.json`: a line-free identity plus how many occurrences of it the team signed off.
 *
 * Rows are written by `analyse --generate-baseline` and read back on every later `analyse --baseline`.
 * Matching reads the identity and the count and nothing else; the rule, path, and subject are kept so a reviewer can read the file.
 * Because no line, column, message, or severity is stored, everyday reformatting never re-flags debt the team already accepted.
 */
final readonly class BaselineEntry
{
    /**
     * Keys a v3 row may never carry; each one is a way a stored baseline could expire or leak.
     */
    private const FORBIDDEN_KEYS = ['line', 'endLine', 'column', 'message', 'severity', 'confidence'];

    /**
     * Holds one baseline identity with an occurrence count and optional descriptive fields.
     *
     * @param string $identity - Line-free lookup key: 16 lowercase hex characters from BaselineIdentity.
     * @param int    $count - Positive occurrence count represented by this row; stored baseline rows use it as the reviewed allowance.
     * @param string $ruleId - Descriptive rule id for readers; empty when the row does not provide one.
     * @param string $path - Descriptive project-relative path; empty when absent from the row.
     * @param string $subject - Descriptive identity subject, so a reviewer sees what was reviewed; empty when absent.
     */
    public function __construct(
        public string $identity,
        public int    $count,
        public string $ruleId = '',
        public string $path = '',
        public string $subject = '',
    ) {
    }

    /**
     * Decodes a reviewed row, validating its identity and count and rejecting forbidden fields.
     *
     * @param array<string, bool|float|int|string|null> $occurrenceRow - Decoded occurrence fields to validate.
     * @param int $index - Zero-based position included in validation errors so the caller can locate the row.
     *
     * @return self - Validated row ready to match against live findings.
     * @throws BaselineException - If identity is not 16 lowercase hex characters, count is not a positive integer, or a forbidden key exists.
     */
    public static function fromArray(array $occurrenceRow, int $index): self
    {
        $identity = $occurrenceRow['identity'] ?? null;

        // Only the ratified digest shape can match live findings, so a malformed identity is refused rather than guessed at.
        if (!is_string($identity) || preg_match('/^[0-9a-f]{16}$/', $identity) !== 1) {
            throw new BaselineException(sprintf('Baseline occurrences[%d].identity must be 16 lowercase hex characters.', $index));
        }

        $count = $occurrenceRow['count'] ?? null;

        // A non-integer or non-positive count cannot represent reviewed debt, so the row is rejected.
        if (!is_int($count) || $count < 1) {
            throw new BaselineException(sprintf('Baseline occurrences[%d].count must be a positive integer.', $index));
        }

        // Baseline rows exclude positional and diagnostic fields; suppression uses the stored identity and reviewed count.
        foreach (self::FORBIDDEN_KEYS as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $occurrenceRow)) {
                throw new BaselineException(sprintf('Baseline occurrences[%d] carries forbidden key "%s".', $index, $forbiddenKey));
            }
        }

        return new self(
            identity: $identity,
            count:    $count,
            ruleId:   self::optionalText($occurrenceRow, 'ruleId'),
            path:     self::optionalText($occurrenceRow, 'path'),
            subject:  self::optionalText($occurrenceRow, 'subject'),
        );
    }

    /**
     * Flattens the row into the JSON object "analyse --generate-baseline" writes, omitting empty descriptive fields.
     *
     * @return array{identity: string, count: int, ruleId?: string, path?: string, subject?: string} - Row with nonempty descriptive fields.
     */
    public function toArray(): array
    {
        $occurrenceRow = ['identity' => $this->identity, 'count' => $this->count];

        // Descriptive fields are for readers only, so an absent one is left out rather than written as an empty string.
        foreach (['ruleId' => $this->ruleId, 'path' => $this->path, 'subject' => $this->subject] as $key => $fieldText) {
            if ($fieldText !== '') {
                $occurrenceRow[$key] = $fieldText;
            }
        }

        return $occurrenceRow;
    }

    /**
     * Formats one resolved baseline entry for human-readable reports, falling back to its hex identity when descriptive fields are absent.
     *
     * @param bool $quoteCode - When true, wraps the leading rule ID (or fallback identity) in Markdown backticks.
     *
     * @return string - Formatted summary line such as `ruleId path (resolved 1): subject` or `identity (resolved 1)`.
     */
    public function resolvedSummary(bool $quoteCode = false): string
    {
        $prefixParts = [];
        // A minimal v3 row may omit its rule, so the summary shows only the fields the row carries.
        if ($this->ruleId !== '') {
            $prefixParts[] = $quoteCode ? sprintf('`%s`', $this->ruleId) : $this->ruleId;
        }

        // A row without a path is summarised without an empty location.
        if ($this->path !== '') {
            $prefixParts[] = $this->path;
        }

        // Minimal v3 baseline rows carry only identity and count, so fall back to the identity when descriptive fields are absent.
        if ($prefixParts === []) {
            $prefixParts[] = $quoteCode ? sprintf('`%s`', $this->identity) : $this->identity;
        }

        $summary = sprintf('%s (resolved %d)', implode(' ', $prefixParts), $this->count);

        return $this->subject !== '' ? sprintf('%s: %s', $summary, $this->subject) : $summary;
    }

    /**
     * Returns a descriptive field when it is textual, treating absent or non-string values as empty.
     *
     * @param array<string, bool|float|int|string|null> $occurrenceRow - Decoded baseline occurrence row containing optional descriptive fields.
     * @param string                                    $key - One of ruleId, path, or subject.
     *
     * @return string - The text, or an empty string when the field is absent or not a string.
     */
    private static function optionalText(array $occurrenceRow, string $key): string
    {
        $fieldText = $occurrenceRow[$key] ?? null;

        return is_string($fieldText) ? $fieldText : '';
    }
}
