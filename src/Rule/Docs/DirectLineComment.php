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
     * Report whether a standalone one-line comment sits directly above a line.
     *
     * @param AnalysisUnit $unit Parsed unit whose source should be inspected.
     * @param int          $line Source line that needs a preceding comment.
     * @return bool True when the previous line is a direct one-line comment token.
     */
    public static function hasCommentAbove(AnalysisUnit $unit, int $line): bool
    {
        if ($line <= 1) {
            // Line 1 has nothing above it, so no preceding comment can exist.
            return false;
        }

        $commentLine = $line - 1;
        $lineText    = self::sourceLine($unit, $commentLine);

        if (!self::isStandaloneOneLineComment($lineText)) {
            // The line directly above is code or blank, so the comment-above contract is unmet.
            return false;
        }

        foreach ($unit->tokens as $token) {
            if ($token->id !== T_COMMENT || $token->line !== $commentLine) {
                continue;
            }

            // The source text looked like a comment; confirm the token is genuinely one physical line.
            return !str_contains($token->text, "\n") && !str_contains($token->text, "\r");
        }

        // No comment token started on that line, so the textual match was a false positive.
        return false;
    }

    /**
     * Read one source line by 1-based line number.
     *
     * @param AnalysisUnit $unit Parsed unit whose source provides the line.
     * @param int          $line 1-based source line number to read.
     * @return string Source line text, or an empty string when unavailable.
     */
    private static function sourceLine(AnalysisUnit $unit, int $line): string
    {
        $lines = preg_split('/\R/', $unit->source);

        if ($lines === false) {
            // Split failed, so treat the source as having no readable line here.
            return '';
        }

        // Hand back the requested line, or empty when the number is past the end of source.
        return $lines[$line - 1] ?? '';
    }

    /**
     * Detect standalone `//`, `#`, or single-line block comments.
     *
     * @param string $line Trimmed-or-raw source line to classify.
     * @return bool True when the line looks like a standalone comment.
     */
    private static function isStandaloneOneLineComment(string $line): bool
    {
        $trimmed = trim($line);

        // Matches a `//` line that has at least one non-space character of text after the slashes.
        if (preg_match('/^\/\/\s*\S/', $trimmed) === 1) {
            // A `//` line carrying real text counts as a standalone comment.
            return true;
        }

        // Matches a `#` line that has at least one non-space character of text after the hash.
        if (preg_match('/^#\s*\S/', $trimmed) === 1) {
            // A `#` line carrying real text counts as a standalone comment.
            return true;
        }

        // Matches a single-line `/* ... */` block but excludes `/**` docblocks via the negative lookahead.
        return preg_match('/^\/\*(?!\*)\s*\S.*\*\/$/', $trimmed) === 1;
    }
}
