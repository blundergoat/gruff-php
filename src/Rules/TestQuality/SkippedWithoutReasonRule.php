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
 * Flags a `markTestSkipped()`/`markTestIncomplete()` call with no message - a test that silently drops out
 * of the run, so a reviewer never learns why it stopped guarding its behaviour. Runs over every test in the
 * file. Warning, high confidence: the missing reason is unambiguous.
 */
final readonly class SkippedWithoutReasonRule implements RuleInterface
{
    /**
     * Stable rule identifier for unexplained skipped test findings.
     */
    public const ID = 'test-quality.skipped-without-reason';

    /**
     * Describes the skipped-test-without-reason rule for the registry and reports.
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
     * Reports skipped or incomplete tests without an explanatory reason.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unexplained skipped tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // Inspect each call the test makes.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                // Only a markTestSkipped() call can skip the test here.
                if (TestQualityNodeHelper::callName($call) !== 'marktestskipped') {
                    continue;
                }

                $reason = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($call));
                // A non-empty string reason explains the skip, so leave it alone.
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
