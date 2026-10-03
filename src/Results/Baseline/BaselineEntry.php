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
     * Holds one reviewed identity exactly as it sits in the baseline file.
     *
     * @param string $identity - 16 lowercase hex characters from `BaselineIdentity`; the only field matching reads.
     * @param int    $count - Occurrences the team accepted, at least one; an extra occurrence beyond it surfaces as new.
     * @param string $ruleId - Descriptive rule id for readers; empty when the row was hand-written without one.
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
     * Rebuilds one reviewed row from a decoded `gruff-baseline.json` occurrence, refusing anything that could expire or leak.
     *
     * @param array<string, bool|float|int|string|null> $occurrenceRow - Decoded occurrence object; a hand-edited file may carry anything.
     * @param int                                       $index - Zero-based position, named in the error so the user can find the row.
     *
     * @return self - Validated row ready to match against live findings.
     * @throws BaselineException When the identity is not 16 hex characters, the count is below one, or a forbidden key is present.
     */
    public static function fromArray(array $occurrenceRow, int $index): self
    {
        $identity = $occurrenceRow['identity'] ?? null;

        // An identity that is not the ratified digest shape cannot have come from a generator, so the row is refused rather than guessed at.
        if (!is_string($identity) || preg_match('/^[0-9a-f]{16}$/', $identity) !== 1) {
            throw new BaselineException(sprintf('Baseline occurrences[%d].identity must be 16 lowercase hex characters.', $index));
        }

        $count = $occurrenceRow['count'] ?? null;

        // A count below one would mean a reviewed identity that suppresses nothing, which is a hand edit gone wrong.
        if (!is_int($count) || $count < 1) {
            throw new BaselineException(sprintf('Baseline occurrences[%d].count must be a positive integer.', $index));
        }

        // A positional or re-classifiable field in a row is how a 0.5 baseline expired on every edit, so its presence fails the file.
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
     * Flattens the row into the JSON object `analyse --generate-baseline` writes, omitting empty descriptive fields.
     *
     * @return array{identity: string, count: int, ruleId?: string, path?: string, subject?: string} - Identity and count first, then whatever descriptive fields exist.
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
     * Reads one optional descriptive string from a decoded row.
     *
     * @param array<string, bool|float|int|string|null> $occurrenceRow - Decoded occurrence object.
     * @param string                                    $key - Field to read.
     *
     * @return string - The text, or an empty string when the field is absent or not a string.
     */
    private static function optionalText(array $occurrenceRow, string $key): string
    {
        $fieldText = $occurrenceRow[$key] ?? null;

        return is_string($fieldText) ? $fieldText : '';
    }
}
