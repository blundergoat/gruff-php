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

/**
 * Detects snapshot assertions that only lock down trivial values.
 */
final readonly class TrivialSnapshotRule implements RuleInterface
{
    /**
     * Stable rule identifier for trivial snapshot findings.
     */
    public const ID = 'test-quality.trivial-snapshot';

    /**
     * Describe the trivial snapshot rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory by default: snapshotting a tiny literal is a style smell, and the length cap is tunable per project.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Trivial snapshot',
            pillar:            Pillar::TestQuality,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Medium,
            defaultThresholds: ['maxLiteralLength' => 5],
        );
    }

    /**
     * Find snapshot assertions that lack supporting behavioral assertions.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for trivial snapshot tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $maxLength  = (int) $ruleContext->settingsFor($definition)->numericThreshold('maxLiteralLength');
        $findings   = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // User view: add each item that can appear in findings list.
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                $name = TestQualityNodeHelper::callName($call);
                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
                if ($name === null || !str_contains($name, 'snapshot')) {
                    continue;
                }

                $literal = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($call));
                // User view: choose the findings list branch for this case.
                if (!is_string($literal) && $call instanceof \PhpParser\Node\Expr\MethodCall) {
                    $literal = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::pestExpectationValue($call));
                }

                // User view: choose the findings list branch for this case.
                if (!is_string($literal) || strlen($literal) > $maxLength) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s snapshots a tiny literal; a direct assertion is clearer.', $scope->symbol),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $call->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Medium,
                    symbol:      $scope->symbol,
                    remediation: 'Use snapshots for rich structured output, not short scalar values.',
                    metadata:    ['length' => strlen($literal)],
                );
            }
        }

        return $findings;
    }
}
