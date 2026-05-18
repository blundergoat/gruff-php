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
 * Detects tests that repeat arrange-act-assert cycles in one method.
 */
final readonly class MultipleAaaCyclesRule implements RuleInterface
{
    /**
     * Stable rule identifier for repeated AAA cycle findings.
     */
    public const ID = 'test-quality.multiple-aaa-cycles';

    /**
     * Describe the multiple arrange-act-assert cycles rule.
     *
     * @return RuleDefinition Rule metadata, defaults, and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Multiple arrange-act-assert cycles',
            pillar:            Pillar::TestQuality,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Low,
            defaultThresholds: ['minCycles' => 2],
            isEnabledByDefault: false,
        );
    }

    /**
     * Find tests that appear to repeat act/assert cycles in one method.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for repeated AAA cycles.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $threshold = (int) $context->settingsFor($this->definition())->numericThreshold('minCycles');
        $findings  = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $cycles = $this->countActAssertCycles($scope);

            if ($cycles < $threshold) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s contains %d act-then-assert cycles; consider splitting into focused tests.',
                    $scope->symbol,
                    $cycles,
                ),
                filePath:    $unit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Low,
                symbol:      $scope->symbol,
                remediation: 'Each test should arrange once, act once, and assert once. Split this method into separate tests for each scenario.',
                metadata:    ['cycles' => $cycles, 'threshold' => $threshold],
            );
        }

        return $findings;
    }

    /**
     * Count apparent act-then-assert cycles across top-level test statements.
     *
     * @return int Number of detected cycles.
     */
    private function countActAssertCycles(TestQualityScope $scope): int
    {
        $cycles              = 0;
        $sawNonAssertionCall = false;
        $finder              = new NodeFinder();

        foreach ($scope->statements as $stmt) {
            $hasAssertion        = false;
            $hasNonAssertionCall = false;

            foreach ($finder->find([$stmt], static fn (Node $node): bool => $node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) as $call) {
                if (!$call instanceof Expr\FuncCall && !$call instanceof Expr\MethodCall && !$call instanceof Expr\StaticCall) {
                    continue;
                }

                if (TestQualityNodeHelper::isAssertionCall($call)) {
                    $hasAssertion = true;
                } elseif (!TestQualityNodeHelper::isMockCreationCall($call) && !TestQualityNodeHelper::isMockVerificationCall($call)) {
                    $hasNonAssertionCall = true;
                }
            }

            if ($hasAssertion) {
                if ($sawNonAssertionCall || $hasNonAssertionCall) {
                    $cycles++;
                }

                $sawNonAssertionCall = false;
                continue;
            }

            $sawNonAssertionCall = $hasNonAssertionCall;
        }

        return $cycles;
    }
}
