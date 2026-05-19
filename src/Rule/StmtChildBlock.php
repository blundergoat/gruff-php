<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * One child-statement block yielded by `StmtChildVisitor::childBlocks()`.
 *
 * Rules iterate child blocks to recurse into nested control-flow without
 * repeating the per-statement-type field access. The `owner` reference lets
 * rules that need extras (an if condition, a catch type list, a switch case
 * label) reach the parent node without re-dispatching on `instanceof`.
 */
final readonly class StmtChildBlock
{
    /** Body inside an `if (...) { ... }` head. */
    public const KIND_IF_BODY      = 'if-body';
    /** Body inside an `elseif (...) { ... }` arm. */
    public const KIND_ELSEIF_BODY  = 'elseif-body';
    /** Body inside an `else { ... }` arm. */
    public const KIND_ELSE_BODY    = 'else-body';
    /** Body inside a `for`/`foreach`/`while`/`do` loop. */
    public const KIND_LOOP_BODY    = 'loop-body';
    /** Body inside one `case` of a `switch`. */
    public const KIND_SWITCH_CASE  = 'switch-case-body';
    /** Body inside the `try { ... }` block. */
    public const KIND_TRY_BODY     = 'try-body';
    /** Body inside one `catch (...) { ... }` arm. */
    public const KIND_CATCH_BODY   = 'catch-body';
    /** Body inside the `finally { ... }` block. */
    public const KIND_FINALLY_BODY = 'finally-body';

    /**
     * @param string       $kind       One of the `KIND_*` constants identifying the block role.
     * @param array<Stmt>  $statements Statements inside the block, as PhpParser yields them.
     * @param Node         $owner      Owning node (Stmt, Else_, ElseIf_, Case_, Catch_, or Finally_) — gives rules access to extras.
     */
    public function __construct(
        public string $kind,
        public array  $statements,
        public Node   $owner,
    ) {
    }
}
