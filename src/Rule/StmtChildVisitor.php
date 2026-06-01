<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

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
     * Whether a node is a control-flow statement that owns child blocks.
     *
     * @param Node $node Node to inspect.
     * @return bool True for If/For/Foreach/While/Do/Switch/TryCatch.
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
     * Yield each child statement block of a control-flow node.
     *
     * Yields nothing for non-control-flow nodes.
     *
     * @param Node $node Node to inspect.
     * @return iterable<StmtChildBlock>
     */
    public static function childBlocks(Node $node): iterable // @phpstan-return iterable<StmtChildBlock>
    {
        if ($node instanceof Stmt\If_) {
            yield new StmtChildBlock(StmtChildBlock::KIND_IF_BODY, $node->stmts, $node);

            foreach ($node->elseifs as $elseif) {
                yield new StmtChildBlock(StmtChildBlock::KIND_ELSEIF_BODY, $elseif->stmts, $elseif);
            }

            if ($node->else !== null) {
                yield new StmtChildBlock(StmtChildBlock::KIND_ELSE_BODY, $node->else->stmts, $node->else);
            }

            // If_ blocks are fully yielded; node kinds are mutually exclusive, so stop the generator here.
            return;
        }

        if ($node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
        ) {
            yield new StmtChildBlock(StmtChildBlock::KIND_LOOP_BODY, $node->stmts, $node);

            // The single loop body is yielded; node kinds are mutually exclusive, so stop the generator here.
            return;
        }

        if ($node instanceof Stmt\Switch_) {
            foreach ($node->cases as $case) {
                yield new StmtChildBlock(StmtChildBlock::KIND_SWITCH_CASE, $case->stmts, $case);
            }

            // Every switch case is yielded; node kinds are mutually exclusive, so stop the generator here.
            return;
        }

        if ($node instanceof Stmt\TryCatch) {
            yield new StmtChildBlock(StmtChildBlock::KIND_TRY_BODY, $node->stmts, $node);

            foreach ($node->catches as $catch) {
                yield new StmtChildBlock(StmtChildBlock::KIND_CATCH_BODY, $catch->stmts, $catch);
            }

            if ($node->finally !== null) {
                yield new StmtChildBlock(StmtChildBlock::KIND_FINALLY_BODY, $node->finally->stmts, $node->finally);
            }
        }
    }
}
