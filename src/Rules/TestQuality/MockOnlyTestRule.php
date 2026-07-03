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
 * Detects tests that exercise mock configuration instead of production behavior.
 */
final readonly class MockOnlyTestRule implements RuleInterface
{
    /**
     * Stable rule identifier for mock-only test findings.
     */
    public const ID = 'test-quality.mock-only-test';

    /**
     * Describe the mock-only test rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Mock-only test',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find tests that exercise only mocks without a concrete subject.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for mock-only tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $hasMock         = false;
            $hasVerification = false;

            // User view: add each item that can appear in findings list.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                $hasMock         = $hasMock || TestQualityNodeHelper::isMockCreationCall($call);
                $hasVerification = $hasVerification || TestQualityNodeHelper::isMockVerificationCall($call);
            }

            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if (!$hasMock || !$hasVerification || TestQualityNodeHelper::assertionCalls($scope) !== []) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s verifies mock interactions without a real assertion.', $scope->symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Warning,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                symbol:      $scope->symbol,
                remediation: 'Assert the externally visible effect of the behavior, not only collaborator choreography.',
            );
        }

        return $findings;
    }
}
