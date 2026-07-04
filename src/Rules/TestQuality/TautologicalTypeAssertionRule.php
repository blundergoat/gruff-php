<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

/**
 * Flags an `assertInstanceOf(X::class, $v)` whose value is already statically known to be that type -
 * built by `new X()` right there or assigned one earlier in the test - so the assertion restates a
 * guarantee the code already makes and proves nothing. Runs over every test. Error severity, high confidence.
 */
final readonly class TautologicalTypeAssertionRule implements RuleInterface
{
    /**
     * Stable rule identifier for tautological type assertion findings.
     */
    public const ID = 'test-quality.tautological-type-assertion';

    /**
     * Describes the tautological-type-assertion rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Tautological type assertion',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Error,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports assertInstanceOf calls where the value's type is already proven locally.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for redundant type assertions.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $localTypes = $this->collectLocalAssignmentTypes($scope);

            // Inspect each call the test makes.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                $name = TestQualityNodeHelper::callName($call);
                // Only assertInstanceOf() can be a redundant type assertion.
                if ($name !== 'assertinstanceof') {
                    continue;
                }

                $expected = $this->classNameArg($call, 0);
                $valueArg = TestQualityNodeHelper::argValue($call, 1);
                // Need both an expected class and a value argument to judge.
                if ($expected === null || $valueArg === null) {
                    continue;
                }

                $proven = $this->provenClass($valueArg, $localTypes);
                // Flag only when the value's proven type already equals the asserted one.
                if ($proven === null || strtolower($proven) !== strtolower($expected)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:  self::ID,
                    message: sprintf(
                        '%s asserts $%s is an instance of %s, but it is statically already that type.',
                        $scope->symbol,
                        $this->describeValue($valueArg),
                        $expected,
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $call->getStartLine(),
                    severity:    Severity::Error,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::High,
                    symbol:      $scope->symbol,
                    remediation: 'Drop the redundant assertInstanceOf or assert behaviour on the value instead of its type.',
                    metadata:    ['expected' => $expected],
                );
            }
        }

        return $findings;
    }

    /**
     * Maps local variables to the class names assigned to them via `new`.
     *
     * @param TestQualityScope $scope - Single test method whose direct `$var = new X()` assignments are scanned.
     *
     * @return array<string, string> - Variable name to constructed class name; only locals built via `new` appear.
     */
    private function collectLocalAssignmentTypes(TestQualityScope $scope): array
    {
        $types = [];

        // Weigh every assignment in the test body.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]) as $assign) {
            // Only a plainly named variable target can be tracked.
            if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }

            $class = $this->newClassName($assign->expr);
            // Record the variable's type when it is built with new.
            if ($class !== null) {
                $types[$assign->var->name] = $class;
            }
        }

        // Locally-proven types only; later reassignment is not tracked, so callers treat this as a lower bound.
        return $types;
    }

    /**
     * Resolves the statically proven class of a value, or null when it cannot be inferred.
     *
     * @param Expr                  $expr - Value expression from the assertion's second argument to resolve.
     * @param array<string, string> $localTypes - Map of variable name to class built by collectLocalAssignmentTypes().
     *
     * @return string|null - Proven class name, or null when it cannot be inferred.
     */
    private function provenClass(Expr $expr, array $localTypes): ?string
    {
        $direct = $this->newClassName($expr);
        if ($direct !== null) {
            // Inline `new X()` proves its own type without consulting the local map.
            return $direct;
        }

        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            // Plain variable: trust the earlier assignment scan, null when the variable was never tracked.
            return $localTypes[$expr->name] ?? null;
        }

        // Property fetches, calls, and other dynamic shapes are treated as unproven on purpose.
        return null;
    }

    /**
     * Returns the class name of a direct `new ClassName` expression, or null.
     *
     * @param Expr $expr - Candidate expression; only a `new` with a static class name yields a result.
     *
     * @return string|null - Constructed class name, or null for dynamic/unsupported expressions.
     */
    private function newClassName(Expr $expr): ?string
    {
        if ($expr instanceof Expr\New_ && $expr->class instanceof Name) {
            // The literal class token as written, so the caller can string-compare it to the expected type.
            return $expr->class->toString();
        }

        // Not a direct construction (variable class, anonymous class, factory call) so no static type is known.
        return null;
    }

    /**
     * Returns the class named by a `ClassName::class` argument, or null.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Assertion call whose argument list is read.
     * @param int $index - Zero-based argument position the caller expects to hold a `X::class` constant.
     *
     * @return string|null - Class name string, or null when the argument is not a class constant.
     */
    private function classNameArg(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?string
    {
        $classConstFetch = TestQualityNodeHelper::argValue($call, $index);
        if (!$classConstFetch instanceof Expr\ClassConstFetch || !$classConstFetch->class instanceof Name) {
            // Missing argument or non-class-constant: caller cannot rely on a known expected type.
            return null;
        }

        $name = $classConstFetch->name;
        if (!$name instanceof Node\Identifier || strtolower($name->toString()) !== 'class') {
            // A constant fetch other than `::class` (e.g. `::FOO`) is not a type reference.
            return null;
        }

        // The class portion of `X::class`, normalised to its written name for type comparison.
        return $classConstFetch->class->toString();
    }

    /**
     * Describes the asserted value for the finding message.
     *
     * @param Expr $expr - Asserted value expression rendered for human-readable finding text.
     *
     * @return string - Variable name or a generic value label.
     */
    private function describeValue(Expr $expr): string
    {
        // A named variable reads best in the message.
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $expr->name;
        }

        return 'value';
    }
}
