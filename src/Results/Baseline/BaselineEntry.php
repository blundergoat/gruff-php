<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\Finding;

/**
 * One row of the user's committed gruff-baseline.json: an accepted finding identity plus how many
 * instances the team signed off on. These rows are what keep `analyse --baseline` green on known
 * debt while anything new still fails the run.
 */
final readonly class BaselineEntry
{
    /**
     * Capture one accepted-debt group keyed by file, rule, and message.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string $filePath - Display path shared by every instance in the group.
     * @param string $ruleId - Rule identifier that produced the findings.
     * @param string $message - Exact finding message shared by every instance in the group.
     * @param int    $count - Accepted instance count for the group; always at least one.
     */
    public function __construct(
        public string $filePath,
        public string $ruleId,
        public string $message,
        public int    $count,
    ) {
    }

    /**
     * Build the line-insensitive group key a live finding matches against.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param Finding $finding - Live analysis finding to key.
     *
     * @return string - the same (file, ruleId, message) key groupKey() builds for persisted entries
     */
    public static function groupKeyForFinding(Finding $finding): string
    {
        return self::groupKeyFor($finding->filePath, $finding->ruleId, $finding->message);
    }

    /**
     * Build the group key for raw identity fields.
     *
     * Lines and columns are deliberately left out: the user can reformat or insert
     * code above accepted debt without their committed baseline breaking.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
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
     * Return this entry's group key.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @return string - the (file, ruleId, message) key used to index this group against live findings
     */
    public function groupKey(): string
    {
        return self::groupKeyFor($this->filePath, $this->ruleId, $this->message);
    }

    /**
     * Substitute invalid UTF-8 bytes the same way the JSON write path does.
     *
     * The baseline file stores substituted text, so keying live findings through the
     * same substitution means a weird byte in a message can never un-match the
     * user's accepted debt.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string $identityText - Raw identity field text (file path, rule id, or message).
     *
     * @return string - the text unchanged when already valid UTF-8, else with invalid byte sequences replaced by U+FFFD
     */
    private static function utf8Substituted(string $identityText): string
    {
        // The empty //u pattern is the canonical fast probe: it matches exactly when the subject
        // is already valid UTF-8, which is the normal case needing no substitution work.
        // User view: choose the baseline feedback branch for this case.
        if (preg_match('//u', $identityText) === 1) {
            return $identityText;
        }

        $encoded = json_encode($identityText, JSON_INVALID_UTF8_SUBSTITUTE);
        // Encoding can only fail on non-UTF-8 pathologies; keep the raw text rather than lose the key.
        // User view: choose the baseline feedback branch for this case.
        if ($encoded === false) {
            return $identityText;
        }

        $decoded = json_decode($encoded);

        return is_string($decoded) ? $decoded : $identityText;
    }

    /**
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param array<string, bool|float|int|string|null> $baselineRow - Serialized baseline group row decoded from JSON.
     * @param int                                       $index - Zero-based baseline row position for error messages.
     *
     * @return self - baseline group rebuilt from a validated on-disk row, ready to match against live findings
     * @throws BaselineException When required fields are missing or malformed.
     */
    public static function fromArray(array $baselineRow, int $index): self
    {
        // Check each identity field in turn: a hand-edited baseline should fail loudly, not half-match.
        // User view: add each item that can appear in baseline feedback.
        foreach (['file', 'ruleId', 'message'] as $key) {
            // A missing or empty field means the row can never match a finding, so name it for the user.
            // User view: choose the baseline feedback branch for this case.
            // User view: an empty value becomes a clear baseline feedback fallback.
            if (!isset($baselineRow[$key]) || !is_string($baselineRow[$key]) || $baselineRow[$key] === '') {
                throw new BaselineException(sprintf('Baseline group %d must include non-empty "%s".', $index, $key));
            }
        }

        // User view: missing data becomes a safe baseline feedback default.
        $count = $baselineRow['count'] ?? null;
        // A zero or negative count could never suppress anything, so reject the row as malformed.
        // User view: choose the baseline feedback branch for this case.
        if (!is_int($count) || $count < 1) {
            throw new BaselineException(sprintf('Baseline group %d field "count" must be an integer of at least 1.', $index));
        }

        // Row has passed every field guard above, so build the group from the now-trusted values.
        return new self(
            filePath: $baselineRow['file'],
            ruleId:   $baselineRow['ruleId'],
            message:  $baselineRow['message'],
            count:    $count,
        );
    }

    /**
     * Serialize this value object into the array shape used by baseline files and reports.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @return array{file: string, ruleId: string, message: string, count: int} - JSON-ready baseline group row; "file" holds the display path, and
     *                            in absent/resolved report rows "count" carries the resolved instance count instead of the accepted count
     */
    public function toArray(): array
    {
        // On-disk baseline JSON keys ("file" for the file path) that fromArray() reads back.
        return [
            'file'    => $this->filePath,
            'ruleId'  => $this->ruleId,
            'message' => $this->message,
            'count'   => $this->count,
        ];
    }
}
