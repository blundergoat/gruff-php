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

final readonly class TautologicalTypeAssertionRule implements RuleInterface
{
    public const ID = 'test-quality.tautological-type-assertion';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Tautological type assertion',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $localTypes = $this->collectLocalAssignmentTypes($scope, $finder);

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
                    ruleId: self::ID,
                    message: sprintf(
                        '%s asserts $%s is an instance of %s, but it is statically already that type.',
                        $scope->symbol,
                        $this->describeValue($valueArg),
                        $expected,
                    ),
                    filePath: $unit->file->displayPath,
                    line: $call->getStartLine(),
                    severity: Severity::Warning,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::High,
                    symbol: $scope->symbol,
                    remediation: 'Drop the redundant assertInstanceOf or assert behaviour on the value instead of its type.',
                    metadata: ['expected' => $expected],
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function collectLocalAssignmentTypes(TestQualityScope $scope, NodeFinder $finder): array
    {
        $types = [];

        foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\Assign) as $assign) {
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
     */
    private function provenClass(Expr $value, array $localTypes): ?string
    {
        $direct = $this->newClassName($value);
        if ($direct !== null) {
            return $direct;
        }

        if ($value instanceof Expr\Variable && is_string($value->name)) {
            return $localTypes[$value->name] ?? null;
        }

        return null;
    }

    private function newClassName(Expr $expr): ?string
    {
        if ($expr instanceof Expr\New_ && $expr->class instanceof Name) {
            return $expr->class->toString();
        }

        return null;
    }

    private function classNameArg(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?string
    {
        $value = TestQualityNodeHelper::argValue($call, $index);
        if (!$value instanceof Expr\ClassConstFetch || !$value->class instanceof Name) {
            return null;
        }

        $name = $value->name;
        if (!$name instanceof Node\Identifier || strtolower($name->toString()) !== 'class') {
            return null;
        }

        return $value->class->toString();
    }

    private function describeValue(Expr $value): string
    {
        if ($value instanceof Expr\Variable && is_string($value->name)) {
            return $value->name;
        }

        return 'value';
    }
}
