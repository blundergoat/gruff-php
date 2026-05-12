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
 * Detects tests that cover multiple behaviors in one method.
 */
final readonly class EagerTestRule implements RuleInterface
{
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
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for eager tests.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition    = $this->definition();
        $minAssertions = (int) $context->settingsFor($definition)->numericThreshold('minAssertions');
        $findings      = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $assertionCount = count(TestQualityNodeHelper::assertionCalls($scope));
            $sutCalls       = $this->distinctSutCalls($scope);

            if ($assertionCount < $minAssertions || count($sutCalls) < 2) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s asserts %d times across multiple apparent SUT calls.', $scope->symbol, $assertionCount),
                filePath:    $unit->file->displayPath,
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
     * @return array<string, string>
     */
    private function distinctSutCalls(TestQualityScope $scope): array
    {
        $resultVariables = $this->collectResultVariables($scope);
        $calls           = [];

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            $name = TestQualityNodeHelper::callName($call);
            if ($name === null
                || TestQualityNodeHelper::isAssertionCall($call)
                || TestQualityNodeHelper::isMockCreationCall($call)
                || TestQualityNodeHelper::isMockVerificationCall($call)
            ) {
                continue;
            }

            if ($call instanceof Expr\MethodCall && $this->receiverIsResultVariable($call->var, $resultVariables)) {
                continue;
            }

            $calls[$name] = $name;
        }

        return $calls;
    }

    /**
     * @return array<string, true>
     */
    private function collectResultVariables(TestQualityScope $scope): array
    {
        $finder    = new NodeFinder();
        $variables = [];

        foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\Assign) as $assign) {
            if (!$assign instanceof Expr\Assign) {
                continue;
            }

            if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }

            if ($this->expressionIsCallChain($assign->expr)) {
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
    private function expressionIsCallChain(Expr $expr): bool
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
    private function receiverIsResultVariable(Expr $receiver, array $resultVariables): bool
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Expr\Variable
            && is_string($receiver->name)
            && isset($resultVariables[$receiver->name]);
    }
}
