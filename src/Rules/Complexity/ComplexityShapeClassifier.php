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
    public const SHAPE_FLAT_GUARD_CLAUSES = 'flat-guard-clauses';

    /**
     * Detect flat validation/hydration flow made of top-level guard clauses.
     *
     * @param Stmt\ClassMethod|Stmt\Function_ $node - Function-like node to classify.
     *
     * @return bool - True when branch count comes from flat guards, not nested decision logic.
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
     * Check one top-level `if` for the flat guard shape.
     *
     * @param Stmt\If_ $if - Candidate top-level if.
     *
     * @return bool - True when it has no else/elseif and no nested control.
     */
    private static function isSimpleTopLevelIf(Stmt\If_ $if): bool
    {
        if ($if->elseifs !== [] || $if->else !== null) {
            return false;
        }

        foreach ($if->stmts as $stmt) {
            if ($stmt instanceof Stmt\If_ || self::isDisallowedTopLevelStatement($stmt)) {
                return false;
            }
        }

        return true;
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
