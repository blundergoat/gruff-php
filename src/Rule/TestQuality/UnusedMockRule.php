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
use PhpParser\NodeFinder;

final readonly class UnusedMockRule implements RuleInterface
{
    public const ID = 'test-quality.unused-mock';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Unused mock variable',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $assignments = $finder->find(
                $scope->statements,
                static fn (Node $node): bool => $node instanceof Expr\Assign,
            );

            $mockAssignments = [];
            $assignedVarObjectIds = [];

            foreach ($assignments as $assign) {
                if (!$assign instanceof Expr\Assign) {
                    continue;
                }

                if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
                    continue;
                }

                $assignedVarObjectIds[spl_object_id($assign->var)] = true;

                if (!$this->isMockCreationExpression($assign->expr)) {
                    continue;
                }

                $varName = $assign->var->name;
                $mockAssignments[$varName] ??= [
                    'line' => $assign->getStartLine(),
                    'name' => $varName,
                ];
            }

            if ($mockAssignments === []) {
                continue;
            }

            $reads = [];
            foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\Variable) as $var) {
                if (!$var instanceof Expr\Variable || !is_string($var->name)) {
                    continue;
                }

                if (isset($assignedVarObjectIds[spl_object_id($var)])) {
                    continue;
                }

                $reads[$var->name] = true;
            }

            foreach ($mockAssignments as $varName => $assignment) {
                if (isset($reads[$varName])) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: self::ID,
                    message: sprintf(
                        '%s creates mock $%s but never reads it.',
                        $scope->symbol,
                        $varName,
                    ),
                    filePath: $unit->file->displayPath,
                    line: $assignment['line'],
                    severity: Severity::Advisory,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::High,
                    symbol: $scope->symbol,
                    remediation: 'Use the mock to set expectations or pass it to the SUT, or remove the unused mock creation.',
                    metadata: ['variable' => $varName],
                );
            }
        }

        return $findings;
    }

    private function isMockCreationExpression(Expr $expr): bool
    {
        $finder = new NodeFinder();

        $matches = $finder->find(
            [$expr],
            static fn (Node $node): bool => ($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
                && TestQualityNodeHelper::isMockCreationCall($node),
        );

        return $matches !== [];
    }
}
