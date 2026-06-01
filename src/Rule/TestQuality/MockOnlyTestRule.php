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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for mock-only tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $hasMock         = false;
            $hasVerification = false;

            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                $hasMock         = $hasMock || TestQualityNodeHelper::isMockCreationCall($call);
                $hasVerification = $hasVerification || TestQualityNodeHelper::isMockVerificationCall($call);
            }

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
