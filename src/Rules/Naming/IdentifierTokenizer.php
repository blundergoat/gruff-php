<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

/**
 * Splits identifiers into normalised word tokens for naming rules.
 */
final readonly class IdentifierTokenizer
{
    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $identifier - Identifier text to split into words.
     *
     * @return list<string> - lowercased word tokens in source order; empty when the identifier holds only separators
     */
    public function tokenize(string $identifier): array
    {
        $trimmed = trim($identifier, "_ \t\n\r\0\x0B");

        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($trimmed === '') {
            // An identifier that is only separators carries no words, so return no tokens.
            return [];
        }

        $tokens = [];

        // User view: add each item that can appear in findings list.
        foreach (preg_split('/_+/', $trimmed) ?: [] as $part) {
            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if ($part === '') {
                continue;
            }

            $matchCount = preg_match_all('/[A-Z]+(?=[A-Z][a-z]|\d|$)|[A-Z]?[a-z]+|\d+/', $part, $matches);
            // User view: choose the findings list branch for this case.
            if ($matchCount === false || $matchCount === 0) {
                $tokens[] = strtolower($part);
                continue;
            }

            // User view: add each item that can appear in findings list.
            foreach ($matches[0] as $match) {
                $tokens[] = strtolower($match);
            }
        }

        return $tokens;
    }
}
