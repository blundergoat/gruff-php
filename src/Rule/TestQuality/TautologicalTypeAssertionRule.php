<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * Detects type assertions that restate guarantees already made by the subject.
 */
final readonly class TautologicalTypeAssertionRule implements RuleInterface
{
    /**
     * Stable rule identifier for tautological type assertion findings.
     */
    public const ID = 'test-quality.tautological-type-assertion';

    /**
     * Describe the tautological type assertion rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Tautological type assertion',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find `assertInstanceOf` calls where the value type is already proven locally.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for redundant type assertions.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $nodeFinder = new NodeFinder();
        $findings   = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $localTypes = $this->collectLocalAssignmentTypes($scope, $nodeFinder);

            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                $name = TestQualityNodeHelper::callName($call);
                if ($name !== 'assertinstanceof') {
                    continue;
                }

                $expected = $this->classNameArg($call, 0);
                $valueArg = TestQualityNodeHelper::argValue($call, 1);
                if ($expected === null || $valueArg === null) {
                    continue;
                }

                $proven = $this->provenClass($valueArg, $localTypes);
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
                    severity:    Severity::Warning,
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
     * @return array<string, string>
     */
    private function collectLocalAssignmentTypes(TestQualityScope $scope, NodeFinder $nodeFinder): array
    {
        $types = [];

        foreach ($nodeFinder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\Assign) as $assign) {
            if (!$assign instanceof Expr\Assign) {
                continue;
            }

            if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }

            $class = $this->newClassName($assign->expr);
            if ($class !== null) {
                $types[$assign->var->name] = $class;
            }
        }

        return $types;
    }

    /**
     * @param array<string, string> $localTypes
     *
     * @return string|null Proven class name, or null when it cannot be inferred.
     */
    private function provenClass(Expr $expr, array $localTypes): ?string
    {
        $direct = $this->newClassName($expr);
        if ($direct !== null) {
            return $direct;
        }

        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $localTypes[$expr->name] ?? null;
        }

        return null;
    }

    /**
     * Extract the class name from a direct `new ClassName` expression.
     *
     * @return string|null Constructed class name, or null for dynamic/unsupported expressions.
     */
    private function newClassName(Expr $expr): ?string
    {
        if ($expr instanceof Expr\New_ && $expr->class instanceof Name) {
            return $expr->class->toString();
        }

        return null;
    }

    /**
     * Extract a `ClassName::class` argument from an assertion call.
     *
     * @return string|null Class name string, or null when the argument is not a class constant.
     */
    private function classNameArg(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?string
    {
        $classConstFetch = TestQualityNodeHelper::argValue($call, $index);
        if (!$classConstFetch instanceof Expr\ClassConstFetch || !$classConstFetch->class instanceof Name) {
            return null;
        }

        $name = $classConstFetch->name;
        if (!$name instanceof Node\Identifier || strtolower($name->toString()) !== 'class') {
            return null;
        }

        return $classConstFetch->class->toString();
    }

    /**
     * Describe the asserted value for the finding message.
     *
     * @return string Variable name or a generic value label.
     */
    private function describeValue(Expr $expr): string
    {
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $expr->name;
        }

        return 'value';
    }
}
