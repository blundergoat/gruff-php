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

/**
 * Flags a single test that drives several distinct system-under-test calls and asserts many times over them,
 * a sign one method covers multiple behaviours and would fail for more than one reason.
 *
 * It counts the distinct calls on the busiest receiver against an assertion floor, ignoring output reads and teardown,
 * so a test that merely inspects one result is left alone. Advisory at low confidence.
 */
final readonly class EagerTestRule implements RuleInterface
{
    /**
     * Method-name prefixes that mark a call as reading test output rather than exercising the SUT.
     *
     * @var list<string>
     */
    private const OBSERVATION_METHOD_PREFIXES = [
        'get',
        'has',
        'is',
    ];

    /**
     * Receiver variable names that usually hold produced output, not the system under test.
     *
     * @var list<string>
     */
    private const OBSERVATION_RECEIVERS = [
        'decoded',
        'diagnostics',
        'finding',
        'findings',
        'html',
        'output',
        'process',
        'report',
        'response',
        'result',
        'results',
        'summary',
    ];

    /**
     * Process-control methods that drive a subprocess harness rather than the behaviour under test.
     *
     * @var list<string>
     */
    private const PROCESS_HARNESS_METHODS = [
        'settimeout',
        'start',
        'stop',
        'wait',
    ];

    /**
     * Method names that configure the harness before the behaviour under test runs.
     *
     * @var list<string>
     */
    private const SETUP_METHODS = [
        'settimeout',
    ];

    /**
     * Stable rule identifier for eager test findings.
     */
    public const ID = 'test-quality.eager-test';

    /**
     * Describes the eager-test rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        // Advisory by default with a 3-assertion floor, so casual two-assert tests never trip this heuristic.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Eager test',
            pillar:            Pillar::TestQuality,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Low,
            defaultThresholds: ['minAssertions' => 3],
            falsePositiveShapes: [
                [
                    'shape'      => 'One scenario that legitimately drives several calls on the same receiver, such as a builder chain or a state machine stepped through its transitions.',
                    'mitigation' => 'Distinct calls on the busiest receiver are counted without judging whether they form one scenario, so raise this rule\'s minAssertions threshold.',
                ],
            ],
        );
    }

    /**
     * Reports tests that assert many times across multiple apparent SUT calls.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for eager tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition    = $this->definition();
        $minAssertions = (int) $ruleContext->settingsFor($definition)->numericThreshold('minAssertions');
        $findings      = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $assertionCount = count(TestQualityNodeHelper::assertionCalls($scope));
            $sutCalls       = $this->distinctSutCalls($scope);

            // A test below the assertion floor, or exercising a single SUT call, is focused enough.
            if ($assertionCount < $minAssertions || count($sutCalls) < 2) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s asserts %d times across multiple apparent SUT calls.', $scope->symbol, $assertionCount),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->anchorLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Low,
                symbol:      $scope->symbol,
                remediation: 'Split unrelated behaviors into focused tests when the assertions cover different responsibilities.',
                metadata:    ['assertions' => $assertionCount, 'sutCalls' => array_values($sutCalls)],
            );
        }

        return $findings;
    }

    /**
     * Collects the distinct method calls made on likely system-under-test receivers.
     *
     * @param  TestQualityScope $scope - Single test method whose body is searched for SUT exercise calls.
     *
     * @return array<string, string> - Distinct method names keyed by name, taken from the busiest receiver only.
     */
    private function distinctSutCalls(TestQualityScope $scope): array
    {
        $resultVariables = $this->collectResultVariables($scope);
        $callsByReceiver = [];

        // Weigh every call the test makes.
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            $name = TestQualityNodeHelper::callName($call);
            // Set aside assertions, mock plumbing, nested-in-assertion calls, teardown, and output reads.
            if ($name === null
                || TestQualityNodeHelper::isAssertionCall($call)
                || TestQualityNodeHelper::isMockCreationCall($call)
                || TestQualityNodeHelper::isMockVerificationCall($call)
                || $this->isNestedInAssertionCall($call)
                || $this->isNestedInFinallyBlock($call)
                || $this->isObservationCall($call, $name)
            ) {
                continue;
            }

            // A call on a stored result value reads that value, so it is not a fresh SUT call.
            if ($call instanceof Expr\MethodCall && $this->isResultVariableReceiver($call->var, $resultVariables)) {
                continue;
            }

            $receiver = $this->receiverKey($call);
            // A call with no keyable receiver cannot be grouped by SUT instance.
            if ($receiver === null) {
                continue;
            }

            $callsByReceiver[$receiver][$name] = $name;
        }

        return $this->largestReceiverCallSet($callsByReceiver);
    }

    /**
     * Reports whether the call sits inside an enclosing assertion expression.
     *
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Candidate SUT call whose ancestors are
     *                                                            walked for an enclosing assertion.
     *
     * @return bool - True when the call is only part of an assertion expression.
     */
    private function isNestedInAssertionCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        // Walk up the ancestor chain from the call.
        while ($parent instanceof Node) {
            // An enclosing assertion means the call is only an argument to it, not SUT exercise.
            if (($parent instanceof Expr\FuncCall || $parent instanceof Expr\MethodCall || $parent instanceof Expr\StaticCall)
                && TestQualityNodeHelper::isAssertionCall($parent)
            ) {
                return true;
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Reports whether the call sits inside a finally block, marking it as teardown.
     *
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Candidate call whose ancestors are
     *                                                            walked for an enclosing finally block.
     *
     * @return bool - True when the call belongs to teardown rather than exercise.
     */
    private function isNestedInFinallyBlock(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        // Climb the ancestor chain from the call.
        while ($parent instanceof Node) {
            // A finally block is teardown, so a call inside it is cleanup, not exercise.
            if ($parent instanceof Node\Stmt\Finally_) {
                return true;
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Reports whether the call reads or shapes test output rather than exercising the SUT.
     *
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Candidate call under inspection; only
     *                                                            method calls can be observations.
     * @param  string $name - Called method name resolved by the caller; compared against the lower-case
     *                      observation conventions.
     *
     * @return bool - True when the call reads or shapes test output rather than exercising the SUT.
     */
    private function isObservationCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, string $name): bool
    {
        // Only a method call can be an observation read.
        if (!$call instanceof Expr\MethodCall) {
            return false;
        }

        // A bare `$this->...` call is a test-case helper, not the SUT.
        if ($this->isDirectThisCall($call)) {
            return true;
        }

        // Harness setup calls configure the test rather than the behaviour.
        if (in_array($name, self::SETUP_METHODS, true)) {
            return true;
        }

        $receiver = $this->receiverName($call->var);

        // Process control methods drive a subprocess harness, not the SUT.
        if ($receiver === 'process' && in_array($name, self::PROCESS_HARNESS_METHODS, true)) {
            return true;
        }

        return $receiver !== null
            && in_array($receiver, self::OBSERVATION_RECEIVERS, true)
            && $this->isObservationMethodName($name);
    }

    /**
     * Reports whether the method call targets the bare `$this` test case.
     *
     * @param  Expr\MethodCall $call - Method call whose receiver is checked for being the bare `$this` test case.
     *
     * @return bool - True when the call is a direct test-case helper call.
     */
    private function isDirectThisCall(Expr\MethodCall $call): bool
    {
        return $call->var instanceof Expr\Variable
            && $call->var->name === 'this';
    }

    /**
     * Returns a stable receiver identity for grouping SUT calls, or null.
     *
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call to key by receiver; free functions
     *                                                            and self/parent/static yield null.
     *
     * @return string|null - Stable receiver identity for SUT call grouping, or null when the call has no groupable receiver.
     */
    private function receiverKey(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        // A free function has no receiver to key on.
        if ($call instanceof Expr\FuncCall) {
            return null;
        }

        // A static call keys on its class name.
        if ($call instanceof Expr\StaticCall) {
            // A dynamic class expression offers no stable name to key.
            if (!$call->class instanceof Node\Name) {
                return null;
            }

            $class = strtolower($call->class->toString());
            // self/parent/static point back at the test, not a separate SUT instance.
            if (in_array($class, ['parent', 'self', 'static'], true)) {
                return null;
            }

            return 'static:' . $class;
        }

        return $this->receiverExpressionKey($call->var);
    }

    /**
     * Returns the receiver identity for a method-call receiver expression, or null.
     *
     * @param  Expr $receiver - Receiver expression to key; method-call chains are unwound to their root before keying.
     *
     * @return string|null - Receiver identity for method-call grouping, or null when the receiver is dynamic.
     */
    private function receiverExpressionKey(Expr $receiver): ?string
    {
        // Unwind a method-call chain down to its root receiver.
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        // A plain variable keys by its name.
        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            return 'var:' . strtolower($receiver->name);
        }

        // A fresh construction keys by the class built.
        if ($receiver instanceof Expr\New_ && $receiver->class instanceof Node\Name) {
            return 'new:' . strtolower($receiver->class->toString());
        }

        // A property-held SUT keys through its property path.
        if ($receiver instanceof Expr\PropertyFetch) {
            return $this->propertyReceiverKey($receiver);
        }

        return null;
    }

    /**
     * Returns the receiver identity for a property-held SUT such as `$this->sut`, or null.
     *
     * @param  Expr\PropertyFetch $receiver - Property access (such as `$this->sut`) keyed as owner plus
     *                                      property name; dynamic names yield null.
     *
     * @return string|null - Receiver identity for property-held SUTs, or null when the property or owner is dynamic.
     */
    private function propertyReceiverKey(Expr\PropertyFetch $receiver): ?string
    {
        // A dynamic property name cannot be keyed.
        if (!$receiver->name instanceof Node\Identifier) {
            return null;
        }

        $owner = $this->receiverExpressionKey($receiver->var);
        // Without a keyable owner the property path is unusable.
        if ($owner === null) {
            return null;
        }

        return $owner . '->' . strtolower($receiver->name->toString());
    }

    /**
     * Returns the receiver with the widest distinct call surface.
     *
     * @param  array<string, array<string, string>> $callsByReceiver - Distinct call-name sets keyed by receiver identity.
     *
     * @return array<string, string> - The single widest call set; empty when no receiver was recorded.
     */
    private function largestReceiverCallSet(array $callsByReceiver): array
    {
        $largest = [];

        // Weigh each receiver's distinct call set.
        foreach ($callsByReceiver as $calls) {
            // Keep the widest set seen so far.
            if (count($calls) > count($largest)) {
                $largest = $calls;
            }
        }

        return $largest;
    }

    /**
     * Returns the root receiver variable name, or null for dynamic receivers.
     *
     * @param  Expr $receiver - Receiver expression unwound through method-call chains to its root variable.
     *
     * @return string|null - Receiver variable name, or null for dynamic/non-variable receivers.
     */
    private function receiverName(Expr $receiver): ?string
    {
        // Follow a method-call chain back to its root receiver.
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        // A dynamic or non-variable receiver has no stable name.
        if (!$receiver instanceof Expr\Variable || !is_string($receiver->name)) {
            return null;
        }

        return strtolower($receiver->name);
    }

    /**
     * Reports whether the method name follows a result-observation convention.
     *
     * @param  string $name - Method name to classify against the get/has/is prefixes and the bare `count` reader.
     *
     * @return bool - True when the method name follows a result-observation convention.
     */
    private function isObservationMethodName(string $name): bool
    {
        // Weigh each observation prefix.
        foreach (self::OBSERVATION_METHOD_PREFIXES as $prefix) {
            // A get/has/is prefix marks a call that reads a result.
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return $name === 'count';
    }

    /**
     * Collects the variables that receive call-result values.
     *
     * @param  TestQualityScope $scope - Test method whose assignments are scanned for call-result variables.
     *
     * @return array<string, true> - Set of local variable names (keyed by name) that hold a call result.
     */
    private function collectResultVariables(TestQualityScope $scope): array
    {
        $variables = [];

        // Weigh every assignment in the test body.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]) as $assign) {
            // Only assignments to a plain variable name can be tracked.
            if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }

            // A variable assigned a call result holds produced output.
            if ($this->isCallChainExpression($assign->expr)) {
                $variables[$assign->var->name] = true;
            }
        }

        return $variables;
    }

    /**
     * Reports whether the expression is a call whose result marks its target as a result value.
     *
     * @param  Expr $expr - Right-hand side of an assignment; a call expression marks its target as a result variable.
     *
     * @return bool - True when the expression is a call result.
     */
    private function isCallChainExpression(Expr $expr): bool
    {
        // A call result is a result variable, so later method calls on it read that result rather than exercising the SUT again.
        //
        // `new X()` is deliberately excluded: a constructor output is usually the SUT itself, so calls on it are genuine exercise.
        return $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall
            || $expr instanceof Expr\FuncCall;
    }

    /**
     * Reports whether the receiver roots at a known result variable.
     *
     * @param  Expr                $receiver - Receiver expression unwound to its root variable before
     *                                              the membership test.
     * @param  array<string, true> $resultVariables - Result-variable name set from collectResultVariables(),
     *                                              keyed by variable name.
     *
     * @return bool - True when the receiver roots at a known result variable.
     */
    private function isResultVariableReceiver(Expr $receiver, array $resultVariables): bool
    {
        // Reduce a method-call chain to its root receiver.
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Expr\Variable
            && is_string($receiver->name)
            && isset($resultVariables[$receiver->name]);
    }
}
