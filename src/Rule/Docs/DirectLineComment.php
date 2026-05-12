<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Parser\AnalysisUnit;

/**
 * Checks whether a regular line comment appears directly above a statement.
 */
final readonly class DirectLineComment
{
    /**
     * Check whether a standalone one-line comment exists directly above a line.
     *
     * @param AnalysisUnit $unit Parsed unit whose source should be inspected.
     * @param int          $line Source line that needs a preceding comment.
     * @return bool True when the previous line is a direct one-line comment token.
     */
    public static function existsAbove(AnalysisUnit $unit, int $line): bool
    {
        if ($line <= 1) {
            return false;
        }

        $commentLine = $line - 1;
        $lineText    = self::sourceLine($unit, $commentLine);

        if (!self::looksLikeStandaloneOneLineComment($lineText)) {
            return false;
        }

        foreach ($unit->tokens as $token) {
            if ($token->id !== T_COMMENT || $token->line !== $commentLine) {
                continue;
            }

            return !str_contains($token->text, "\n") && !str_contains($token->text, "\r");
        }

        return false;
    }

    /**
     * Read one source line by 1-based line number.
     *
     * @return string Source line text, or an empty string when unavailable.
     */
    private static function sourceLine(AnalysisUnit $unit, int $line): string
    {
        $lines = preg_split('/\R/', $unit->source);

        if ($lines === false) {
            return '';
        }

        return $lines[$line - 1] ?? '';
    }

    /**
     * Detect standalone `//`, `#`, or single-line block comments.
     *
     * @return bool True when the line looks like a standalone comment.
     */
    private static function looksLikeStandaloneOneLineComment(string $line): bool
    {
        $trimmed = trim($line);

        if (preg_match('/^\/\/\s*\S/', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^#\s*\S/', $trimmed) === 1) {
            return true;
        }

        return preg_match('/^\/\*(?!\*)\s*\S.*\*\/$/', $trimmed) === 1;
    }
}
