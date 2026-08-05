<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Size;

use GruffPhp\Engine\Parser\AnalysisUnit;

/**
 * Shared substantive-line counting for the size rules: blank lines and comment-only lines are free
 * (family ratification, 2026-08-05), so required documentation can never push a file or class over
 * a size budget.
 *
 * Comment tokens from the parser are masked out of the source (newlines preserved) before counting
 * non-blank lines, so a line holding both code and a trailing comment still counts. Parse-failed
 * units have no tokens and fall back to counting non-blank raw lines.
 */
final readonly class SubstantiveLineCounter
{
    /**
     * Counts the substantive lines of the whole unit.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose source and comment tokens are inspected.
     *
     * @return int - Number of non-blank lines after comment masking.
     */
    public static function countAll(AnalysisUnit $analysisUnit): int
    {
        return self::countLines(self::maskedLines($analysisUnit));
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
        $lines = self::maskedLines($analysisUnit);
        $slice = array_slice($lines, max(0, $startLine - 1), max(0, $endLine - $startLine + 1));

        return self::countLines($slice);
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
        $masked = $analysisUnit->source;

        // Blank each comment token in place so comment-only lines become whitespace-only lines.
        foreach ($analysisUnit->tokens as $token) {
            if (!$token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }
            $blanked = preg_replace('/[^\n]/', ' ', $token->text) ?? $token->text;
            $masked  = substr_replace($masked, $blanked, $token->pos, strlen($token->text));
        }

        return explode("\n", $masked);
    }

    /**
     * Counts the lines that still carry visible characters after masking.
     *
     * @param list<string> $lines - Masked source lines to count.
     *
     * @return int - Number of non-blank lines.
     */
    private static function countLines(array $lines): int
    {
        $count = 0;

        foreach ($lines as $line) {
            // A line is substantive when anything beyond whitespace survives the comment mask.
            if (trim($line) !== '') {
                ++$count;
            }
        }

        return $count;
    }
}
