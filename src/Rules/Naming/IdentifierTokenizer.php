<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

/**
 * Splits an identifier into normalised, lowercased word tokens so the naming rules can reason about the
 * individual words a name is built from.
 *
 * Handles snake_case underscores and camelCase/PascalCase humps, including acronym runs such as `XMLHttp`,
 * and drops separator-only fragments. Used by every identifier-quality check that needs to inspect a name
 * word by word rather than as one opaque string.
 */
final readonly class IdentifierTokenizer
{
    /**
     * Splits an identifier into its lowercased word tokens.
     *
     * @param string $identifier - Identifier text to split into words.
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

        // Split on underscores first, then inspect each snake_case segment on its own.
        foreach (preg_split('/_+/', $trimmed) ?: [] as $part) {
            // Repeated underscores leave empty segments that carry no word.
            if ($part === '') {
                continue;
            }

            // Break the segment into camelCase words and acronym runs.
            $matchCount = preg_match_all('/[A-Z]+(?=[A-Z][a-z]|\d|$)|[A-Z]?[a-z]+|\d+/', $part, $matches);
            // Nothing matched, so keep the whole segment as a single lowercase token.
            if ($matchCount === false || $matchCount === 0) {
                $tokens[] = strtolower($part);
                continue;
            }

            // Record each detected word in lowercase.
            foreach ($matches[0] as $match) {
                $tokens[] = strtolower($match);
            }
        }

        return $tokens;
    }
}
