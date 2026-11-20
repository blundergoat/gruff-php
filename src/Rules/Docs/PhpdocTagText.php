<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

/**
 * Provides shared PHPDoc tag-text checks for documentation rules, including bare parameters and return descriptions.
 * Keeps tag parsing and the definition of a bare-tags-only docblock consistent across these rules.
 *
 * A method or function's wholly bare block is handled by docs.bare-phpdoc-tags alone.
 * Other description rules skip those blocks to avoid duplicate findings.
 * Bound closures' bare blocks stay with the description rules because docs.bare-phpdoc-tags excludes closures.
 */
final readonly class PhpdocTagText
{
    /**
     * Reports whether text follows the first top-level whitespace while ignoring whitespace inside balanced PHPDoc
     * type brackets such as array<string, int>.
     *
     * @param string $body - text following `@return `, e.g. `array<string, int> remaining counts`, to scan
     *
     * @return bool - true when text follows the first top-level whitespace; false when no such text is found
     */
    public static function hasReturnTagDescription(string $body): bool
    {
        $depth  = 0;
        $length = strlen($body);

        // Bracket depth prevents whitespace inside balanced type brackets from ending the type.
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $body[$offset];

            if (str_contains('<{[(', $character)) {
                $depth++;
                continue;
            }

            if (str_contains('>}])', $character) && $depth > 0) {
                $depth--;
                continue;
            }

            if ($depth === 0 && ctype_space($character)) {
                return trim(substr($body, $offset + 1)) !== '';
            }
        }

        return false;
    }

    /**
     * Reads the text following the first `@return` tag in a docblock, including continuation
     * lines for multiline array shapes.
     *
     * @param string $docText - raw docblock text including its `/**`, ` * `, and `*\/` framing
     *
     * @return string|null - Type and description after the first @return; empty for a bodyless tag, or null when absent.
     */
    public static function returnTagBody(string $docText): ?string
    {
        $lines = self::contentLines($docText);


        foreach ($lines as $index => $line) {
            if ($line === '@return') {

                return '';
            }


            if (str_starts_with($line, '@return ')) {
                $bodyLines = [trim(substr($line, strlen('@return ')))];


                for ($offset = $index + 1, $count = count($lines); $offset < $count; $offset++) {

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
     * @return bool - True for a non-empty block of bare @param/@return tags; false for prose, other tags, or descriptions.
     */
    public static function isBareTagsOnly(string $docText): bool
    {
        $lines = self::contentLines($docText);


        if ($lines === []) {
            return false;
        }


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
     * @return list<string> - Bare tag names without $, in docblock order; duplicates retained; empty when no qualifying tag exists.
     */
    public static function bareParamTagNames(string $docText): array
    {
        $lines = self::contentLines($docText);
        $names = [];

        foreach ($lines as $index => $line) {
            // Only `@param` itself counts; `@param-out` and similar tags document something else.
            if (preg_match('/^@param(?:\s|$)/', $line) !== 1) {
                continue;
            }

            $bodyLines = [trim(substr($line, strlen('@param')))];


            for ($offset = $index + 1, $count = count($lines); $offset < $count; $offset++) {

                if (str_starts_with($lines[$offset], '@')) {
                    break;
                }

                $bodyLines[] = $lines[$offset];
            }

            $name = self::bareParamName(trim(implode("\n", $bodyLines)));


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
     * @return string|null - Parameter name without $ if no description follows; null for described or missing variables.
     */
    private static function bareParamName(string $body): ?string
    {
        $depth  = 0;
        $length = strlen($body);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $body[$offset];


            if (str_contains('<{[(', $character)) {
                $depth++;
                continue;
            }


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


        return null;
    }

    /**
     * Reports whether one PHPDoc line matches this rule's supported bare @param or @return shape.
     *
     * @param string $line - single trimmed docblock line, already stripped of comment markers, to classify
     *
     * @return bool - True for a supported bare tag shape; false for other lines or text classified as a description.
     */
    private static function isBareParamOrReturnTag(string $line): bool
    {
        // A terminal parameter variable is the rule's supported bare @param shape.
        if (preg_match('/^@param\s+\S+(?:\s+\S+)*\s+\$\w+\s*$/', $line) === 1) {
            return true;
        }

        if (!str_starts_with($line, '@return ')) {

            return false;
        }


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
        // Normalize docblock lines for tag classification; literal stars inside the text are omitted from the returned content.
        $stripped = preg_replace('/\/\*\*|\*\/|\*/', '', $docText) ?? $docText;
        $stripped = trim($stripped);

        return array_values(array_filter(
            array_map('trim', explode("\n", $stripped)),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
