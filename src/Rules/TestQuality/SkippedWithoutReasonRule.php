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
 * Detects skipped tests that do not include an explicit reason.
 */
final readonly class SkippedWithoutReasonRule implements RuleInterface
{
    /**
     * Stable rule identifier for unexplained skipped test findings.
     */
    public const ID = 'test-quality.skipped-without-reason';

    /**
     * Describe the skipped test without reason rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning at high confidence: a missing skip reason is unambiguous, and a silent skip erodes the suite.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Skipped test without reason',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find skipped or incomplete tests without an explanatory reason.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unexplained skipped tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // User view: add each item that can appear in findings list.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                // User view: choose the findings list branch for this case.
                if (TestQualityNodeHelper::callName($call) !== 'marktestskipped') {
                    continue;
                }

                $reason = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($call));
                // User view: choose the findings list branch for this case.
                // User view: an empty value becomes a clear findings list fallback.
                if (is_string($reason) && trim($reason) !== '') {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s skips without an explanatory reason.', $scope->symbol),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $call->getStartLine(),
                    severity:    Severity::Warning,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::High,
                    symbol:      $scope->symbol,
                    remediation: 'Include the condition or ticket that explains why this test is skipped.',
                );
            }
        }

        return $findings;
    }
}
