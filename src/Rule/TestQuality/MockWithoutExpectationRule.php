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

/**
 * Detects mock objects that are used without verification expectations.
 */
final readonly class MockWithoutExpectationRule implements RuleInterface
{
    /**
     * Stable identifier for the mock-without-expectation rule.
     */
    public const ID = 'test-quality.mock-without-expectation';

    /**
     * Method names that prove a mock has an explicit expectation.
     */
    private const VERIFICATION_METHODS = ['expects', 'shouldreceive', 'shouldhavebeencalled', 'shouldnotreceive'];

    /**
     * Stub-only method names that do not prove behavior was verified.
     */
    private const STUB_METHODS = ['willreturn', 'willreturnmap', 'willreturncallback', 'willreturnonconsecutivecalls', 'willreturnself', 'willthrowexception', 'andreturn'];

    /**
     * Describe the mock-without-expectation rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Mock without expectation',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find mocks that are read without any verification call.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for mock variables that lack expectations.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $nodeFinder = new NodeFinder();
        $findings   = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $findings = array_merge($findings, $this->findingsForScope($analysisUnit, $scope, $nodeFinder));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function findingsForScope(AnalysisUnit $analysisUnit, TestQualityScope $scope, NodeFinder $finder): array
    {
        $assignedVarObjectIds = [];
        $mockAssignments      = $this->mockAssignments($scope, $finder, $assignedVarObjectIds);

        if ($mockAssignments === []) {
            return [];
        }

        $reads    = $this->variableReads($scope, $finder, $assignedVarObjectIds);
        $findings = [];

        foreach ($mockAssignments as $varName => $assignment) {
            $finding = $this->findingForMock(
                analysisUnit:       $analysisUnit,
                scope:      $scope,
                finder:     $finder,
                varName:    $varName,
                assignment: $assignment,
                reads:      $reads,
            );

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
        $assignments     = $finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\Assign);

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
     * @param array{line: int, name: string}     $assignment
     * @param array<string, list<Expr\Variable>> $reads
     *
     * @return Finding|null Finding for the mock assignment, or null when verified.
     */
    private function findingForMock(
        AnalysisUnit $analysisUnit,
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
        if ($this->hasAnyIntersection($methodNames, self::VERIFICATION_METHODS)) {
            return null;
        }

        $hasStub = $this->hasAnyIntersection($methodNames, self::STUB_METHODS);
        $variant = $hasStub ? 'stub-only' : 'dead-mock';

        return new Finding(
            ruleId:      self::ID,
            message:     $this->mockMessage($scope->symbol, $varName, $hasStub),
            filePath:    $analysisUnit->file->displayPath,
            line:        $assignment['line'],
            severity:    $hasStub ? Severity::Advisory : Severity::Warning,
            pillar:      Pillar::TestQuality,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            symbol:      $scope->symbol,
            remediation: 'Either verify a method call with $mock->expects(...) / shouldReceive(), or replace the mock with a stub created via createStub() to make the intent explicit.',
            metadata:    ['variable' => $varName, 'variant' => $variant],
        );
    }

    /**
     * Build the finding message for a mock variable.
     *
     * @return string Human-readable finding message.
     */
    private function mockMessage(string $symbol, string $varName, bool $hasStub): string
    {
        if ($hasStub) {
            return sprintf('%s sets up mock $%s with stub return values but never verifies any call.', $symbol, $varName);
        }

        return sprintf('%s passes mock $%s around without setting up an expectation or stub.', $symbol, $varName);
    }

    /**
     * Detect whether an expression creates a mock directly or through a method chain.
     *
     * @return bool True when the expression is recognised as mock creation.
     */
    private function isMockCreationExpression(Expr $expr): bool
    {
        if ($expr instanceof Expr\FuncCall || $expr instanceof Expr\StaticCall) {
            return TestQualityNodeHelper::isMockCreationCall($expr);
        }

        if (!$expr instanceof Expr\MethodCall) {
            return false;
        }

        return $this->isMockCreationCallChain($expr);
    }

    /**
     * Walk a method-call chain to see whether it originates at mock creation.
     *
     * @return bool True when any chain receiver creates a mock.
     */
    private function isMockCreationCallChain(Expr\MethodCall $call): bool
    {
        if (TestQualityNodeHelper::isMockCreationCall($call)) {
            return true;
        }

        $receiver = $call->var;
        while ($receiver instanceof Expr\MethodCall) {
            if (TestQualityNodeHelper::isMockCreationCall($receiver)) {
                return true;
            }

            $receiver = $receiver->var;
        }

        return ($receiver instanceof Expr\FuncCall || $receiver instanceof Expr\StaticCall)
            && TestQualityNodeHelper::isMockCreationCall($receiver);
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

            if (!$this->isChainRootedAtVariable($call, $varName)) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Check whether a method-call chain starts at the target variable.
     *
     * @return bool True when the chain root is the named variable.
     */
    private function isChainRootedAtVariable(Expr\MethodCall $call, string $varName): bool
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
     * Check whether two normalised method-name lists overlap.
     *
     * @param list<string> $names
     * @param list<string> $needles
     *
     * @return bool True when any name appears in the needle list.
     */
    private function hasAnyIntersection(array $names, array $needles): bool
    {
        foreach ($names as $name) {
            if (in_array($name, $needles, true)) {
                return true;
            }
        }

        return false;
    }
}
