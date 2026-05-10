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

final readonly class MockWithoutExpectationRule implements RuleInterface
{
    public const ID = 'test-quality.mock-without-expectation';

    private const VERIFICATION_METHODS = ['expects', 'shouldreceive', 'shouldhavebeencalled', 'shouldnotreceive'];
    private const STUB_METHODS = ['willreturn', 'willreturnmap', 'willreturncallback', 'willreturnonconsecutivecalls', 'willreturnself', 'willthrowexception', 'andreturn'];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Mock without expectation',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $findings = array_merge($findings, $this->findingsForScope($unit, $scope, $finder));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function findingsForScope(AnalysisUnit $unit, TestQualityScope $scope, NodeFinder $finder): array
    {
        $assignedVarObjectIds = [];
        $mockAssignments = $this->mockAssignments($scope, $finder, $assignedVarObjectIds);

        if ($mockAssignments === []) {
            return [];
        }

        $reads = $this->variableReads($scope, $finder, $assignedVarObjectIds);
        $findings = [];

        foreach ($mockAssignments as $varName => $assignment) {
            $finding = $this->findingForMock($unit, $scope, $finder, $varName, $assignment, $reads);

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param array<int, true> $assignedVarObjectIds
     * @return array<string, array{line: int, name: string}>
     */
    private function mockAssignments(
        TestQualityScope $scope,
        NodeFinder $finder,
        array &$assignedVarObjectIds,
    ): array {
        $mockAssignments = [];
        $assignments = $finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\Assign);

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

        return $mockAssignments;
    }

    /**
     * @param array<int, true> $assignedVarObjectIds
     * @return array<string, list<Expr\Variable>>
     */
    private function variableReads(TestQualityScope $scope, NodeFinder $finder, array $assignedVarObjectIds): array
    {
        $reads = [];

        foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\Variable) as $var) {
            if (!$var instanceof Expr\Variable || !is_string($var->name)) {
                continue;
            }

            if (isset($assignedVarObjectIds[spl_object_id($var)])) {
                continue;
            }

            $reads[$var->name] ??= [];
            $reads[$var->name][] = $var;
        }

        return $reads;
    }

    /**
     * @param array{line: int, name: string} $assignment
     * @param array<string, list<Expr\Variable>> $reads
     */
    private function findingForMock(
        AnalysisUnit $unit,
        TestQualityScope $scope,
        NodeFinder $finder,
        string $varName,
        array $assignment,
        array $reads,
    ): ?Finding {
        if (!isset($reads[$varName])) {
            return null;
        }

        $methodNames = $this->methodNamesCalledOnVariable($scope, $finder, $varName);
        if ($this->intersectsAny($methodNames, self::VERIFICATION_METHODS)) {
            return null;
        }

        $hasStub = $this->intersectsAny($methodNames, self::STUB_METHODS);
        $variant = $hasStub ? 'stub-only' : 'dead-mock';

        return new Finding(
            ruleId: self::ID,
            message: $this->mockMessage($scope->symbol, $varName, $hasStub),
            filePath: $unit->file->displayPath,
            line: $assignment['line'],
            severity: $hasStub ? Severity::Advisory : Severity::Warning,
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            confidence: Confidence::Medium,
            symbol: $scope->symbol,
            remediation: 'Either verify a method call with $mock->expects(...) / shouldReceive(), or replace the mock with a stub created via createStub() to make the intent explicit.',
            metadata: ['variable' => $varName, 'variant' => $variant],
        );
    }

    private function mockMessage(string $symbol, string $varName, bool $hasStub): string
    {
        if ($hasStub) {
            return sprintf('%s sets up mock $%s with stub return values but never verifies any call.', $symbol, $varName);
        }

        return sprintf('%s passes mock $%s around without setting up an expectation or stub.', $symbol, $varName);
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

    /**
     * @return list<string>
     */
    private function methodNamesCalledOnVariable(TestQualityScope $scope, NodeFinder $finder, string $varName): array
    {
        $names = [];

        foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\MethodCall) as $call) {
            if (!$call instanceof Expr\MethodCall) {
                continue;
            }

            if (!$this->chainRootsAtVariable($call, $varName)) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function chainRootsAtVariable(Expr\MethodCall $call, string $varName): bool
    {
        $receiver = $call->var;

        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Expr\Variable
            && is_string($receiver->name)
            && $receiver->name === $varName;
    }

    /**
     * @param list<string> $names
     * @param list<string> $needles
     */
    private function intersectsAny(array $names, array $needles): bool
    {
        foreach ($names as $name) {
            if (in_array($name, $needles, true)) {
                return true;
            }
        }

        return false;
    }
}
