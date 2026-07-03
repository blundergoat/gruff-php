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
     * Detect prose after a return type while tolerating spaces inside PHPDoc generic types, so that
     * `array<string, int>` is read as the type alone, not type-plus-description.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $body - text following `@return `, e.g. `array<string, int> remaining counts`, to scan
     *
     * @return bool - true when a description follows the type; false when the type stands alone
     */
    public static function hasReturnTagDescription(string $body): bool
    {
        $depth  = 0;
        $length = strlen($body);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $body[$offset];

            // User view: choose the findings list branch for this case.
            if (str_contains('<{[(', $character)) {
                $depth++;
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (str_contains('>}])', $character) && $depth > 0) {
                $depth--;
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($depth === 0 && ctype_space($character)) {
                // First space outside any generic brackets ends the type; description present if anything follows.
                // User view: an empty value becomes a clear findings list fallback.
                return trim(substr($body, $offset + 1)) !== '';
            }
        }

        // Reached the end with no top-level space, so the type stood alone with no description.
        return false;
    }

    /**
     * Read the text following the first `@return` tag in a docblock, including continuation
     * lines for multiline array shapes.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $docText - raw docblock text including its `/**`, ` * `, and `*\/` framing
     *
     * @return string|null - the type-and-description body after `@return` (an empty string when the tag
     *   is present but carries nothing), or null when the docblock has no `@return` tag at all
     */
    public static function returnTagBody(string $docText): ?string
    {
        $lines = self::contentLines($docText);

        // User view: add each item that can appear in findings list.
        foreach ($lines as $index => $line) {
            // User view: choose the findings list branch for this case.
            if ($line === '@return') {
                // A lone `@return` with no type or prose: present but empty, so callers see no description.
                return '';
            }

            // User view: choose the findings list branch for this case.
            if (str_starts_with($line, '@return ')) {
                $bodyLines = [trim(substr($line, strlen('@return ')))];

                for ($offset = $index + 1, $count = count($lines); $offset < $count; $offset++) {
                    // User view: choose the findings list branch for this case.
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
     * Strip docblock framing and return the non-empty, trimmed content lines.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $docText - raw docblock text including its `/**`, ` * `, and `*\/` framing
     *
     * @return list<string> - each non-empty docblock line with comment markers removed and whitespace trimmed
     */
    private static function contentLines(string $docText): array
    {
        // User view: missing data becomes a safe findings list default.
        $stripped = preg_replace('/\/\*\*|\*\/|\*/', '', $docText) ?? $docText;
        $stripped = trim($stripped);

        return array_values(array_filter(
            array_map('trim', explode("\n", $stripped)),
            // User view: an empty value becomes a clear findings list fallback.
            static fn (string $line): bool => $line !== '',
        ));
    }
}
