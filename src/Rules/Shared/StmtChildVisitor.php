<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Shared dispatch over a PHP statement's child control-flow blocks.
 *
 * Replaces the duplicated `instanceof Stmt\If_/For_/Foreach_/While_/Do_/Switch_/TryCatch`
 * chains that previously appeared across complexity and waste rules. When PHP
 * adds a new statement type, only this file changes - the consuming rules
 * inherit the new coverage automatically.
 *
 * Rules contribute per-block payload by switching on `StmtChildBlock::$kind`
 * and combining results in their own way (max for nesting depth, sum for
 * cognitive score, recurse-only for waste).
 */
final readonly class StmtChildVisitor
{
    /**
     * Reports whether a node is a control-flow statement whose child blocks childBlocks() walks.
     *
     * @param Node $node - Node to inspect.
     *
     * @return bool - true when the node is an If/For/Foreach/While/Do/Switch/TryCatch that childBlocks() walks; false otherwise.
     */
    public static function isControlFlowStmt(Node $node): bool
    {
        // Must list exactly the statement kinds childBlocks() yields for; keep the two in lockstep.
        return $node instanceof Stmt\If_
               || $node instanceof Stmt\For_
               || $node instanceof Stmt\Foreach_
               || $node instanceof Stmt\While_
               || $node instanceof Stmt\Do_
               || $node instanceof Stmt\Switch_
               || $node instanceof Stmt\TryCatch;
    }

    /**
     * Yields each child statement block of a control-flow node, or nothing for any other node.
     *
     * Yields nothing for non-control-flow nodes.
     *
     * @param Node $node - Node to inspect.
     *
     * @return iterable<StmtChildBlock> - one block per child statement list in source order; empty for non-control-flow nodes.
     */
    public static function childBlocks(Node $node): iterable // @phpstan-return iterable<StmtChildBlock>
    {
        // An if spreads into its own body, each elseif arm, and any else arm.
        if ($node instanceof Stmt\If_) {
            yield new StmtChildBlock(StmtChildBlock::KIND_IF_BODY, $node->stmts, $node);

            // Each elseif arm is its own block.
            foreach ($node->elseifs as $elseif) {
                yield new StmtChildBlock(StmtChildBlock::KIND_ELSEIF_BODY, $elseif->stmts, $elseif);
            }

            // An else arm, when present, is the last block.
            if ($node->else !== null) {
                yield new StmtChildBlock(StmtChildBlock::KIND_ELSE_BODY, $node->else->stmts, $node->else);
            }

            // If_ blocks are fully yielded; node kinds are mutually exclusive, so stop the generator here.
            return;
        }

        // A for/foreach/while/do exposes its single loop body.
        if ($node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
        ) {
            yield new StmtChildBlock(StmtChildBlock::KIND_LOOP_BODY, $node->stmts, $node);

            // The single loop body is yielded; node kinds are mutually exclusive, so stop the generator here.
            return;
        }

        // A switch exposes one block per case.
        if ($node instanceof Stmt\Switch_) {
            // Walk every case arm.
            foreach ($node->cases as $case) {
                yield new StmtChildBlock(StmtChildBlock::KIND_SWITCH_CASE, $case->stmts, $case);
            }

            // Every switch case is yielded; node kinds are mutually exclusive, so stop the generator here.
            return;
        }

        // A try exposes its try body, each catch arm, and any finally.
        if ($node instanceof Stmt\TryCatch) {
            yield new StmtChildBlock(StmtChildBlock::KIND_TRY_BODY, $node->stmts, $node);

            // Each catch arm is its own block.
            foreach ($node->catches as $catch) {
                yield new StmtChildBlock(StmtChildBlock::KIND_CATCH_BODY, $catch->stmts, $catch);
            }

            // A finally block, when present, is the last.
            if ($node->finally !== null) {
                yield new StmtChildBlock(StmtChildBlock::KIND_FINALLY_BODY, $node->finally->stmts, $node->finally);
            }
        }
    }
}
