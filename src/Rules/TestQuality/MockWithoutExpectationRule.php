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
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Flags a mock that is passed around without an expectation or intentional configuration. PHPUnit/Mockery
 * stubs remain advisory, while native Prophecy predictions and a revealed double asserted by identity count as
 * configured. Runs over every test. Warning, medium confidence.
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
     * Prophecy methods that configure behaviour or register a prediction on a confirmed prophecy handle.
     *
     * @var list<string>
     */
    private const PROPHECY_CONFIGURATION_METHODS = [
        'shouldbecalled',
        'shouldbecalledonce',
        'shouldbecalledtimes',
        'shouldhavebeencalled',
        'shouldnothavebeencalled',
        'willreturn',
        'willreturncallback',
        'willthrow',
    ];

    /**
     * Describes the mock-without-expectation rule for the registry and reports.
     *
     * @return RuleDefinition - id, name, pillar, tier and the default Warning/Medium severity the engine reads
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
     * Reports mocks that are read without any verification call.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one entry per unverified mock across all test scopes; empty when every mock is verified
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $findings = array_merge($findings, $this->findingsForScope($analysisUnit, $scope));
        }

        return $findings;
    }

    /**
     * Builds the mock-expectation findings for one test scope.
     *
     * @param AnalysisUnit     $analysisUnit - Parsed unit supplying the display path recorded on each finding.
     * @param TestQualityScope $scope - Single test method or function whose mock usage is examined.
     *
     * @return list<Finding> - one finding per unverified mock in this scope; empty when every mock is verified
     */
    private function findingsForScope(AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $assignedVarObjectIds = [];
        $mockAssignments      = $this->mockAssignments($scope, $assignedVarObjectIds);

        if ($mockAssignments === []) {
            // No mocks were created in this scope, so there is nothing to verify expectations against.
            return [];
        }

        $reads    = $this->variableReads($scope, $assignedVarObjectIds);
        $findings = [];

        // Weigh each mock the scope created.
        foreach ($mockAssignments as $varName => $assignment) {
            $finding = $this->findingForMock(
                analysisUnit: $analysisUnit,
                scope:        $scope,
                varName:      $varName,
                assignment:   $assignment,
                reads:        $reads,
            );

            // Keep the finding when the mock proved unverified.
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Collects the mock variables created in a test scope.
     *
     * @param TestQualityScope $scope - Test scope whose assignments are scanned for mock creation.
     * @param array<int, true> $assignedVarObjectIds - Out-param: records the object id of every assigned variable
     *                                                 node so {@see variableReads()} can exclude the write side and
     *                                                 keep only genuine reads.
     *
     * @return array<string, array{line: int, name: string, isProphecy: bool}> - mock variables keyed by name (sigil
     *                                                                          stripped), with creation evidence used
     *                                                                          to apply framework-specific semantics
     */
    private function mockAssignments(
        TestQualityScope $scope,
        array            &$assignedVarObjectIds,
    ): array {
        $mockAssignments = [];
        $assignments     = NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]);

        // Weigh every assignment in the test body.
        foreach ($assignments as $assign) {
            // Only a plainly named variable target can be tracked.
            if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }

            $assignedVarObjectIds[spl_object_id($assign->var)] = true;

            // Record the target only when the right-hand side creates a mock.
            if (!$this->isMockCreationExpression($assign->expr)) {
                continue;
            }

            $varName                   = $assign->var->name;
            $mockAssignments[$varName] ??= [
                'line' => $assign->getStartLine(),
                'name' => $varName,
                'isProphecy' => $this->isProphecyCreationExpression($assign->expr),
            ];
        }

        return $mockAssignments;
    }

    /**
     * Collects the read occurrences of each variable, excluding assignment targets.
     *
     * @param TestQualityScope $scope - Test scope whose variable nodes are walked.
     * @param array<int, true> $assignedVarObjectIds - Object ids of assignment-target nodes, used to skip the write
     *                                                 side so a mock that is only assigned and never read is treated
     *                                                 as unused rather than flagged for a missing expectation.
     *
     * @return array<string, list<Expr\Variable>> - genuine read occurrences grouped by variable name with the
     *                                              assignment-target nodes excluded, so a non-empty group means the
     *                                              mock is consulted; a variable absent here was only assigned
     */
    private function variableReads(TestQualityScope $scope, array $assignedVarObjectIds): array
    {
        $reads = [];

        // Weigh every variable node in the test body.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Variable::class]) as $var) {
            // Skip variable-variables with no static name.
            if (!is_string($var->name)) {
                continue;
            }

            // Skip the assignment target itself; that write is not a read.
            if (isset($assignedVarObjectIds[spl_object_id($var)])) {
                continue;
            }

            $reads[$var->name]   ??= [];
            $reads[$var->name][] = $var;
        }

        return $reads;
    }

    /**
     * Builds a finding when a mock variable lacks a verifying call, or null.
     *
     * @param AnalysisUnit                       $analysisUnit - Parsed unit supplying the display path for the finding.
     * @param TestQualityScope                   $scope - Test scope the mock lives in; its symbol labels findings.
     * @param string                             $varName - Mock variable name without the leading sigil.
     * @param array{line: int, name: string, isProphecy: bool} $assignment - Creation evidence and finding location.
     * @param array<string, list<Expr\Variable>> $reads - Read occurrences per variable; a mock never read here is
     *                                                          left to the unused-mock rule and not flagged.
     *
     * @return Finding|null - finding anchored at the mock's creation line, or null when the mock is verified or
     *                        never read (the latter is left to the unused-mock rule)
     */
    private function findingForMock(
        AnalysisUnit     $analysisUnit,
        TestQualityScope $scope,
        string           $varName,
        array            $assignment,
        array            $reads,
    ): ?Finding {
        if (!isset($reads[$varName])) {
            // Assigned but never read: that is dead-mock territory for a different rule, not ours.
            return null;
        }

        $methodNames = $this->methodNamesCalledOnVariable($scope, $varName);
        if ($this->hasAnyIntersection($methodNames, self::VERIFICATION_METHODS)) {
            // An explicit expectation (expects/shouldReceive) proves intent, so the mock is fine.
            return null;
        }

        // Prophecy has its own prediction vocabulary; applying it only to prophesize() avoids cross-framework guesses.
        if ($assignment['isProphecy']
            && ($this->hasAnyIntersection($methodNames, self::PROPHECY_CONFIGURATION_METHODS)
                || $this->hasProphecyRevealInAssertion($scope, $varName))
        ) {
            return null;
        }

        $hasStub = $this->hasAnyIntersection($methodNames, self::STUB_METHODS);
        $variant = $hasStub ? 'stub-only' : 'dead-mock';

        // Reached only for an unverified mock; stub-only downgrades severity, a bare mock stays a warning.
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
     * Builds the finding message for a mock variable.
     *
     * @param string $symbol - Enclosing test symbol named in the message so the reader can locate the mock.
     * @param string $varName - Mock variable name without the sigil; rendered as $name in the message.
     * @param bool   $hasStub - True selects the stub-only wording (has return setup), false the bare-mock wording.
     *
     * @return string - finding message naming the test symbol and $varName, worded for the stub-only or bare-mock case
     */
    private function mockMessage(string $symbol, string $varName, bool $hasStub): string
    {
        if ($hasStub) {
            // Stub-only: return values are wired but no call is asserted, so the wording names that gap.
            return sprintf('%s sets up mock $%s with stub return values but never verifies any call.', $symbol, $varName);
        }

        // Bare mock: neither expectation nor stub, so the message points at the missing setup entirely.
        return sprintf('%s passes mock $%s around without setting up an expectation or stub.', $symbol, $varName);
    }

    /**
     * Reports whether an expression creates a mock directly or through a method chain.
     *
     * @param Expr $expr - Right-hand side of an assignment being classified as mock creation or not.
     *
     * @return bool - true when the expression is a recognised mock creator (direct call or builder chain), false otherwise
     */
    private function isMockCreationExpression(Expr $expr): bool
    {
        if ($expr instanceof Expr\FuncCall || $expr instanceof Expr\StaticCall) {
            // Direct call form such as createMock(...) or Mockery::mock(...) is decided in one hop.
            return TestQualityNodeHelper::isMockCreationCall($expr);
        }

        if (!$expr instanceof Expr\MethodCall) {
            // Only call expressions can create a mock; anything else (literal, variable) cannot.
            return false;
        }

        // Method-call form may builder-chain off a mock creator, so defer to the chain walker.
        return $this->isMockCreationCallChain($expr);
    }

    /**
     * Reports whether an expression creates a Prophecy handle directly.
     *
     * @param Expr $expression - Mock-creation expression whose framework vocabulary must be selected.
     *
     * @return bool - True only for a statically named prophesize() call.
     */
    private function isProphecyCreationExpression(Expr $expression): bool
    {
        // Framework-specific suppression is unsafe when the creation API cannot be resolved statically.
        if (!$expression instanceof Expr\FuncCall
            && !$expression instanceof Expr\MethodCall
            && !$expression instanceof Expr\StaticCall
        ) {
            return false;
        }

        return TestQualityNodeHelper::callName($expression) === 'prophesize';
    }

    /**
     * Reports whether a method-call chain originates at a mock creator.
     *
     * @param Expr\MethodCall $call - Outermost call of a builder chain (e.g. ...->getMock()) to trace back to its root.
     *
     * @return bool - true when the outermost call or any receiver back to the chain root is a mock creator
     */
    private function isMockCreationCallChain(Expr\MethodCall $call): bool
    {
        if (TestQualityNodeHelper::isMockCreationCall($call)) {
            // The outermost call is itself the creator; no need to unwind the receiver.
            return true;
        }

        $receiver = $call->var;
        // Unwind the receiver chain looking for a mock creator.
        while ($receiver instanceof Expr\MethodCall) {
            if (TestQualityNodeHelper::isMockCreationCall($receiver)) {
                // A mid-chain builder step (getMockBuilder()->...) is the creator we were after.
                return true;
            }

            $receiver = $receiver->var;
        }

        // Chain root is a free or static call; it is mock creation only if that call qualifies.
        return ($receiver instanceof Expr\FuncCall || $receiver instanceof Expr\StaticCall)
               && TestQualityNodeHelper::isMockCreationCall($receiver);
    }

    /**
     * Lists the method names called on a specific variable.
     *
     * @param TestQualityScope $scope - Test scope searched for calls rooted at the variable.
     * @param string           $varName - Variable whose method calls are collected, without the sigil.
     *
     * @return list<string> - method names called on the variable in call order (order irrelevant to callers);
     *                       empty when the variable receives no method calls
     */
    private function methodNamesCalledOnVariable(TestQualityScope $scope, string $varName): array
    {
        $names = [];

        // Weigh every method call in the test body.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\MethodCall::class]) as $call) {
            // Only calls rooted at this variable count.
            if (!$this->isChainRootedAtVariable($call, $varName)) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            // Record each resolvable method name called on it.
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Reports whether an assertion directly consumes reveal() from a prophecy handle.
     *
     * @param TestQualityScope $scope - Test scope containing the prophecy and candidate assertion.
     * @param string           $varName - Prophecy variable name without the sigil.
     *
     * @return bool - True when reveal() from this prophecy is nested inside a recognised assertion.
     */
    private function hasProphecyRevealInAssertion(TestQualityScope $scope, string $varName): bool
    {
        // Merely passing reveal() into the subject is setup; only an assertion makes the revealed identity evidence.
        foreach (TestQualityNodeHelper::assertionCalls($scope) as $assertionCall) {
            $nestedMethodCalls = (new NodeFinder())->findInstanceOf($assertionCall->args, Expr\MethodCall::class);
            foreach ($nestedMethodCalls as $nestedMethodCall) {
                if (TestQualityNodeHelper::callName($nestedMethodCall) === 'reveal'
                    && $this->isChainRootedAtVariable($nestedMethodCall, $varName)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reports whether a method-call chain is rooted at the target variable.
     *
     * @param Expr\MethodCall $call - Call whose receiver chain is unwound to find its base expression.
     * @param string          $varName - Variable name the chain must bottom out at to count as rooted there.
     *
     * @return bool - true when the receiver chain bottoms out at exactly $varName, false for any other base receiver
     */
    private function isChainRootedAtVariable(Expr\MethodCall $call, string $varName): bool
    {
        $receiver = $call->var;

        // Unwind the receiver chain to its base expression.
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Expr\Variable
               && is_string($receiver->name)
               && $receiver->name === $varName;
    }

    /**
     * Reports whether two method-name lists overlap.
     *
     * @param list<string> $names - Normalised method names collected from the test body.
     * @param list<string> $needles - Normalised expectation method names to look for.
     *
     * @return bool - true on the first $names entry found in $needles, false when the two lists are disjoint
     */
    private function hasAnyIntersection(array $names, array $needles): bool
    {
        // Look for the first shared name between the lists.
        foreach ($names as $name) {
            if (in_array($name, $needles, true)) {
                // First shared entry is enough to confirm overlap; stop scanning.
                return true;
            }
        }

        return false;
    }
}
