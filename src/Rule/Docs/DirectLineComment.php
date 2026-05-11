<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Parser\AnalysisUnit;

final readonly class DirectLineComment
{
    public static function existsAbove(AnalysisUnit $unit, int $line): bool
    {
        if ($line <= 1) {
            return false;
        }

        $commentLine = $line - 1;
        $lineText = self::sourceLine($unit, $commentLine);

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

    private static function sourceLine(AnalysisUnit $unit, int $line): string
    {
        $lines = preg_split('/\R/', $unit->source);

        if ($lines === false) {
            return '';
        }

        return $lines[$line - 1] ?? '';
    }

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
