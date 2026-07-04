<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Complexity;

use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Recognises the one complexity shape raw branch counts over-penalise: a flat run of top-level guard
 * clauses, so the complexity rules can spare well-structured validation from a false warning.
 *
 * A method that opens with five-plus early-return `if` guards and no nesting reads as simple to a human
 * yet scores high on branch count. isFlatGuardClauseFlow() spots exactly that pattern - flat, no
 * else/elseif, every guard exits early, nothing heavier nested - so callers can downgrade its severity.
 */
final readonly class ComplexityShapeClassifier
{
    // Classification tag for a method whose branches are all top-level guard clauses rather than nested decisions.
    public const SHAPE_FLAT_GUARD_CLAUSES = 'flat-guard-clauses';

    /**
     * Reports whether a function-like body is a flat stack of early-exit guard clauses (5+, no nesting).
     *
     * @param Stmt\ClassMethod|Stmt\Function_ $node - Function-like node to classify.
     *
     * @return bool - True when branch count comes from flat early-exit guards, not nested or fall-through branches.
     */
    public static function isFlatGuardClauseFlow(Stmt\ClassMethod|Stmt\Function_ $node): bool
    {
        $stmts = $node->stmts ?? [];
        // An empty body has no guard shape to recognise.
        if ($stmts === []) {
            return false;
        }

        $topLevelIfs = 0;
        // Every top-level statement must be a simple guard or a plain, control-free statement.
        foreach ($stmts as $stmt) {
            // A top-level if has to be a simple early-exit guard.
            if ($stmt instanceof Stmt\If_) {
                $topLevelIfs++;
                // Any non-guard if breaks the flat shape.
                if (!self::isSimpleTopLevelIf($stmt)) {
                    return false;
                }

                continue;
            }

            // A loop or switch at the top level is not guard flow.
            if (self::isDisallowedTopLevelStatement($stmt)) {
                return false;
            }
        }

        // Fewer than five guards is ordinary branching, not the shape worth exempting.
        if ($topLevelIfs < 5) {
            return false;
        }

        // No control flow may hide deeper in the body.
        foreach (NodeIndex::bodyDescendants($node) as $child) {
            // A nested if (anything below the top level) breaks the flat shape.
            if ($child instanceof Stmt\If_ && $child->getAttribute('parent') !== $node) {
                return false;
            }

            // Any nested loop, switch, match, or ternary breaks it too.
            if (self::isDisallowedNestedControl($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reports whether one top-level if is a clean guard: no else/elseif, no nested control, exits early.
     *
     * @param Stmt\If_ $ifStatement - Candidate top-level if.
     *
     * @return bool - True when it has no else/elseif, no nested control, and a branch that exits early.
     */
    private static function isSimpleTopLevelIf(Stmt\If_ $ifStatement): bool
    {
        // An else or elseif means it is branching, not a one-way guard.
        if ($ifStatement->elseifs !== [] || $ifStatement->else !== null) {
            return false;
        }

        // The guard body must hold no nested if or heavy control.
        foreach ($ifStatement->stmts as $stmt) {
            // A nested if or loop disqualifies the guard.
            if ($stmt instanceof Stmt\If_ || self::isDisallowedTopLevelStatement($stmt)) {
                return false;
            }
        }

        return self::isEarlyExitBranch($ifStatement->stmts);
    }

    /**
     * Reports whether a guard branch ends by leaving the method early (return, throw, or exit/die).
     *
     * Mirrors the terminator set the unreachable-code analysis uses: a `return`, or an `exit`/`die` or
     * `throw` expression. A guard clause short-circuits; a branch that does work and falls through is
     * ordinary branching, so without this check flat fall-through conditionals (for example building an
     * array) would be mis-tagged as guard clauses and wrongly downgraded below the fail-on threshold.
     * `break`/`continue` cannot appear at a method body's top level, so they are intentionally excluded.
     *
     * @param array<Stmt> $stmts - Statements in the `if` branch body, in source order.
     *
     * @return bool - True when the final statement returns, throws, or exits the process.
     */
    private static function isEarlyExitBranch(array $stmts): bool
    {
        $last = $stmts === [] ? null : $stmts[array_key_last($stmts)];

        // A trailing return exits the method early.
        if ($last instanceof Stmt\Return_) {
            return true;
        }

        // A trailing exit/die or throw also exits early.
        if ($last instanceof Stmt\Expression) {
            return $last->expr instanceof Expr\Exit_ || $last->expr instanceof Expr\Throw_;
        }

        return false;
    }

    /**
     * Reports whether a node is nested control flow that disqualifies the flat-guard shape.
     *
     * @param Node $node - Statement or expression node to classify.
     *
     * @return bool - True when the node is not part of the flat-guard exemption.
     */
    private static function isDisallowedNestedControl(Node $node): bool
    {
        return $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
            || $node instanceof Stmt\Switch_
            || $node instanceof Stmt\TryCatch
            || $node instanceof Stmt\Catch_
            || $node instanceof Expr\Match_
            || $node instanceof Expr\Ternary;
    }

    /**
     * Reports whether a top-level statement is too control-heavy to count as flat validation.
     *
     * @param Stmt $stmt - Top-level statement.
     *
     * @return bool - True when the statement should keep the normal complexity severity.
     */
    private static function isDisallowedTopLevelStatement(Stmt $stmt): bool
    {
        return $stmt instanceof Stmt\For_
            || $stmt instanceof Stmt\Foreach_
            || $stmt instanceof Stmt\While_
            || $stmt instanceof Stmt\Do_
            || $stmt instanceof Stmt\Switch_
            || $stmt instanceof Stmt\TryCatch;
    }
}
