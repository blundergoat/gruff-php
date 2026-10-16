<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Security\SecurityNodeHelper;
use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Recognizes executed Laravel fluent JSON checks on a source-proven local receiver.
 *
 * A same-named foreign builder, rebound variable or unused callback supplies no assertion evidence.
 */
final class FluentJsonAssertionEvidence
{
    /** Exact vendor class whose where methods verify the selected JSON value. */
    private const ASSERTABLE_JSON = 'illuminate\testing\fluent\assertablejson';

    /**
     * Finds a fluent value check that the test actually executes.
     *
     * @param TestQualityScope $scope - Test method or Pest callback whose checks are evaluated.
     * @param AnalysisUnit     $unit  - Parsed declarations used to reject a local vendor-class replacement.
     * @return bool - True when a supported check uses a still-bound vendor JSON builder.
     */
    public static function hasExpectation(TestQualityScope $scope, AnalysisUnit $unit): bool
    {
        foreach (NodeIndex::nodesOf($unit, Stmt\Class_::class) as $class) {
            if (strtolower($class->namespacedName?->toString() ?? '') === self::ASSERTABLE_JSON) {
                return false;
            }
        }

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (!$call instanceof Expr\MethodCall || $call->isFirstClassCallable() || count($call->getArgs()) < 2
                || !in_array(TestQualityNodeHelper::callName($call), ['where', 'wherecontains'], true)
                || SecurityNodeHelper::enclosingFunctionLike($call) !== $scope->node) {
                continue;
            }
            if (self::isJsonReceiver($call->var, $scope, $call->getStartFilePos())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the receiver's latest assignment so rebinding cannot preserve obsolete vendor evidence.
     *
     * @param Expr             $receiver - Receiver of the actual where check; chained checks retain their original root.
     * @param TestQualityScope $scope    - Owning body containing possible local assignments.
     * @param int              $before   - Source offset of the check; later assignments cannot establish this receiver.
     * @return bool - True when the receiver originates from the exact vendor fromArray factory.
     */
    private static function isJsonReceiver(Expr $receiver, TestQualityScope $scope, int $before): bool
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }
        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            $latest = null;
            foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]) as $assignment) {
                if ($assignment->getStartFilePos() < $before && $assignment->var instanceof Expr\Variable
                    && $assignment->var->name === $receiver->name
                    && SecurityNodeHelper::enclosingFunctionLike($assignment) === $scope->node) {
                    $latest = $assignment->expr;
                }
            }
            $receiver = $latest ?? $receiver;
        }

        return $receiver instanceof Expr\StaticCall
            && TestQualityNodeHelper::callName($receiver) === 'fromarray'
            && SecurityNodeHelper::className($receiver->class) === self::ASSERTABLE_JSON;
    }
}
