<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Complexity;

use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Classifies complexity shapes that raw branch counts mis-rank.
 */
final readonly class ComplexityShapeClassifier
{
    // Classification tag for a method whose branches are all top-level guard clauses rather than nested decisions.
    public const SHAPE_FLAT_GUARD_CLAUSES = 'flat-guard-clauses';

    /**
     * Detect flat validation flow made of top-level guard clauses that each exit early.
     *
     * @param Stmt\ClassMethod|Stmt\Function_ $node - Function-like node to classify.
     *
     * @return bool - True when branch count comes from flat early-exit guards, not nested or fall-through branches.
     */
    public static function isFlatGuardClauseFlow(Stmt\ClassMethod|Stmt\Function_ $node): bool
    {
        $stmts = $node->stmts ?? [];
        if ($stmts === []) {
            return false;
        }

        $topLevelIfs = 0;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\If_) {
                $topLevelIfs++;
                if (!self::isSimpleTopLevelIf($stmt)) {
                    return false;
                }

                continue;
            }

            if (self::isDisallowedTopLevelStatement($stmt)) {
                return false;
            }
        }

        if ($topLevelIfs < 5) {
            return false;
        }

        foreach (NodeIndex::bodyDescendants($node) as $child) {
            if ($child instanceof Stmt\If_ && $child->getAttribute('parent') !== $node) {
                return false;
            }

            if (self::isDisallowedNestedControl($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check one top-level `if` for the flat guard shape: no else/elseif, no nested control, and an early exit.
     *
     * @param Stmt\If_ $ifStatement - Candidate top-level if.
     *
     * @return bool - True when it has no else/elseif, no nested control, and a branch that exits early.
     */
    private static function isSimpleTopLevelIf(Stmt\If_ $ifStatement): bool
    {
        if ($ifStatement->elseifs !== [] || $ifStatement->else !== null) {
            return false;
        }

        foreach ($ifStatement->stmts as $stmt) {
            if ($stmt instanceof Stmt\If_ || self::isDisallowedTopLevelStatement($stmt)) {
                return false;
            }
        }

        return self::isEarlyExitBranch($ifStatement->stmts);
    }

    /**
     * Decide whether a guard branch ends by leaving the method early.
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

        if ($last instanceof Stmt\Return_) {
            return true;
        }

        if ($last instanceof Stmt\Expression) {
            return $last->expr instanceof Expr\Exit_ || $last->expr instanceof Expr\Throw_;
        }

        return false;
    }

    /**
     * Reject statement-level constructs that mark decision trees or mixed responsibilities.
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
     * Reject top-level statements that are too control-heavy for flat validation.
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
