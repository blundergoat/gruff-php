<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\Finding;

/**
 * One row of the user's committed `gruff-baseline.json`: an accepted-debt group built from a finding's
 * identity (its file, rule, and message) plus how many instances the team signed off on. Rows like this
 * are written by `analyse --generate-baseline` and read back on every later `analyse --baseline`.
 *
 * Their job is to keep a scan green on debt the team already reviewed while still failing the moment
 * something genuinely new turns up. The identity deliberately ignores line and column numbers, so
 * everyday reformatting doesn't break a baseline the team already signed off (see `groupKeyFor`).
 */
final readonly class BaselineEntry
{
    /**
     * Holds one accepted-debt group exactly as it sits in the user's baseline file, keyed by the file,
     * rule, and message a live finding must match before `analyse --baseline` will suppress it.
     *
     * @param string $filePath - Display path shared by every instance in the group.
     * @param string $ruleId - Rule identifier that produced the accepted findings.
     * @param string $message - Exact finding message shared by every instance in the group.
     * @param int    $count - Instances the team accepted; at least one. The filter suppresses only this many, so an extra instance beyond the count surfaces as new.
     */
    public function __construct(
        public string $filePath,
        public string $ruleId,
        public string $message,
        public int    $count,
    ) {
    }

    /**
     * Turns a finding from the current scan into the same group key its accepted-debt row would carry,
     * which is how `analyse --baseline` tells whether the user already signed this finding off.
     *
     * @param Finding $finding - Live analysis finding from the current scan to reduce to its group key.
     *
     * @return string - the same (file, ruleId, message) key groupKey() builds for persisted rows, so equal keys mean accepted debt
     */
    public static function groupKeyForFinding(Finding $finding): string
    {
        return self::groupKeyFor($finding->filePath, $finding->ruleId, $finding->message);
    }

    /**
     * Builds the group key shared by persisted rows and live findings alike from three raw identity fields.
     *
     * Lines and columns are deliberately left out, so the user can reformat a file or insert code above
     * accepted debt without their committed baseline breaking and re-flagging findings they already accepted.
     *
     * @param string $filePath - Display path for the group.
     * @param string $ruleId - Rule identifier for the group.
     * @param string $message - Finding message for the group.
     *
     * @return string - NUL-joined (file, ruleId, message) key with every part UTF-8-substituted, so on-disk rows and
     *                raw in-memory findings produce identical keys
     */
    public static function groupKeyFor(string $filePath, string $ruleId, string $message): string
    {
        return self::utf8Substituted($filePath) . "\0" . self::utf8Substituted($ruleId) . "\0" . self::utf8Substituted($message);
    }

    /**
     * Hands back this stored entry's own group key, the value `analyse --baseline` indexes it under so
     * findings from the current scan can be looked up against the team's accepted debt.
     *
     * @return string - the (file, ruleId, message) key used to index this accepted-debt group against live findings
     */
    public function groupKey(): string
    {
        return self::groupKeyFor($this->filePath, $this->ruleId, $this->message);
    }

    /**
     * Cleans invalid UTF-8 bytes out of an identity field exactly the way the JSON write path does.
     *
     * The baseline on disk only ever holds substituted text, so a live finding whose message carries a
     * stray non-UTF-8 byte has to be run through the same substitution here; otherwise that one odd byte
     * would make it miss the user's accepted-debt row and get re-flagged as new.
     *
     * @param string $identityText - Raw identity field text (file path, rule id, or message).
     *
     * @return string - the text unchanged when already valid UTF-8, else with invalid byte sequences replaced by U+FFFD
     */
    private static function utf8Substituted(string $identityText): string
    {
        // The empty `//u` pattern is the canonical fast probe: it matches only when the subject is
        // already valid UTF-8, which is the common case where the user's text needs no cleaning at all.
        if (preg_match('//u', $identityText) === 1) {
            return $identityText;
        }

        $encoded = json_encode($identityText, JSON_INVALID_UTF8_SUBSTITUTE);
        // With substitution on, `json_encode` replaces invalid bytes rather than failing, so this false is rare; keep the raw text if it happens so the user still gets a usable key.
        if ($encoded === false) {
            return $identityText;
        }

        $decoded = json_decode($encoded);

        // Use the cleaned string only when the round-trip really handed one back; on anything else keep the raw text.
        return is_string($decoded) ? $decoded : $identityText;
    }

    /**
     * Rebuilds one accepted-debt group from a decoded `gruff-baseline.json` row, validating every identity
     * field first so a hand-edited or corrupt baseline fails loudly instead of quietly suppressing the wrong
     * findings on the next `analyse --baseline`.
     *
     * @param array<string, bool|float|int|string|null> $baselineRow - Serialized baseline group row decoded from JSON.
     * @param int                                       $index - Zero-based baseline row position for error messages.
     *
     * @return self - baseline group rebuilt from a validated on-disk row, ready to match against live findings
     * @throws BaselineException When required fields are missing or malformed.
     */
    public static function fromArray(array $baselineRow, int $index): self
    {
        // Validate the identity fields in order so a hand-edited baseline names the first bad field on load, rather than half-building a dead row.
        foreach (['file', 'ruleId', 'message'] as $key) {
            // A field that is missing, non-string, or empty can never line up with a real finding, so name it and stop rather than load a dead row.
            if (!isset($baselineRow[$key]) || !is_string($baselineRow[$key]) || $baselineRow[$key] === '') {
                throw new BaselineException(sprintf('Baseline group %d must include non-empty "%s".', $index, $key));
            }
        }

        $count = $baselineRow['count'] ?? null;
        // A count someone hand-edited to zero or a negative could never suppress anything, so treat the row as malformed and say which one.
        if (!is_int($count) || $count < 1) {
            throw new BaselineException(sprintf('Baseline group %d field "count" must be an integer of at least 1.', $index));
        }

        // Every field guard above has passed, so build the group from values we can now trust.
        return new self(
            filePath: $baselineRow['file'],
            ruleId:   $baselineRow['ruleId'],
            message:  $baselineRow['message'],
            count:    $count,
        );
    }

    /**
     * Flattens this entry back into the array shape written to `gruff-baseline.json` and echoed in reports,
     * the inverse of `fromArray()` so a row can round-trip from disk and back unchanged.
     *
     * @return array{file: string, ruleId: string, message: string, count: int} - JSON-ready baseline group row; "file" holds the display path, and
     *                            in absent/resolved report rows "count" carries the resolved instance count instead of the accepted count
     */
    public function toArray(): array
    {
        // Emit the on-disk JSON keys, including "file" for the display path, exactly as `fromArray()` reads them back.
        return [
            'file'    => $this->filePath,
            'ruleId'  => $this->ruleId,
            'message' => $this->message,
            'count'   => $this->count,
        ];
    }
}
