<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

use PhpParser\Comment;
use PhpParser\Comment\Doc;
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
     * Split a class body into unbroken runs of one declaration kind: consecutive declarations of that kind with
     * no blank line between them. Comments between declarations keep a run; any other statement ends it.
     *
     * @template T of Node\Stmt
     *
     * @param array<Node\Stmt> $statements - Class-body statements in source order.
     * @param class-string<T>  $kind - Declaration class whose runs are wanted.
     * @param string           $source - Complete file source used to find blank lines between declarations.
     *
     * @return list<non-empty-list<T>> - Runs in source order; a declaration standing alone is a run of one.
     */
    public static function unbrokenRuns(array $statements, string $kind, string $source): array
    {
        $runs       = [];
        $currentRun = [];

        foreach ($statements as $statement) {
            // Another kind of statement ends the run in progress.
            if (!$statement instanceof $kind) {
                if ($currentRun !== []) {
                    $runs[] = $currentRun;
                }
                $currentRun = [];
                continue;
            }

            // A blank line above this declaration starts a new run.
            if ($currentRun !== [] && !self::hasNoBlankLineBetween($currentRun[count($currentRun) - 1], $statement, $source)) {
                $runs[]     = $currentRun;
                $currentRun = [];
            }
            $currentRun[] = $statement;
        }

        if ($currentRun !== []) {
            $runs[] = $currentRun;
        }

        return $runs;
    }

    /**
     * Report whether a declaration has a comment of its own ending on the line directly above it.
     *
     * @param Node   $owner - Declaration whose parser-attached comments are checked.
     * @param string $source - Complete file source used to reject detached and trailing comments.
     * @param bool   $isDocblockRequired - When true, only a `/** ... *\/` docblock counts as the declaration's own.
     *
     * @return bool - True when an own-line comment of the requested kind sits immediately above the declaration.
     */
    public static function hasOwnComment(Node $owner, string $source, bool $isDocblockRequired): bool
    {
        foreach ($owner->getComments() as $comment) {
            // A trailing or detached comment belongs to whatever it follows, not to this declaration.
            if ((!$isDocblockRequired || $comment instanceof Doc) && self::isOwnLineImmediatelyAbove($comment, $owner, $source)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Report whether no blank line separates two declarations; comments between them are allowed.
     *
     * @param Node   $previous - Earlier declaration.
     * @param Node   $next - Declaration directly after it in the same body.
     * @param string $source - Complete file source.
     *
     * @return bool - True when the gap between the two holds no blank physical line (empty or only spaces and tabs).
     */
    private static function hasNoBlankLineBetween(Node $previous, Node $next, string $source): bool
    {
        $gapStart = $previous->getEndFilePos() + 1;
        $gapEnd   = $next->getStartFilePos();

        // Missing parser offsets cannot prove the gap, so the two declarations are kept apart.
        if ($gapStart <= 0 || $gapEnd < $gapStart) {
            return false;
        }

        // A blank line is a line break, then only spaces or tabs, then another line break.
        return preg_match('/\n[ \t]*\r?\n/', substr($source, $gapStart, $gapEnd - $gapStart)) !== 1;
    }

    /**
     * Report whether a physical span contains one or more comments and no executable code.
     *
     * @param string $line - One physical source line, or a complete multi-line block-comment span.
     *
     * @return bool - True when whitespace-separated line or closed block comments consume the complete span.
     */
    public static function isCommentOnlyLine(string $line): bool
    {
        $remaining = ltrim($line);
        if ($remaining === '') {
            return false;
        }

        while ($remaining !== '') {
            // Everything after a line-comment delimiter is comment text by PHP syntax.
            if (str_starts_with($remaining, '//') || str_starts_with($remaining, '#')) {
                return true;
            }

            if (!str_starts_with($remaining, '/*')) {
                return false;
            }

            // Consume the first block comment so code cannot hide before a later closing delimiter.
            $closingDelimiter = strpos($remaining, '*/', 2);
            if ($closingDelimiter === false) {
                return false;
            }

            $remaining = ltrim(substr($remaining, $closingDelimiter + 2));
        }

        return true;
    }
}
