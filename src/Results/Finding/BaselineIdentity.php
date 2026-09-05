<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

use GruffPhp\Results\Baseline\BaselineException;

/**
 * The one line-free identity a baseline stores for a finding, ratified for the whole family in contracts/core/finding-identity.v1.json.
 *
 * When a user runs `gruff-php analyse --generate-baseline`, every ordinary finding is named by this identity and nothing positional.
 * On the next `analyse --baseline`, a finding that moved lines still matches, while a new sibling of the same rule never inherits the review.
 *
 * Three decisions live here:
 * - a symbol-bearing finding is named by its symbol plus a declaration ordinal, so two same-named methods in one file stay apart;
 * - a finding naming no symbol falls back to its message with measured values normalised, the only stable thing a file-level finding has;
 * - a sensitive finding receives no identity at all, because a stored identity is what would let a review hide a secret.
 */
final readonly class BaselineIdentity
{
    /**
     * Token gruff-php contributes to every identity, so the same rule name on the same path never collides with another port's.
     */
    public const TOOL_LANGUAGE = 'php';

    /**
     * Joins a symbol to its declaration ordinal; a symbol containing it could forge another symbol's ordinal, so such a symbol gets no identity.
     */
    private const ORDINAL_SEPARATOR = '#';

    /**
     * Every number a message can state: a length, a count, a percentage, or a version, including `.` and `,` groups such as 1,234 or 12.5.
     */
    private const MEASURED_VALUE_PATTERN = '/[0-9]+(?:[.,][0-9]+)*/';

    /**
     * What every measured value becomes in a subject, so a file that grew from 1010 to 1200 lines keeps the identity the user reviewed.
     */
    private const MEASURED_VALUE_PLACEHOLDER = '#';

    /**
     * Tells whether a finding may ever receive a baseline identity.
     *
     * A sensitive finding never does: it stays visible and blocking on every run until the user fixes it or excludes it with a written reason.
     *
     * @param Finding $finding - Any finding from the current scan.
     *
     * @return bool - True for an ordinary finding; false for a sensitive-data finding, which the baseline counts but never stores or hides.
     */
    public static function isEligible(Finding $finding): bool
    {
        return $finding->pillar !== Pillar::SensitiveData && !str_starts_with($finding->ruleId, 'sensitive-data.');
    }

    /**
     * Replaces every measured value in a message with `#`, per the identity amendment of 2026-09-05.
     *
     * "File has 1010 lines (limit 1000)" becomes "File has # lines (limit #)", so a file that grows keeps the identity the user reviewed.
     *
     * @param string $message - Finding message as the rule emitted it; it may state a length, count, percentage, or version.
     *
     * @return string - The message with each run of digits, including `.` or `,` groups, replaced by `#`; unchanged when it has none.
     */
    public static function normaliseMeasuredValues(string $message): string
    {
        return (string)preg_replace(self::MEASURED_VALUE_PATTERN, self::MEASURED_VALUE_PLACEHOLDER, $message);
    }

    /**
     * Builds the identity subject: `symbol#ordinal` for a symbol-bearing finding, otherwise the message with measured values normalised.
     *
     * @param Finding $finding - Finding to name; a symbol-bearing finding needs the ordinal `assignOrdinals()` ranked for it.
     * @param int     $ordinal - 1-based declaration ordinal among same-named symbols in the file; ignored for a symbol-less finding.
     *
     * @return string - The subject the identity hashes.
     * @throws BaselineException When a symbol-bearing finding has no ordinal, or its symbol contains the ordinal separator.
     */
    public static function subject(Finding $finding, int $ordinal): string
    {
        $symbol = $finding->symbol;

        // A file-level finding has nothing but its message to name it: its measurement is stripped, and a reworded message is a new finding by design.
        if ($symbol === null || $symbol === '') {
            return self::normaliseMeasuredValues($finding->message);
        }

        // A symbol carrying the separator could pose as another symbol's ordinal, so it is refused rather than hashed ambiguously.
        $findingLabel = "Finding {$finding->ruleId} in {$finding->filePath} has symbol \"{$symbol}\"";

        if (str_contains($symbol, self::ORDINAL_SEPARATOR)) {
            throw new BaselineException($findingLabel . ' containing "' . self::ORDINAL_SEPARATOR . '".');
        }

        // Defaulting a missing ordinal to 1 would merge two same-named methods back together, the collision the ordinal exists to prevent.
        if ($ordinal < 1) {
            throw new BaselineException($findingLabel . ' without a declaration ordinal.');
        }

        return $symbol . self::ORDINAL_SEPARATOR . $ordinal;
    }

    /**
     * Computes the identity gruff-php stores for one finding: sha256 over the NUL-separated tool language, rule id, path, and subject, first 16 hex.
     *
     * @param Finding $finding - Ordinary finding to identify; a sensitive finding must never reach this method.
     * @param int     $ordinal - Declaration ordinal from `assignOrdinals()`; 0 is only valid for a symbol-less finding.
     *
     * @return string - 16 lowercase hex characters; identical input on any port with the same tool language yields the same value.
     * @throws BaselineException When the subject cannot be built.
     */
    public static function identityOf(Finding $finding, int $ordinal): string
    {
        return self::computeFor(self::TOOL_LANGUAGE, $finding->ruleId, $finding->filePath, self::subject($finding, $ordinal));
    }

    /**
     * Hashes the ratified identity under an explicit tool language.
     *
     * Conformance tests use it to reproduce the digests the family oracle pins for other ports, which is the only proof the rule is one rule.
     *
     * @param string $toolLanguage - One of go, php, py, rs, or ts.
     * @param string $ruleId - Native rule id, never a concept id.
     * @param string $path - Project-relative POSIX path.
     * @param string $subject - Subject from `subject()`.
     *
     * @return string - 16 lowercase hex characters.
     */
    public static function computeFor(string $toolLanguage, string $ruleId, string $path, string $subject): string
    {
        return substr(hash('sha256', implode("\0", [$toolLanguage, $ruleId, $path, $subject])), 0, 16);
    }

    /**
     * Ranks each symbol-bearing finding's declaration among same-named symbols in its file.
     *
     * PHP refuses to declare a class, method, function, property, or constant twice in one file, so every finding on such a symbol is ordinal 1:
     * two findings on `Widget::process()` at different lines are one declaration, and inserting code above it moves nothing.
     * Only a variable symbol such as `$value` can be declared in several places, so those are ranked by the line of each occurrence.
     *
     * @param list<Finding> $findings - One run's findings.
     *
     * @return array<int, int> - `spl_object_id` of each finding to its 1-based ordinal; a symbol-less finding maps to 0.
     */
    public static function assignOrdinals(array $findings): array
    {
        $positionsBySymbol = [];
        $positionByFinding = [];

        // Collect every distinct declaration position each (file, symbol) pair occupies before any rank is assigned.
        foreach ($findings as $finding) {
            $symbol = $finding->symbol;

            if ($symbol === null || $symbol === '') {
                continue;
            }

            $key      = $finding->filePath . "\0" . $symbol;
            $position = self::declarationPosition($finding);

            $positionByFinding[spl_object_id($finding)] = $position;
            $positionsBySymbol[$key][$position]         = true;
        }

        $ordinals = [];

        // Rank positions in file order so the first declaration of a name is ordinal 1, the next 2, and so on.
        foreach ($findings as $finding) {
            $findingId = spl_object_id($finding);

            if (!isset($positionByFinding[$findingId])) {
                $ordinals[$findingId] = 0;
                continue;
            }

            $positions = array_keys($positionsBySymbol[$finding->filePath . "\0" . (string)$finding->symbol]);
            sort($positions);
            $rank = array_search($positionByFinding[$findingId], $positions, true);

            $ordinals[$findingId] = ($rank === false ? 0 : $rank) + 1;
        }

        return $ordinals;
    }

    /**
     * Names the declaration position a symbol-bearing finding sits on, the value both ordinals and collision detection rank by.
     *
     * A class, method, function, property, or constant symbol is declared once per file, so every finding on it shares position 1.
     * A variable symbol can be bound in several places, so its position is the line of the finding itself.
     *
     * @param Finding $finding - Finding with a non-empty symbol.
     *
     * @return int - Declaration position; 1 for a declaration PHP declares once per file, otherwise the finding's line.
     */
    public static function declarationPosition(Finding $finding): int
    {
        return self::isVariableSymbol((string)$finding->symbol) ? ($finding->line ?? 1) : 1;
    }

    /**
     * Tells whether a symbol names a variable, the one kind of symbol PHP lets a file declare more than once.
     *
     * @param string $symbol - Symbol as the rule emitted it, such as `$value` or `Widget::process()`.
     *
     * @return bool - True for a plain variable; false for a class, method, function, property, or constant symbol.
     */
    private static function isVariableSymbol(string $symbol): bool
    {
        return str_starts_with($symbol, '$');
    }
}
