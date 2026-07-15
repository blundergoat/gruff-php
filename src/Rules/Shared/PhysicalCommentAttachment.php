<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

use PhpParser\Comment;
use PhpParser\Node;

/**
 * Verifies physical comment ownership beyond PHP-Parser's permissive attachment metadata.
 * Documentation rules use it after parsing to reject detached and trailing comments.
 * Users benefit when only prose visibly placed above a declaration or statement covers it.
 */
final class PhysicalCommentAttachment
{
    /**
     * Report whether a comment occupies only comment text and ends directly above an owner.
     *
     * @param Comment $comment - Parser-attached comment whose physical placement is being proven.
     * @param Node    $owner - Statement or declaration the comment is expected to explain.
     * @param string  $source - Complete file source used to inspect text around the comment token.
     *
     * @return bool - True only when indentation precedes the comment and no executable code follows it before the owner.
     */
    public static function isOwnLineImmediatelyAbove(Comment $comment, Node $owner, string $source): bool
    {
        // Detached comments cannot explain an owner after one or more intervening physical lines.
        if ($comment->getEndLine() !== $owner->getStartLine() - 1) {
            return false;
        }

        $commentStart = $comment->getStartFilePos();
        $commentEnd   = $comment->getEndFilePos();
        $ownerStart   = $owner->getStartFilePos();
        $sourceLength = strlen($source);

        // Missing or inconsistent parser offsets cannot prove the comment-to-owner source slice.
        if ($commentStart < 0
            || $commentEnd < $commentStart
            || $ownerStart <= $commentEnd
            || $commentEnd >= $sourceLength
            || $ownerStart > $sourceLength
        ) {
            return false;
        }

        $lineBreak = strrpos($source, "\n", $commentStart - $sourceLength);
        $lineStart = $lineBreak === false ? 0 : $lineBreak + 1;
        $lineEnd   = strpos($source, "\n", $commentEnd + 1);
        $lineEnd   = $lineEnd === false ? $sourceLength : $lineEnd;
        $lineText  = substr($source, $lineStart, $lineEnd - $lineStart);
        $between   = substr($source, $commentEnd + 1, $ownerStart - $commentEnd - 1);

        return self::isCommentOnlyLine($lineText) && trim($between) === '';
    }

    /**
     * Report whether a physical line contains an indented comment and no trailing code.
     *
     * @param string $line - One physical source line, or a complete multi-line block-comment span.
     *
     * @return bool - True for `//`, `#`, or a closed `/* ... *\/` comment with only trailing whitespace.
     */
    public static function isCommentOnlyLine(string $line): bool
    {
        $trimmedLine = ltrim($line);

        // Everything after a line-comment delimiter is comment text by PHP syntax.
        if (str_starts_with($trimmedLine, '//') || str_starts_with($trimmedLine, '#')) {
            return true;
        }

        if (!str_starts_with($trimmedLine, '/*')) {
            return false;
        }

        $closingDelimiter = strrpos($trimmedLine, '*/');

        return $closingDelimiter !== false
            && trim(substr($trimmedLine, $closingDelimiter + 2)) === '';
    }
}
