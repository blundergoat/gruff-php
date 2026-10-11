<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

/**
 * Shared PHPDoc tag-text helpers for the documentation rules. Strips docblock framing into content
 * lines, locates the body of an `@return` tag, decides whether a return type is followed by a real
 * description, and decides whether a whole docblock is nothing but bare `@param`/`@return` tags.
 *
 * BarePhpdocTagsRule (tags-only docblock detection) and ReturnCommentRule (described-`@return`
 * enforcement) both need the same generics-tolerant "is there prose after the type" check and the
 * same tags-only definition, so they live here once: the tags-only block is reported by
 * docs.bare-phpdoc-tags alone, and the other docs rules skip it rather than report it twice.
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
     * Reports whether a docblock is wholly bare tags: it has content, every content line is a tag, and
     * every tag is a `@param` or `@return` with a type but no description. This is the block
     * docs.bare-phpdoc-tags reports; other docs rules call this to leave that block to it.
     *
     * @param string $docText - raw docblock text including its `/**`, ` * `, and `*\/` framing
     *
     * @return bool - true when the block holds only bare param/return tags; false when it is empty, has
     *   a summary or other prose line, or has any described or other tag
     */
    public static function isBareTagsOnly(string $docText): bool
    {
        $lines = self::contentLines($docText);

        // An empty docblock carries no tags, so it is not a tags-only block.
        if ($lines === []) {
            return false;
        }

        // Any prose line, other tag, or described tag means the block is not bare.
        foreach ($lines as $line) {
            if (!self::isBareParamOrReturnTag($line)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lists the parameters whose `@param` tag stops at its variable with no description, reading continuation
     * lines so a multiline array shape is scanned through to its variable.
     *
     * @param string $docText - raw docblock text including its `/**`, ` * `, and `*\/` framing
     *
     * @return list<string> - each bare tag's parameter name without `$`, in docblock order; empty when every
     *   `@param` tag is described or names no variable
     */
    public static function bareParamTagNames(string $docText): array
    {
        $lines = self::contentLines($docText);
        $names = [];

        // Scan each content line for a param tag.
        foreach ($lines as $index => $line) {
            // Only `@param` itself counts; `@param-out` and similar tags document something else.
            if (preg_match('/^@param(?:\s|$)/', $line) !== 1) {
                continue;
            }

            $bodyLines = [trim(substr($line, strlen('@param')))];

            // Continuation lines, until the next tag, extend a multiline array shape or description.
            for ($offset = $index + 1, $count = count($lines); $offset < $count; $offset++) {
                // The next tag ends this param body.
                if (str_starts_with($lines[$offset], '@')) {
                    break;
                }

                $bodyLines[] = $lines[$offset];
            }

            $name = self::bareParamName(trim(implode("\n", $bodyLines)));

            // Record only a tag that names its variable and carries nothing after it.
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Reads the variable a `@param` body documents and returns it when no description follows, tolerating
     * spaces and `$` tokens inside generic or callable types such as `callable(int $x): void`.
     *
     * @param string $body - text following `@param`: an optional type, the variable, then any description, to scan
     *
     * @return string|null - the parameter name without `$` when the tag stops at its variable; null when a
     *   description follows or the tag names no variable
     */
    private static function bareParamName(string $body): ?string
    {
        $depth  = 0;
        $length = strlen($body);

        // Walk the body character by character, tracking generic-bracket depth.
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $body[$offset];

            // An opening bracket enters a type shape.
            if (str_contains('<{[(', $character)) {
                $depth++;
                continue;
            }

            // A closing bracket leaves the type shape.
            if (str_contains('>}])', $character) && $depth > 0) {
                $depth--;
                continue;
            }

            // The first variable outside any type shape is the documented parameter.
            if ($depth === 0 && $character === '$' && preg_match('/\$(\w+)/A', $body, $matches, 0, $offset) === 1) {
                // Bare exactly when nothing follows the variable.
                return trim(substr($body, $offset + strlen($matches[0]))) === '' ? $matches[1] : null;
            }
        }

        // No variable at depth zero: there is no parameter this tag could name.
        return null;
    }

    /**
     * Reports whether one PHPDoc line is a `@param` or `@return` tag with a type but no description.
     *
     * @param string $line - single trimmed docblock line, already stripped of comment markers, to classify
     *
     * @return bool - true when the line is a bare parameter or return tag; false for prose, other tags,
     *   and described tags
     */
    private static function isBareParamOrReturnTag(string $line): bool
    {
        // Match @param tags that end at the variable name with no descriptive prose.
        if (preg_match('/^@param\s+\S+(?:\s+\S+)*\s+\$\w+\s*$/', $line) === 1) {
            // A @param stopping at the variable name carries no description, so it is bare.
            return true;
        }

        if (!str_starts_with($line, '@return ')) {
            // Lines that are neither a bare @param nor a @return cannot be a bare return tag.
            return false;
        }

        // A @return is bare exactly when no prose follows its type, so negate the description check.
        return !self::hasReturnTagDescription(trim(substr($line, strlen('@return '))));
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
