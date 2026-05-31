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

        // Hand back one finding per eager test scope; empty when nothing crossed both thresholds.
        return $findings;
    }

    /**
     * Collect distinct method calls made on likely system-under-test receivers.
     *
     * @param  TestQualityScope $scope Single test method whose body is searched for SUT exercise calls.
     * @return array<string, string>   Distinct method names keyed by name, taken from the busiest receiver only.
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

        // Count only the busiest receiver, so helpers spread across many objects do not read as one eager SUT.
        return $this->largestReceiverCallSet($callsByReceiver);
    }

    /**
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Candidate SUT call whose ancestors are walked for an enclosing assertion.
     * @return bool True when the call is only part of an assertion expression.
     */
    private function isNestedInAssertionCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        while ($parent instanceof Node) {
            if (($parent instanceof Expr\FuncCall || $parent instanceof Expr\MethodCall || $parent instanceof Expr\StaticCall)
                && TestQualityNodeHelper::isAssertionCall($parent)
            ) {
                // An assertion ancestor means this call is the asserted value, not a separate SUT exercise.
                return true;
            }

            $parent = $parent->getAttribute('parent');
        }

        // Reached the root with no assertion ancestor, so the call stands on its own.
        return false;
    }

    /**
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Candidate call whose ancestors are walked for an enclosing finally block.
     * @return bool True when the call belongs to teardown rather than exercise.
     */
    private function isNestedInFinallyBlock(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof Node\Stmt\Finally_) {
                // A finally ancestor means this call is teardown, so it must not count as SUT exercise.
                return true;
            }

            $parent = $parent->getAttribute('parent');
        }

        // No finally ancestor, so the call is part of the test body proper.
        return false;
    }

    /**
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Candidate call under inspection; only method calls can be observations.
     * @param  string $name Called method name resolved by the caller; compared against the lower-case observation conventions.
     * @return bool True when the call reads or shapes test output rather than exercising the SUT.
     */
    private function isObservationCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, string $name): bool
    {
        if (!$call instanceof Expr\MethodCall) {
            // Only instance-method calls read a result; free functions and static calls are never observations.
            return false;
        }

        if ($this->isDirectThisCall($call)) {
            // `$this->...` is a test-case helper, not the system under test.
            return true;
        }

        if (in_array($name, self::SETUP_METHODS, true)) {
            // Setup verbs such as setTimeout configure the fixture rather than exercise behaviour.
            return true;
        }

        $receiver = $this->receiverName($call->var);

        if ($receiver === 'process' && in_array($name, self::PROCESS_HARNESS_METHODS, true)) {
            // Process-harness verbs on $process drive the runner, not the code being tested.
            return true;
        }

        // Otherwise it is an observation only when a known result receiver is read via a getter-style name.
        return $receiver !== null
            && in_array($receiver, self::OBSERVATION_RECEIVERS, true)
            && $this->isObservationMethodName($name);
    }

    /**
     * @param  Expr\MethodCall $call Method call whose receiver is checked for being the bare `$this` test case.
     * @return bool True when the call is a direct test-case helper call.
     */
    private function isDirectThisCall(Expr\MethodCall $call): bool
    {
        // True only for the literal `$this` receiver; chained or property receivers are handled elsewhere.
        return $call->var instanceof Expr\Variable
            && $call->var->name === 'this';
    }

    /**
     * @param  Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Call to key by receiver; free functions and self/parent/static yield null.
     * @return string|null Stable receiver identity for SUT call grouping.
     */
    private function receiverKey(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        if ($call instanceof Expr\FuncCall) {
            // A free function has no receiver object, so it cannot anchor a SUT call group.
            return null;
        }

        if ($call instanceof Expr\StaticCall) {
            if (!$call->class instanceof Node\Name) {
                // A dynamic class expression has no stable name to key by.
                return null;
            }

            $class = strtolower($call->class->toString());
            if (in_array($class, ['parent', 'self', 'static'], true)) {
                // Relative class keywords point back at the test itself, not an external SUT.
                return null;
            }

            // Group static calls by their concrete class so calls on the same class cluster together.
            return 'static:' . $class;
        }

        // Instance calls are keyed by their receiver expression, unwound to its root.
        return $this->receiverExpressionKey($call->var);
    }

    /**
     * @param  Expr $receiver Receiver expression to key; method-call chains are unwound to their root before keying.
     * @return string|null Receiver identity for method-call grouping.
     */
    private function receiverExpressionKey(Expr $receiver): ?string
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            // A plain `$var` receiver is keyed by its lower-cased name so case variants group together.
            return 'var:' . strtolower($receiver->name);
        }

        if ($receiver instanceof Expr\New_ && $receiver->class instanceof Node\Name) {
            // An inline `new X()` receiver is keyed by class, treating each construction site as one SUT.
            return 'new:' . strtolower($receiver->class->toString());
        }

        if ($receiver instanceof Expr\PropertyFetch) {
            // Property-held SUTs (such as `$this->sut`) delegate to the owner-plus-property keying.
            return $this->propertyReceiverKey($receiver);
        }

        // Anything else (dynamic var, array access, call) has no stable identity, so it does not group.
        return null;
    }

    /**
     * @param  Expr\PropertyFetch $receiver Property access (such as `$this->sut`) keyed as owner plus property name; dynamic names yield null.
     * @return string|null Receiver identity for property-held SUTs.
     */
    private function propertyReceiverKey(Expr\PropertyFetch $receiver): ?string
    {
        if (!$receiver->name instanceof Node\Identifier) {
            // A dynamic property name (`$this->$prop`) has no fixed identity to key by.
            return null;
        }

        $owner = $this->receiverExpressionKey($receiver->var);
        if ($owner === null) {
            // Without a keyable owner the property access cannot be grouped either.
            return null;
        }

        // Combine owner and property so `$this->sut` and `$this->other` stay distinct receivers.
        return $owner . '->' . strtolower($receiver->name->toString());
    }

    /**
     * Choose the receiver with the widest distinct call surface.
     *
     * @param  array<string, array<string, string>> $callsByReceiver Distinct call-name sets keyed by receiver identity.
     * @return array<string, string> The single widest call set; empty when no receiver was recorded.
     */
    private function largestReceiverCallSet(array $callsByReceiver): array
    {
        $largest = [];

        foreach ($callsByReceiver as $calls) {
            if (count($calls) > count($largest)) {
                $largest = $calls;
            }
        }

        // The widest set drives the SUT-call count; one busy receiver matters more than many sparse ones.
        return $largest;
    }

    /**
     * @param  Expr $receiver Receiver expression unwound through method-call chains to its root variable for name matching.
     * @return string|null Receiver variable name, or null for dynamic/non-variable receivers.
     */
    private function receiverName(Expr $receiver): ?string
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        if (!$receiver instanceof Expr\Variable || !is_string($receiver->name)) {
            // Only a plain variable root yields a name; dynamic or non-variable roots are unnamed here.
            return null;
        }

        // Lower-case so receiver-name matching against the observation lists is case-insensitive.
        return strtolower($receiver->name);
    }

    /**
     * @param  string $name Method name to classify; matched against the get/has/is prefixes and the bare `count` reader.
     * @return bool True when the method name follows a result-observation convention.
     */
    private function isObservationMethodName(string $name): bool
    {
        foreach (self::OBSERVATION_METHOD_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                // A get/has/is prefix marks a reader, so the call inspects rather than mutates the SUT.
                return true;
            }
        }

        // `count` is the one non-prefixed reader treated as an observation.
        return $name === 'count';
    }

    /**
     * Collect variables that receive assertion result values.
     *
     * @param  TestQualityScope $scope Test method whose assignments are scanned for call-result variables.
     * @return array<string, true> Set of local variable names (keyed by name) that hold a call result.
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

        // These names mark result holders, so later getter calls on them are not counted as SUT exercise.
        return $variables;
    }

    /**
     * Detect call-chain expressions whose assigned variables represent result values.
     *
     * @param  Expr $expr Right-hand side of an assignment; a call expression marks its target as a result variable.
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
     * @param  Expr                $receiver        Receiver expression unwound to its root variable before the membership test.
     * @param  array<string, true> $resultVariables Result-variable name set from collectResultVariables(), keyed by variable name.
     *
     * @return bool True when the receiver roots at a known result variable.
     */
    private function isResultVariableReceiver(Expr $receiver, array $resultVariables): bool
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        // True when the root variable is a recorded result holder, so the call is a getter not SUT exercise.
        return $receiver instanceof Expr\Variable
            && is_string($receiver->name)
            && isset($resultVariables[$receiver->name]);
    }
}
