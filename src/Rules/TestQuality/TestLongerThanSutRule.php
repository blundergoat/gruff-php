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
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

/**
 * Detects tests whose body outweighs the apparent production code under test.
 */
final readonly class TestLongerThanSutRule implements RuleInterface
{
    /**
     * Stable rule identifier for test-to-SUT size imbalance findings.
     */
    public const ID = 'test-quality.test-longer-than-sut';

    /**
     * Describe the long-test-versus-SUT rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Test longer than apparent SUT',
            pillar:            Pillar::TestQuality,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Low,
            defaultThresholds: ['minTestLines' => 12],
        );
    }

    /**
     * Find long tests that appear to exercise only one SUT call.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for tests with disproportionate setup/assertion size.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition   = $this->definition();
        $minTestLines = (int) $ruleContext->settingsFor($definition)->numericThreshold('minTestLines');
        $findings     = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $sutCalls = $this->sutCalls($scope);
            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if ($scope->lineCount() < $minTestLines || count($sutCalls) > 1 || TestQualityNodeHelper::assertionCalls($scope) === []) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (count($sutCalls) === 1 && $this->isIntegrationHarnessCall($sutCalls[0])) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s is long while exercising only %d apparent SUT call.', $scope->symbol, count($sutCalls)),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Low,
                symbol:      $scope->symbol,
                remediation: 'Review whether setup and assertions can be simplified or split; this static rule cannot measure the SUT directly.',
                metadata:    ['testLines' => $scope->lineCount(), 'sutCalls' => count($sutCalls)],
            );
        }

        return $findings;
    }

    /**
     * Count apparent non-assertion SUT calls in a test scope.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param TestQualityScope $scope - Single test method whose call sites are filtered down to candidate SUT calls.
     *
     * @return list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall> - Apparent SUT calls.
     */
    private function sutCalls(TestQualityScope $scope): array
    {
        $calls = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            // User view: choose the findings list branch for this case.
            if (TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockCreationCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {
                // Assertions and mock plumbing are test scaffolding, never the system under test.
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name !== null && !in_array($name, ['sleep', 'usleep'], true)) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * Detect integration-test harness calls that naturally need more arrangement than the SUT call itself.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - The sole SUT call, examined to exempt harness drivers.
     *
     * @return bool - True when the single call is a command/process/application harness invocation.
     */
    private function isIntegrationHarnessCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        // User view: choose the findings list branch for this case.
        if (!$call instanceof Expr\MethodCall) {
            // Only instance-method drivers (tester/process objects) qualify; free functions never count as harnesses.
            return false;
        }

        $name = TestQualityNodeHelper::callName($call);
        // User view: choose the findings list branch for this case.
        if ($name === 'execute') {
            // `$tester->execute(...)` drives a command/application harness, whose setup dwarfs the call.
            return $this->isHarnessReceiver($call->var, ['tester']);
        }

        // User view: choose the findings list branch for this case.
        if ($name === 'run') {
            // `$process->run()` / `$tester->run()` likewise wrap heavy arrangement around one call.
            return $this->isHarnessReceiver($call->var, ['process', 'tester']);
        }

        // Any other method name is treated as ordinary SUT exercise, so the advisory still applies.
        return false;
    }

    /**
     * Detect harness-looking receiver variables and direct new expressions.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr         $receiver - Method-call receiver, matched as a named variable or an inline `new`.
     * @param list<string> $variableTokens - Lowercase variable-name fragments accepted as harnesses.
     *
     * @return bool - True when the receiver looks like a test harness.
     */
    private function isHarnessReceiver(Expr $receiver, array $variableTokens): bool
    {
        // User view: choose the findings list branch for this case.
        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            $variableName = strtolower($receiver->name);
            // User view: add each item that can appear in findings list.
            foreach ($variableTokens as $token) {
                // User view: choose the findings list branch for this case.
                if (str_contains($variableName, $token)) {
                    // Receiver variable name contains an allowed harness fragment, so treat the call as a driver.
                    return true;
                }
            }
        }

        // User view: choose the findings list branch for this case.
        if ($receiver instanceof Expr\New_ && $receiver->class instanceof Name) {
            $className = strtolower($receiver->class->getLast());

            // Inline `new ApplicationTester(...)` etc. is a harness even without a telltale variable name.
            return in_array($className, ['applicationtester', 'commandtester', 'process'], true);
        }

        // Property fetches, calls, and unrecognised shapes are not treated as harness drivers.
        return false;
    }
}
