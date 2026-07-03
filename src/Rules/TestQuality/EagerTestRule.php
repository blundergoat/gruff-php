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
 * Detects tests that cover multiple behaviors in one method.
 */
final readonly class EagerTestRule implements RuleInterface
{
    /**
     * @var list<string>
     */
    private const OBSERVATION_METHOD_PREFIXES = [
        'get',
        'has',
        'is',
    ];

    /**
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
     * @var list<string>
     */
    private const PROCESS_HARNESS_METHODS = [
        'settimeout',
        'start',
        'stop',
        'wait',
    ];

    /**
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
     * Describe the eager test rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        );
    }

    /**
     * Find tests that assert many times across multiple apparent SUT calls.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $assertionCount = count(TestQualityNodeHelper::assertionCalls($scope));
            $sutCalls       = $this->distinctSutCalls($scope);

            // User view: choose the findings list branch for this case.
            if ($assertionCount < $minAssertions || count($sutCalls) < 2) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s asserts %d times across multiple apparent SUT calls.', $scope->symbol, $assertionCount),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
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
     * Collect distinct method calls made on likely system-under-test receivers.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  TestQualityScope $scope - Single test method whose body is searched for SUT exercise calls.
     *
     * @return array<string, string> - Distinct method names keyed by name, taken from the busiest receiver only.
     */
    private function distinctSutCalls(TestQualityScope $scope): array
    {
        $resultVariables = $this->collectResultVariables($scope);
        $callsByReceiver = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            $name = TestQualityNodeHelper::callName($call);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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

            // User view: choose the findings list branch for this case.
            if ($call instanceof Expr\MethodCall && $this->isResultVariableReceiver($call->var, $resultVariables)) {
                continue;
            }

            $receiver = $this->receiverKey($call);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($receiver === null) {
                continue;
            }

            $callsByReceiver[$receiver][$name] = $name;
        }

        return $this->largestReceiverCallSet($callsByReceiver);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Candidate SUT call whose ancestors are
     *                                                            walked for an enclosing assertion.
     *
     * @return bool - True when the call is only part of an assertion expression.
     */
    private function isNestedInAssertionCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        while ($parent instanceof Node) {
            // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Candidate call whose ancestors are
     *                                                            walked for an enclosing finally block.
     *
     * @return bool - True when the call belongs to teardown rather than exercise.
     */
    private function isNestedInFinallyBlock(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        while ($parent instanceof Node) {
            // User view: choose the findings list branch for this case.
            if ($parent instanceof Node\Stmt\Finally_) {
                return true;
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
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
        // User view: choose the findings list branch for this case.
        if (!$call instanceof Expr\MethodCall) {
            return false;
        }

        // User view: choose the findings list branch for this case.
        if ($this->isDirectThisCall($call)) {
            return true;
        }

        // User view: choose the findings list branch for this case.
        if (in_array($name, self::SETUP_METHODS, true)) {
            return true;
        }

        $receiver = $this->receiverName($call->var);

        // User view: choose the findings list branch for this case.
        if ($receiver === 'process' && in_array($name, self::PROCESS_HARNESS_METHODS, true)) {
            return true;
        }

        // User view: missing data becomes the expected findings list state.
        return $receiver !== null
            && in_array($receiver, self::OBSERVATION_RECEIVERS, true)
            && $this->isObservationMethodName($name);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call to key by receiver; free functions
     *                                                            and self/parent/static yield null.
     *
     * @return string|null - Stable receiver identity for SUT call grouping.
     */
    private function receiverKey(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        // User view: choose the findings list branch for this case.
        if ($call instanceof Expr\FuncCall) {
            return null;
        }

        // User view: choose the findings list branch for this case.
        if ($call instanceof Expr\StaticCall) {
            // User view: choose the findings list branch for this case.
            if (!$call->class instanceof Node\Name) {
                return null;
            }

            $class = strtolower($call->class->toString());
            // User view: choose the findings list branch for this case.
            if (in_array($class, ['parent', 'self', 'static'], true)) {
                return null;
            }

            return 'static:' . $class;
        }

        return $this->receiverExpressionKey($call->var);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  Expr $receiver - Receiver expression to key; method-call chains are unwound to their root before keying.
     *
     * @return string|null - Receiver identity for method-call grouping.
     */
    private function receiverExpressionKey(Expr $receiver): ?string
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        // User view: choose the findings list branch for this case.
        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            return 'var:' . strtolower($receiver->name);
        }

        // User view: choose the findings list branch for this case.
        if ($receiver instanceof Expr\New_ && $receiver->class instanceof Node\Name) {
            return 'new:' . strtolower($receiver->class->toString());
        }

        // User view: choose the findings list branch for this case.
        if ($receiver instanceof Expr\PropertyFetch) {
            return $this->propertyReceiverKey($receiver);
        }

        return null;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  Expr\PropertyFetch $receiver - Property access (such as `$this->sut`) keyed as owner plus
     *                                      property name; dynamic names yield null.
     *
     * @return string|null - Receiver identity for property-held SUTs.
     */
    private function propertyReceiverKey(Expr\PropertyFetch $receiver): ?string
    {
        // User view: choose the findings list branch for this case.
        if (!$receiver->name instanceof Node\Identifier) {
            return null;
        }

        $owner = $this->receiverExpressionKey($receiver->var);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($owner === null) {
            return null;
        }

        return $owner . '->' . strtolower($receiver->name->toString());
    }

    /**
     * Choose the receiver with the widest distinct call surface.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  array<string, array<string, string>> $callsByReceiver - Distinct call-name sets keyed by receiver identity.
     *
     * @return array<string, string> - The single widest call set; empty when no receiver was recorded.
     */
    private function largestReceiverCallSet(array $callsByReceiver): array
    {
        $largest = [];

        // User view: add each item that can appear in findings list.
        foreach ($callsByReceiver as $calls) {
            // User view: choose the findings list branch for this case.
            if (count($calls) > count($largest)) {
                $largest = $calls;
            }
        }

        return $largest;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  Expr $receiver - Receiver expression unwound through method-call chains to its root variable.
     *
     * @return string|null - Receiver variable name, or null for dynamic/non-variable receivers.
     */
    private function receiverName(Expr $receiver): ?string
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        // User view: choose the findings list branch for this case.
        if (!$receiver instanceof Expr\Variable || !is_string($receiver->name)) {
            return null;
        }

        return strtolower($receiver->name);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  string $name - Method name to classify against the get/has/is prefixes and the bare `count` reader.
     *
     * @return bool - True when the method name follows a result-observation convention.
     */
    private function isObservationMethodName(string $name): bool
    {
        // User view: add each item that can appear in findings list.
        foreach (self::OBSERVATION_METHOD_PREFIXES as $prefix) {
            // User view: choose the findings list branch for this case.
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return $name === 'count';
    }

    /**
     * Collect variables that receive assertion result values.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  TestQualityScope $scope - Test method whose assignments are scanned for call-result variables.
     *
     * @return array<string, true> - Set of local variable names (keyed by name) that hold a call result.
     */
    private function collectResultVariables(TestQualityScope $scope): array
    {
        $variables = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]) as $assign) {
            // User view: choose the findings list branch for this case.
            if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($this->isCallChainExpression($assign->expr)) {
                $variables[$assign->var->name] = true;
            }
        }

        return $variables;
    }

    /**
     * Detect call-chain expressions whose assigned variables represent result values.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param  Expr $expr - Right-hand side of an assignment; a call expression marks its target as a result variable.
     *
     * @return bool - True when the expression is a call result.
     */
    private function isCallChainExpression(Expr $expr): bool
    {
        // Method/static/function call results are "result variables" whose subsequent method
        // calls are getters on the result, not fresh SUT calls. `new X()` is intentionally
        // excluded - constructor outputs are usually the SUT itself, and calls on them are
        // genuine SUT exercise.
        return $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall
            || $expr instanceof Expr\FuncCall;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
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
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Expr\Variable
            && is_string($receiver->name)
            && isset($resultVariables[$receiver->name]);
    }
}
