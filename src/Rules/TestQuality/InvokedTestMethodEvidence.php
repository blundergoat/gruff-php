<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Security\SecurityNodeHelper;
use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Finds assertions a developer's test invokes through methods declared in the same class.
 *
 * Only actual calls contribute evidence; stored callbacks and nested, uninvoked functions do not.
 * A resolved Doctrine trait also supplies its exact after-test expectation when no local method or trait can replace it.
 */
final class InvokedTestMethodEvidence
{
    /** Exact trait whose published API verifies the registered deprecation after the test. */
    private const DEPRECATION_TRAIT = 'doctrine\deprecations\phpunit\verifydeprecations';

    /** Maximum number of method bodies followed before an unresolved test keeps its warning. */
    private const MAX_METHOD_DEPTH = 8;

    /**
     * Checks additional expectation evidence for one PHPUnit test after direct checks have failed.
     *
     * @param TestQualityScope $scope - Discovered test; Pest or a missing enclosing class grants no same-class evidence.
     * @param AnalysisUnit     $unit  - Parsed source used to reject locally replaced vendor traits.
     * @return bool - True when an invoked helper or supported resolved trait supplies a real expectation.
     */
    public static function hasExpectation(TestQualityScope $scope, AnalysisUnit $unit): bool
    {
        $method = $scope->node;
        $class  = $method->getAttribute('parent');
        // Only a method in this exact class can establish the supported call graph.
        if (!$method instanceof Stmt\ClassMethod || !$class instanceof Stmt\Class_) {
            return false;
        }

        return self::hasInvokedExpectation($method, $class, self::hasVendorTrait($class, $unit), []);
    }

    /**
     * Follows invoked same-class methods while keeping cycles and unused closures out of the proof.
     *
     * @param Stmt\ClassMethod $method         - Current body; absent statements provide no expectation.
     * @param Stmt\Class_      $class          - Exact owner whose methods may be followed.
     * @param bool             $hasVendorTrait - Whether the unmodified Doctrine trait supplies its published expectation method.
     * @param array<int, true> $visited        - Method identities already followed; empty starts a new test traversal.
     * @return bool - True when this bounded path reaches assertion or supported expectation behavior.
     */
    private static function hasInvokedExpectation(Stmt\ClassMethod $method, Stmt\Class_ $class, bool $hasVendorTrait, array $visited): bool
    {
        $identity = spl_object_id($method);
        // A cycle or exhausted traversal cannot turn an assertion-free test into a proved test.
        if (isset($visited[$identity]) || count($visited) >= self::MAX_METHOD_DEPTH) {
            return false;
        }
        $visited[$identity] = true;
        $calls              = (new NodeFinder())->findInstanceOf($method->stmts ?? [], Expr\CallLike::class);
        // Each actual call may supply an assertion or lead to another declared helper.
        foreach ($calls as $call) {
            // Creating a callback or declaring a nested function does not execute its checks.
            if (!self::isExecutedCall($call) || SecurityNodeHelper::enclosingFunctionLike($call) !== $method) {
                continue;
            }
            // Other call-like expressions, such as construction, are not expectation methods.
            if (!$call instanceof Expr\FuncCall && !$call instanceof Expr\MethodCall && !$call instanceof Expr\StaticCall) {
                continue;
            }
            // The existing assertion policy also applies inside an actually invoked helper.
            if (TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {
                return true;
            }
            $name = self::sameClassMethodName($call, $class);
            // Unknown receivers cannot execute a helper proved from this class's source.
            if ($name === null) {
                continue;
            }
            // Doctrine checks this exact registered expectation in its published after-test hook.
            if ($hasVendorTrait && $name === 'expectdeprecationwithidentifier') {
                return true;
            }
            $callee = $class->getMethod($name);
            // A declared and actually invoked method is the only supported delegation edge.
            if ($callee !== null && self::hasInvokedExpectation($callee, $class, $hasVendorTrait, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinguishes executing a check from preparing a first-class or partial callback.
     *
     * @param Expr\CallLike $call - Parsed call; an empty argument list is an ordinary zero-argument invocation.
     * @return bool - True only when every argument is supplied now, so this expression invokes its target.
     */
    private static function isExecutedCall(Expr\CallLike $call): bool
    {
        // Any placeholder defers execution until a later invocation that this expression does not prove.
        foreach ($call->getRawArgs() as $argument) {
            // Placeholders carry no evaluated argument, including newer parser partial-call nodes.
            if (!$argument instanceof \PhpParser\Node\Arg) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolves only this object or a written reference to the declaring class.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call  - Executed call under review.
     * @param Stmt\Class_                                   $class - Owner used for exact qualified-name comparison.
     * @return string|null - Lowercase method name; null leaves foreign, dynamic or late-static targets unresolved.
     */
    private static function sameClassMethodName(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, Stmt\Class_ $class): ?string
    {
        // A dynamic method name cannot identify which helper executes.
        if (!$call->name instanceof Identifier) {
            return null;
        }
        // The current object has the exact method declarations being inspected.
        if ($call instanceof Expr\MethodCall && $call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return strtolower($call->name->toString());
        }
        // Static self references are bounded; parent and dynamic class expressions are not.
        if (!$call instanceof Expr\StaticCall || !$call->class instanceof Name) {
            return null;
        }
        $resolved  = $call->class->getAttribute('resolvedName', $call->class);
        $ownerName = $class->namespacedName ?? null;
        $isOwner   = $resolved instanceof Name && $ownerName instanceof Name
            && strcasecmp($resolved->toString(), $ownerName->toString()) === 0;

        return strtolower($call->class->toString()) === 'self' || $isOwner ? strtolower($call->name->toString()) : null;
    }

    /**
     * Requires the exact vendor trait without adaptations, competing traits or local replacement methods.
     *
     * @param Stmt\Class_  $class - PHPUnit class whose trait declarations are inspected.
     * @param AnalysisUnit $unit  - Parsed file; a local declaration of the vendor trait invalidates published API evidence.
     * @return bool - True only when the exact Doctrine expectation remains supplied by the supported trait.
     */
    private static function hasVendorTrait(Stmt\Class_ $class, AnalysisUnit $unit): bool
    {
        // Replacing the expectation or either hook invalidates the published after-test assertion evidence.
        foreach (['expectDeprecationWithIdentifier', 'verifyDeprecationsAreTriggered', 'enableDeprecationTracking'] as $methodName) {
            // A local override may deliberately register or verify nothing.
            if ($class->getMethod($methodName) !== null) {
                return false;
            }
        }
        // A locally declared replacement cannot borrow behavior from a published vendor API.
        foreach (NodeIndex::nodesOf($unit, Stmt\Trait_::class) as $trait) {
            $name = $trait->namespacedName ?? null;
            // The fully qualified declaration would replace the trait whose hook supplies the proof.
            if ($name instanceof Name && strtolower($name->toString()) === self::DEPRECATION_TRAIT) {
                return false;
            }
        }
        $found = false;
        // Every trait must be known here; an unrelated trait may override expectation or hook behavior.
        foreach ($class->getTraitUses() as $use) {
            // Aliasing or conflict resolution changes which implementation the test would invoke.
            if ($use->adaptations !== []) {
                return false;
            }
            // A comma-separated trait list can hide a competing implementation too.
            foreach ($use->traits as $traitName) {
                $resolved = $traitName->getAttribute('resolvedName', $traitName);
                // Only the exact supported vendor binding grants this narrowly named expectation.
                if (!$resolved instanceof Name || strtolower($resolved->toString()) !== self::DEPRECATION_TRAIT) {
                    return false;
                }
                $found = true;
            }
        }

        return $found;
    }
}
