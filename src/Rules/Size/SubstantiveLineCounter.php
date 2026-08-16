<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Size;

use GruffPhp\Engine\Parser\AnalysisUnit;
use WeakMap;

/**
 * Shared substantive-line counting for the size rules: blank lines and comment-only lines are free
 * (family ratification, 2026-08-05), so required documentation can never push a file or class over
 * a size budget.
 *
 * Comment tokens from the parser are masked out of the source (newlines preserved) before counting
 * non-blank lines, so a line holding both code and a trailing comment still counts. Units without
 * parser tokens fall back to counting non-blank raw lines; syntax-error units can retain tokens and
 * receive the same comment masking as successfully parsed source.
 */
final class SubstantiveLineCounter
{
    /**
     * Cumulative substantive-line counts keyed by analysis-unit identity.
     *
     * @var WeakMap<AnalysisUnit, list<int>>|null
     */
    private static ?WeakMap $prefixCache = null;

    /**
     * Counts the substantive lines of the whole unit.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose source and comment tokens are inspected.
     *
     * @return int - Number of non-blank lines after comment masking.
     */
    public static function countAll(AnalysisUnit $analysisUnit): int
    {
        $prefix = self::substantiveLinePrefix($analysisUnit);

        return $prefix[count($prefix) - 1];
    }

    /**
     * Counts the substantive lines inside one inclusive 1-based line range.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose source and comment tokens are inspected.
     * @param int          $startLine    - First line of the range, 1-based inclusive.
     * @param int          $endLine      - Last line of the range, 1-based inclusive.
     *
     * @return int - Number of non-blank lines after comment masking within the range.
     */
    public static function countRange(AnalysisUnit $analysisUnit, int $startLine, int $endLine): int
    {
        $prefix          = self::substantiveLinePrefix($analysisUnit);
        $lineCount       = count($prefix) - 1;
        $startIndex      = max(0, $startLine - 1);
        $requestedLength = max(0, $endLine - $startLine + 1);

        // Preserve the original array-slice bounds: an empty or out-of-source range contributes nothing.
        if ($requestedLength === 0 || $startIndex >= $lineCount) {
            return 0;
        }

        $endIndex = min($lineCount, $startIndex + $requestedLength);

        return $prefix[$endIndex] - $prefix[$startIndex];
    }

    /**
     * Drops the cached prefix for a unit whose source is about to be released.
     *
     * @param AnalysisUnit $analysisUnit - Unit to remove from the substantive-line cache.
     *
     * @return void
     */
    public static function evictUnit(AnalysisUnit $analysisUnit): void
    {
        // The lightweight unit shell can outlive its source, so remove the source-derived prefix explicitly.
        if (self::$prefixCache !== null) {
            unset(self::$prefixCache[$analysisUnit]);
        }
    }

    /**
     * Builds and memoises cumulative substantive-line counts for one unit.
     *
     * Prefix index zero is always zero; index N stores the substantive count through source line N.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose comment-masked source is counted.
     *
     * @return list<int> - Cumulative substantive-line counts with a leading zero.
     */
    private static function substantiveLinePrefix(AnalysisUnit $analysisUnit): array
    {
        self::$prefixCache ??= new WeakMap();
        $cached = self::$prefixCache[$analysisUnit] ?? null;
        if ($cached !== null) {
            // A hit means this unit's mask and every range count are already represented by the prefix.
            return $cached;
        }

        $count  = 0;
        $prefix = [0];

        foreach (self::maskedLines($analysisUnit) as $line) {
            // A line is substantive when anything beyond whitespace survives the comment mask.
            if (trim($line) !== '') {
                ++$count;
            }
            $prefix[] = $count;
        }

        self::$prefixCache[$analysisUnit] = $prefix;

        return $prefix;
    }

    /**
     * Splits the comment-masked source into lines shared by both counting entry points.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose comment tokens are blanked in place.
     *
     * @return list<string> - Source lines with comment token text replaced by spaces.
     */
    private static function maskedLines(AnalysisUnit $analysisUnit): array
    {
        $masked       = '';
        $sourceOffset = 0;

        // Copy source and masked-comment spans in order so each source byte is visited at most once.
        foreach ($analysisUnit->tokens as $token) {
            if (!$token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }

            $tokenLength = strlen($token->text);
            $masked .= substr($analysisUnit->source, $sourceOffset, $token->pos - $sourceOffset);
            $masked .= preg_replace('/[^\n]/', ' ', $token->text) ?? $token->text;
            $sourceOffset = $token->pos + $tokenLength;
        }

        // Preserve the untouched tail after the final comment token.
        $masked .= substr($analysisUnit->source, $sourceOffset);

        return explode("\n", $masked);
    }
}
