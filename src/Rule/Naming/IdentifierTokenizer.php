<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

/**
 * Splits identifiers into normalised word tokens for naming rules.
 */
final readonly class IdentifierTokenizer
{
    /**
     * @param string $identifier Identifier text to split into words.
     *
     * @return list<string> - lowercased word tokens in source order; empty when the identifier holds only separators
     */
    public function tokenize(string $identifier): array
    {
        $trimmed = trim($identifier, "_ \t\n\r\0\x0B");

        if ($trimmed === '') {
            // An identifier that is only separators carries no words, so return no tokens.
            return [];
        }

        $tokens = [];

        foreach (preg_split('/_+/', $trimmed) ?: [] as $part) {
            if ($part === '') {
                continue;
            }

            $matchCount = preg_match_all('/[A-Z]+(?=[A-Z][a-z]|\d|$)|[A-Z]?[a-z]+|\d+/', $part, $matches);
            if ($matchCount === false || $matchCount === 0) {
                $tokens[] = strtolower($part);
                continue;
            }

            foreach ($matches[0] as $match) {
                $tokens[] = strtolower($match);
            }
        }

        // Hand back the lowercased words in source order, ready for downstream naming comparisons.
        return $tokens;
    }
}
