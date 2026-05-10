<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

final readonly class IdentifierTokenizer
{
    /**
     * @return list<string>
     */
    public function tokenize(string $identifier): array
    {
        $trimmed = trim($identifier, "_ \t\n\r\0\x0B");

        if ($trimmed === '') {
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

        return $tokens;
    }
}
