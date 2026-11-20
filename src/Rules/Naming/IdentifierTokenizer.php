<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

/**
 * Splits an identifier into normalised, lowercased word tokens so the naming rules can reason about the
 * individual words a name is built from.
 *
 * Handles underscore separators and ASCII camelCase/PascalCase humps, including acronym runs such as `XMLHttp`.
 * Segments with no regex match are retained whole; matched segments contribute only their word and digit matches.
 * Naming checks use the tokens to inspect a name word by word.
 */
final readonly class IdentifierTokenizer
{
    /**
     * Splits identifier segments into lowercased word tokens, falling back to one lowercase token when a segment has no recognized words.
     *
     * @param string $identifier - Identifier text split into underscore-separated segments before word matching.
     *
     * @return list<string> - tokens in source order; empty for an empty string or only underscores and PHP trim characters
     */
    public function tokenize(string $identifier): array
    {
        $trimmed = trim($identifier, "_ \t\n\r\0\x0B");

        if ($trimmed === '') {

            return [];
        }

        $tokens = [];

        // Underscores form word boundaries before the camel-case matcher handles each segment.
        foreach (preg_split('/_+/', $trimmed) ?: [] as $part) {

            if ($part === '') {
                continue;
            }

            // Break the segment into case-transition words, acronym runs, and number runs.
            $matchCount = preg_match_all('/[A-Z]+(?=[A-Z][a-z]|\d|$)|[A-Z]?[a-z]+|\d+/', $part, $matches);
            // Nothing matched, so keep the whole segment as a single lowercase token.
            if ($matchCount === false || $matchCount === 0) {
                $tokens[] = strtolower($part);
                continue;
            }


            foreach ($matches[0] as $match) {
                $tokens[] = strtolower($match);
            }
        }

        return $tokens;
    }
}
