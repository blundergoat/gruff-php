<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Complexity;

use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Identifies function and method bodies with at least five top-level early-exit guards.
 * Guards may be interspersed with other statements, but else/elseif and the listed nested control nodes disqualify the body.
 *
 * Complexity rules use this shape when choosing severity; classification alone does not suppress a finding.
 */
final readonly class ComplexityShapeClassifier
{
    // Classification tag for functions or methods meeting the minimum guard count and control-node checks.
    public const SHAPE_FLAT_GUARD_CLAUSES = 'flat-guard-clauses';

    /**
     * Recognise at least five top-level early-exit guards with no else/elseif or disallowed nested control.
     *
     * @param Stmt\ClassMethod|Stmt\Function_ $node - Function or method whose body nodes have parent attributes.
     *
     * @return bool - Whether the body meets the guard-shape checks; false for a missing or empty body.
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
     * Check a guard's else/elseif clauses, immediate statements, and final exit.
     * The caller separately rejects disallowed control deeper in the body.
     *
     * @param Stmt\If_ $ifStatement - Candidate guard checked before the caller's descendant scan.
     *
     * @return bool - True for an early-exit guard without else/elseif or rejected immediate statements.
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
     * Require a return statement or a throw/exit expression as the final branch statement.
     * This prevents fall-through conditionals from qualifying as early-exit guards.
     *
     * @param array<Stmt> $stmts - Branch statements in source order; an empty branch has no terminating statement.
     *
     * @return bool - Whether the final statement returns, throws, or exits; false for an empty branch.
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
     * Reports whether a node is nested control flow that disqualifies the flat-guard shape.
     *
     * @param Node $node - Body descendant checked by the flat-guard classifier.
     *
     * @return bool - True for loops, switch, try/catch, match, or ternary nodes; nested if checks run separately.
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
     * Identify top-level loops, switch statements, and try/catch blocks that disqualify guard flow.
     *
     * @param Stmt $stmt - Immediate function-body or guard-body statement to check.
     *
     * @return bool - True for for, foreach, while, do/while, switch, or try/catch statements.
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
