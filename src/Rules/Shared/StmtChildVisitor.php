<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Exposes child statement lists for if, for, foreach, while, do, switch and try/catch statements.
 * Rules share this traversal while combining block results for their own metrics.
 *
 * Each block carries its kind and owning node so callers can inspect conditions or catch types.
 * Unsupported node kinds yield no blocks; empty supported bodies still yield their block.
 */
final readonly class StmtChildVisitor
{
    /**
     * Reports whether a node is a control-flow statement whose child blocks childBlocks() walks.
     *
     * @param Node $node - Candidate node checked against the statement kinds supported by childBlocks().
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
     * Yields child statement blocks in source order for the supported control-flow nodes.
     *
     * @param Node $node - Candidate node whose child statement lists are yielded when its kind is supported.
     *
     * @return iterable<StmtChildBlock> - One block per child statement list, including empty bodies; none for unsupported nodes.
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

            return;
        }


        if ($node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
        ) {
            yield new StmtChildBlock(StmtChildBlock::KIND_LOOP_BODY, $node->stmts, $node);

            return;
        }


        if ($node instanceof Stmt\Switch_) {

            foreach ($node->cases as $case) {
                yield new StmtChildBlock(StmtChildBlock::KIND_SWITCH_CASE, $case->stmts, $case);
            }

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
