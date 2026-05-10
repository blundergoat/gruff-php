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
            $assignments = $finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\Assign);

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

                $reads[$var->name] ??= [];
                $reads[$var->name][] = $var;
            }

            foreach ($mockAssignments as $varName => $assignment) {
                if (!isset($reads[$varName])) {
                    // Variable never read at all - that's the unused-mock rule's territory; skip.
                    continue;
                }

                $methodNames = $this->methodNamesCalledOnVariable($scope, $finder, $varName);

                $hasVerification = $this->intersectsAny($methodNames, self::VERIFICATION_METHODS);
                if ($hasVerification) {
                    continue;
                }

                $hasStub = $this->intersectsAny($methodNames, self::STUB_METHODS);
                $variant = $hasStub ? 'stub-only' : 'dead-mock';
                $severity = $hasStub ? Severity::Advisory : Severity::Warning;

                $message = $hasStub
                    ? sprintf('%s sets up mock $%s with stub return values but never verifies any call.', $scope->symbol, $varName)
                    : sprintf('%s passes mock $%s around without setting up an expectation or stub.', $scope->symbol, $varName);

                $findings[] = new Finding(
                    ruleId: self::ID,
                    message: $message,
                    filePath: $unit->file->displayPath,
                    line: $assignment['line'],
                    severity: $severity,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::Medium,
                    symbol: $scope->symbol,
                    remediation: 'Either verify a method call with $mock->expects(...) / shouldReceive(), or replace the mock with a stub created via createStub() to make the intent explicit.',
                    metadata: ['variable' => $varName, 'variant' => $variant],
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
