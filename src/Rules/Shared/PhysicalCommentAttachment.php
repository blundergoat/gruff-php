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
     * Report whether a comment begins on its own line and ends directly above an owner.
     *
     * @param Comment $comment - Parser-attached comment whose physical placement is being proven.
     * @param Node    $owner - Statement or declaration the comment is expected to explain.
     * @param string  $source - Complete file source used to inspect text before the comment token.
     *
     * @return bool - True only when indentation, never earlier code, precedes the adjacent comment.
     */
    public static function isOwnLineImmediatelyAbove(Comment $comment, Node $owner, string $source): bool
    {
        // Detached comments cannot explain an owner after one or more intervening physical lines.
        if ($comment->getEndLine() !== $owner->getStartLine() - 1) {
            return false;
        }

        $commentStart = $comment->getStartFilePos();

        // Missing parser offsets cannot prove where the comment token begins on its source line.
        if ($commentStart < 0) {
            return false;
        }

        $lineBreak = strrpos($source, "\n", $commentStart - strlen($source));
        $lineStart = $lineBreak === false ? 0 : $lineBreak + 1;
        $prefix    = substr($source, $lineStart, $commentStart - $lineStart);

        return trim($prefix) === '';
    }
}
