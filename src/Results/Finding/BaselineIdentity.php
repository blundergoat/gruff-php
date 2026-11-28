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
     * Checks whether the finding is eligible for baseline review.
     * Sensitive-data findings are excluded by pillar or rule-ID prefix and cannot be hidden by a baseline.
     *
     * @param Finding $finding - Any finding from the current scan.
     *
     * @return bool - True unless the pillar is SensitiveData or the rule ID starts with `sensitive-data.`.
     */
    public static function isEligible(Finding $finding): bool
    {
        return $finding->pillar !== Pillar::SensitiveData && !str_starts_with($finding->ruleId, 'sensitive-data.');
    }

    /**
     * Checks baseline eligibility and rejects symbols containing the reserved ordinal separator.
     * Null and empty symbols remain eligible for message-based identities.
     *
     * @param Finding $finding - Any finding from the current scan.
     *
     * @return bool - True for an eligible finding whose symbol contains no `#`; false otherwise.
     */
    public static function hasIdentity(Finding $finding): bool
    {
        return self::isEligible($finding) && !str_contains($finding->symbol ?? '', self::ORDINAL_SEPARATOR);
    }

    /**
     * Replaces numeric tokens in a finding message with the # placeholder before identity hashing.
     *
     * "File has 1010 lines (limit 1000)" becomes "File has # lines (limit #)", so a file that grows keeps the identity the user reviewed.
     *
     * @param string $message - Finding message as the rule emitted it; it may state a length, count, percentage, or version.
     *
     * @return string - The message with digit runs, including dot or comma groups, replaced by the # placeholder; unchanged when none occur.
     */
    public static function normaliseMeasuredValues(string $message): string
    {
        // A dotted version such as 1.2.3 becomes one # because its groups belong to a single match.
        return (string)preg_replace(self::MEASURED_VALUE_PATTERN, self::MEASURED_VALUE_PLACEHOLDER, $message);
    }

    /**
     * Builds the identity subject: `symbol#ordinal` for a symbol-bearing finding, otherwise the message with measured values normalised.
     *
     * @param Finding $finding - Finding to name; a symbol-bearing finding needs the ordinal `assignOrdinals()` ranked for it.
     * @param int     $ordinal - 1-based declaration ordinal among same-named symbols in the file; ignored for a symbol-less finding.
     *
     * @return string - The subject the identity hashes.
     * @throws BaselineException - When a symbol contains `#` or its ordinal is less than 1.
     */
    public static function subject(Finding $finding, int $ordinal): string
    {
        $symbol = $finding->symbol;

        // Null or empty symbols use the normalised message; rewording that message changes the identity.
        if ($symbol === null || $symbol === '') {
            return self::normaliseMeasuredValues($finding->message);
        }

        // A symbol carrying the separator could pose as another symbol's ordinal, so it is refused rather than hashed ambiguously.
        $findingLabel = "Finding {$finding->ruleId} in {$finding->filePath} has symbol \"{$symbol}\"";

        if (str_contains($symbol, self::ORDINAL_SEPARATOR)) {
            throw new BaselineException($findingLabel . ' containing "' . self::ORDINAL_SEPARATOR . '".');
        }

        // Treating a missing ordinal as 1 could merge this finding with the first declaration of the same symbol.
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
     * @throws BaselineException - When the subject cannot be built.
     */
    public static function identityOf(Finding $finding, int $ordinal): string
    {
        return self::computeFor(self::TOOL_LANGUAGE, $finding->ruleId, $finding->filePath, self::subject($finding, $ordinal));
    }

    /**
     * Hashes the ratified identity under an explicit tool language.
     *
     * Accepts the language explicitly so callers can reproduce identities for different ports.
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
     * Non-variable symbols use ordinal 1; variable symbols are ranked by finding lines and optional inventory positions.
     * Symbol-less findings receive 0.
     *
     * @param list<Finding>             $findings - One run's findings.
     * @param DeclarationInventory|null $inventory - Scanned variable occurrences per file; null ranks variables among their findings alone.
     *
     * @return array<int, int> - spl_object_id of each finding to its 1-based ordinal; a symbol-less finding maps to 0.
     */
    public static function assignOrdinals(array $findings, ?DeclarationInventory $inventory = null): array
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

            // Every scanned occurrence of a variable takes a slot, whether or not it warned this run.
            if ($inventory instanceof DeclarationInventory && self::isVariableSymbol($symbol)) {
                foreach ($inventory->positionsFor($finding->filePath, $symbol) as $declared) {
                    $positionsBySymbol[$key][$declared] = true;
                }
            }
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
     * Returns the position used to rank ordinals and detect collisions.
     * Non-variable symbols use position 1; variable symbols use the finding line.
     *
     * @param Finding $finding - Finding with a non-empty symbol.
     *
     * @return int - Position used for ranking: 1 for a non-variable symbol, otherwise the finding's line.
     */
    public static function declarationPosition(Finding $finding): int
    {
        return self::isVariableSymbol((string)$finding->symbol) ? ($finding->line ?? 1) : 1;
    }

    /**
     * Selects position-based ordinal handling for emitted symbols that start with a dollar sign.
     *
     * @param string $symbol - Symbol as the rule emitted it, such as $value or Widget::process().
     *
     * @return bool - True when the symbol starts with a dollar sign; false otherwise.
     */
    private static function isVariableSymbol(string $symbol): bool
    {
        return str_starts_with($symbol, '$');
    }
}
