<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Represents one child-statement block yielded by StmtChildVisitor::childBlocks().
 * Rules use its kind and statements to recurse through control flow without repeating node-type dispatch.
 * The owner exposes related data such as conditions, catch types, and case labels.
 */
final readonly class StmtChildBlock
{
    /** Body inside an `if (...) { ... }` head. */
    public const KIND_IF_BODY = 'if-body';
    /** Body inside an `elseif (...) { ... }` arm. */
    public const KIND_ELSEIF_BODY = 'elseif-body';
    /** Body inside an `else { ... }` arm. */
    public const KIND_ELSE_BODY = 'else-body';
    /** Body inside a `for`/`foreach`/`while`/`do` loop. */
    public const KIND_LOOP_BODY = 'loop-body';
    /** Statements in one switch case or default arm. */
    public const KIND_SWITCH_CASE = 'switch-case-body';
    /** Body inside the `try { ... }` block. */
    public const KIND_TRY_BODY = 'try-body';
    /** Body inside one `catch (...) { ... }` arm. */
    public const KIND_CATCH_BODY = 'catch-body';
    /** Body inside the `finally { ... }` block. */
    public const KIND_FINALLY_BODY = 'finally-body';

    /**
     * Wraps one child-statement block with its role and a back-reference to the owning node.
     *
     * @param string      $kind - One of the KIND_* constants identifying the block role.
     * @param array<Stmt> $statements - Statements inside the block, as PhpParser yields them.
     * @param Node        $owner - Owning node (Stmt, Else_, ElseIf_, Case_, Catch_, or Finally_) that exposes branch-specific data to rules.
     */
    public function __construct(
        public string $kind,
        public array  $statements,
        public Node   $owner,
    ) {
    }
}
