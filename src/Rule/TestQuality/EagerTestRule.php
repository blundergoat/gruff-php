<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
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
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for eager tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition    = $this->definition();
        $minAssertions = (int) $ruleContext->settingsFor($definition)->numericThreshold('minAssertions');
        $findings      = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $assertionCount = count(TestQualityNodeHelper::assertionCalls($scope));
            $sutCalls       = $this->distinctSutCalls($scope);

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
     * @return array<string, string>
     */
    private function distinctSutCalls(TestQualityScope $scope): array
    {
        $resultVariables = $this->collectResultVariables($scope);
        $callsByReceiver = [];

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            $name = TestQualityNodeHelper::callName($call);
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

            if ($call instanceof Expr\MethodCall && $this->isResultVariableReceiver($call->var, $resultVariables)) {
                continue;
            }

            $receiver = $this->receiverKey($call);
            if ($receiver === null) {
                continue;
            }

            $callsByReceiver[$receiver][$name] = $name;
        }

        return $this->largestReceiverCallSet($callsByReceiver);
    }

    /**
     * @return bool True when the call is only part of an assertion expression.
     */
    private function isNestedInAssertionCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        while ($parent instanceof Node) {
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
     * @return bool True when the call belongs to teardown rather than exercise.
     */
    private function isNestedInFinallyBlock(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof Node\Stmt\Finally_) {
                return true;
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * @return bool True when the call reads or shapes test output rather than exercising the SUT.
     */
    private function isObservationCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, string $name): bool
    {
        if (!$call instanceof Expr\MethodCall) {
            return false;
        }

        if ($this->isDirectThisCall($call)) {
            return true;
        }

        if (in_array($name, self::SETUP_METHODS, true)) {
            return true;
        }

        $receiver = $this->receiverName($call->var);

        if ($receiver === 'process' && in_array($name, self::PROCESS_HARNESS_METHODS, true)) {
            return true;
        }

        return $receiver !== null
            && in_array($receiver, self::OBSERVATION_RECEIVERS, true)
            && $this->isObservationMethodName($name);
    }

    /**
     * @return bool True when the call is a direct test-case helper call.
     */
    private function isDirectThisCall(Expr\MethodCall $call): bool
    {
        return $call->var instanceof Expr\Variable
            && $call->var->name === 'this';
    }

    /**
     * @return string|null Stable receiver identity for SUT call grouping.
     */
    private function receiverKey(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        if ($call instanceof Expr\FuncCall) {
            return null;
        }

        if ($call instanceof Expr\StaticCall) {
            if (!$call->class instanceof Node\Name) {
                return null;
            }

            $class = strtolower($call->class->toString());
            if (in_array($class, ['parent', 'self', 'static'], true)) {
                return null;
            }

            return 'static:' . $class;
        }

        return $this->receiverExpressionKey($call->var);
    }

    /**
     * @return string|null Receiver identity for method-call grouping.
     */
    private function receiverExpressionKey(Expr $receiver): ?string
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            return 'var:' . strtolower($receiver->name);
        }

        if ($receiver instanceof Expr\New_ && $receiver->class instanceof Node\Name) {
            return 'new:' . strtolower($receiver->class->toString());
        }

        if ($receiver instanceof Expr\PropertyFetch) {
            return $this->propertyReceiverKey($receiver);
        }

        return null;
    }

    /**
     * @return string|null Receiver identity for property-held SUTs.
     */
    private function propertyReceiverKey(Expr\PropertyFetch $receiver): ?string
    {
        if (!$receiver->name instanceof Node\Identifier) {
            return null;
        }

        $owner = $this->receiverExpressionKey($receiver->var);
        if ($owner === null) {
            return null;
        }

        return $owner . '->' . strtolower($receiver->name->toString());
    }

    /**
     * Choose the receiver with the widest distinct call surface.
     *
     * @param array<string, array<string, string>> $callsByReceiver
     * @return array<string, string>
     */
    private function largestReceiverCallSet(array $callsByReceiver): array
    {
        $largest = [];

        foreach ($callsByReceiver as $calls) {
            if (count($calls) > count($largest)) {
                $largest = $calls;
            }
        }

        return $largest;
    }

    /**
     * @return string|null Receiver variable name, or null for dynamic/non-variable receivers.
     */
    private function receiverName(Expr $receiver): ?string
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        if (!$receiver instanceof Expr\Variable || !is_string($receiver->name)) {
            return null;
        }

        return strtolower($receiver->name);
    }

    /**
     * @return bool True when the method name follows a result-observation convention.
     */
    private function isObservationMethodName(string $name): bool
    {
        foreach (self::OBSERVATION_METHOD_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return $name === 'count';
    }

    /**
     * Collect variables that receive assertion result values.
     *
     * @return array<string, true>
     */
    private function collectResultVariables(TestQualityScope $scope): array
    {
        $variables = [];

        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]) as $assign) {
            if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }

            if ($this->isCallChainExpression($assign->expr)) {
                $variables[$assign->var->name] = true;
            }
        }

        return $variables;
    }

    /**
     * Detect call-chain expressions whose assigned variables represent result values.
     *
     * @return bool True when the expression is a call result.
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
     * @param array<string, true> $resultVariables
     *
     * @return bool True when the receiver roots at a known result variable.
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
