<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use GruffPhp\Rules\Size\SubstantiveLineCounter;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

/**
 * Flags a long test that appears to exercise only a single call into the system under test - a hint that
 * its bulk is setup and assertions rather than behaviour, so it may be doing too much or testing too little.
 * A static heuristic (it cannot measure the real SUT); integration harness drivers are exempt. Advisory, low confidence.
 */
final readonly class TestLongerThanSutRule implements RuleInterface
{
    /**
     * Stable rule identifier for test-to-SUT size imbalance findings.
     */
    public const ID = 'test-quality.test-longer-than-sut';

    /**
     * Describes the test-longer-than-SUT rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                  self::ID,
            name:                'Test longer than apparent SUT',
            pillar:              Pillar::TestQuality,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Advisory,
            confidence:          Confidence::Low,
            defaultThresholds:   ['minTestLines' => 12],
            isEnabledByDefault:  false,
            falsePositiveShapes: [
                [
                    'shape' => 'A long test around a single call whose length is real table-driven coverage, such as one call checked against many expected fields.',
                    'mitigation' => 'The real system under test is never measured, only the test\'s own length and call count, so raise this rule\'s minTestLines threshold.',
                ],
            ],
        );
    }

    /**
     * Reports long tests that appear to exercise at most one SUT call.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Supplies the minTestLines threshold.
     *
     * @return list<Finding> - Findings for long tests with at most one apparent SUT call.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition   = $this->definition();
        $minTestLines = (int) $ruleContext->settingsFor($definition)->numericThreshold('minTestLines');
        $findings     = [];


        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $sutCalls = $this->sutCalls($scope);
            // Code lines only (FAMILY-CONTRACT section 12): comments, attributes and blank lines are free.
            $testLines = max(1, SubstantiveLineCounter::countRange($analysisUnit, $scope->line, $scope->endLine ?? $scope->line));
            if ($testLines < $minTestLines || count($sutCalls) > 1 || TestQualityNodeHelper::assertionCalls($scope) === []) {
                continue;
            }

            // Exempt recognised integration-harness calls, whose setup can dominate the test.
            if (count($sutCalls) === 1 && $this->isIntegrationHarnessCall($sutCalls[0])) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s is long while exercising only %d apparent SUT call.', $scope->symbol, count($sutCalls)),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->anchorLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Low,
                symbol:      $scope->symbol,
                remediation: 'Review whether setup and assertions can be simplified or split; this static rule cannot measure the SUT directly.',
                metadata:    ['testLines' => $testLines, 'sutCalls' => count($sutCalls)],
            );
        }

        return $findings;
    }

    /**
     * Collects the apparent non-assertion SUT calls in a test scope.
     *
     * @param TestQualityScope $scope - PHPUnit or Pest test scope whose call sites are filtered to apparent SUT calls.
     *
     * @return list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall> - Apparent SUT calls.
     */
    private function sutCalls(TestQualityScope $scope): array
    {
        $calls = [];


        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockCreationCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {

                continue;
            }

            $name = TestQualityNodeHelper::callName($call);

            if ($name !== null && !in_array($name, ['sleep', 'usleep'], true)) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * Checks whether the sole apparent SUT call matches the integration-harness heuristic.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - The sole SUT call, examined to exempt harness drivers.
     *
     * @return bool - True for recognised execute/run receiver shapes; no receiver type is resolved.
     */
    private function isIntegrationHarnessCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        if (!$call instanceof Expr\MethodCall) {
            // Only instance-method drivers (tester/process objects) qualify; free functions never count as harnesses.
            return false;
        }

        $name = TestQualityNodeHelper::callName($call);
        if ($name === 'execute') {

            return $this->isHarnessReceiver($call->var, ['tester']);
        }

        if ($name === 'run') {

            return $this->isHarnessReceiver($call->var, ['process', 'tester']);
        }

        // Any other method name is treated as ordinary SUT exercise, so the advisory still applies.
        return false;
    }

    /**
     * Reports whether a receiver looks like a test harness (by variable name or inline new).
     *
     * @param Expr         $receiver       - Method-call receiver, matched as a named variable or an inline new expression.
     * @param list<string> $variableTokens - Lowercase variable-name fragments accepted as harnesses.
     *
     * @return bool - True when the receiver looks like a test harness.
     */
    private function isHarnessReceiver(Expr $receiver, array $variableTokens): bool
    {

        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            $variableName = strtolower($receiver->name);

            foreach ($variableTokens as $token) {
                if (str_contains($variableName, $token)) {

                    return true;
                }
            }
        }

        // Inline construction is matched by class short name, without namespace or type resolution.
        if ($receiver instanceof Expr\New_ && $receiver->class instanceof Name) {
            $className = strtolower($receiver->class->getLast());


            return in_array($className, ['applicationtester', 'commandtester', 'process'], true);
        }

        // Property fetches, calls, and unrecognised shapes are not treated as harness drivers.
        return false;
    }
}
