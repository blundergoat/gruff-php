<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

/**
 * Shared PHPDoc tag-text helpers for the documentation rules. Strips docblock framing into content
 * lines, locates the body of an `@return` tag, and decides whether a return type is followed by a
 * real description.
 *
 * BarePhpdocTagsRule (tags-only docblock detection) and ReturnCommentRule (described-`@return`
 * enforcement) both need the same generics-tolerant "is there prose after the type" check, so the
 * detector lives here once rather than being copy-pasted between the two rules.
 */
final readonly class PhpdocTagText
{
    /**
     * Reports whether prose follows a return type, tolerating spaces inside PHPDoc generic types so that
     * `array<string, int>` is read as the type alone, not type-plus-description.
     *
     * @param string $body - text following `@return `, e.g. `array<string, int> remaining counts`, to scan
     *
     * @return bool - true when a description follows the type; false when the type stands alone
     */
    public static function hasReturnTagDescription(string $body): bool
    {
        $depth  = 0;
        $length = strlen($body);

        // Walk the body character by character, tracking generic-bracket depth.
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $body[$offset];

            // An opening bracket enters a generic type.
            if (str_contains('<{[(', $character)) {
                $depth++;
                continue;
            }

            // A closing bracket leaves the generic type.
            if (str_contains('>}])', $character) && $depth > 0) {
                $depth--;
                continue;
            }

            if ($depth === 0 && ctype_space($character)) {
                // First space outside any generic brackets ends the type; description present if anything follows.
                return trim(substr($body, $offset + 1)) !== '';
            }
        }

        // Reached the end with no top-level space, so the type stood alone with no description.
        return false;
    }

    /**
     * Reads the text following the first `@return` tag in a docblock, including continuation
     * lines for multiline array shapes.
     *
     * @param string $docText - raw docblock text including its `/**`, ` * `, and `*\/` framing
     *
     * @return string|null - the type-and-description body after `@return` (an empty string when the tag
     *   is present but carries nothing), or null when the docblock has no `@return` tag at all
     */
    public static function returnTagBody(string $docText): ?string
    {
        $lines = self::contentLines($docText);

        // Scan each content line for the return tag.
        foreach ($lines as $index => $line) {
            if ($line === '@return') {
                // A lone `@return` with no type or prose: present but empty, so callers see no description.
                return '';
            }

            // A return tag carrying a body: gather the type and any continuation lines.
            if (str_starts_with($line, '@return ')) {
                $bodyLines = [trim(substr($line, strlen('@return ')))];

                // Continuation lines, until the next tag, extend a multiline array shape.
                for ($offset = $index + 1, $count = count($lines); $offset < $count; $offset++) {
                    // The next tag ends the return body.
                    if (str_starts_with($lines[$offset], '@')) {
                        break;
                    }

                    $bodyLines[] = $lines[$offset];
                }

                return trim(implode("\n", $bodyLines));
            }
        }

        // No `@return` line: presence is owned by docs.missing-return-tag, so signal absence with null.
        return null;
    }

    /**
     * Strips docblock framing and returns the non-empty, trimmed content lines.
     *
     * @param string $docText - raw docblock text including its `/**`, ` * `, and `*\/` framing
     *
     * @return list<string> - each non-empty docblock line with comment markers removed and whitespace trimmed
     */
    private static function contentLines(string $docText): array
    {
        $stripped = preg_replace('/\/\*\*|\*\/|\*/', '', $docText) ?? $docText;
        $stripped = trim($stripped);

        return array_values(array_filter(
            array_map('trim', explode("\n", $stripped)),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
