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
 * Flags a test that only sets up and verifies mocks - it checks that collaborators were called but never
 * asserts a real, externally visible effect of the system under test, so it passes even when the behaviour
 * is wrong. Runs over every test in the file. Warning, medium confidence.
 */
final readonly class MockOnlyTestRule implements RuleInterface
{
    /**
     * Stable rule identifier for mock-only test findings.
     */
    public const ID = 'test-quality.mock-only-test';

    /**
     * Describes the mock-only-test rule for the registry and reports.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A collaboration test whose contract genuinely is the interaction, such as proving an event was dispatched or a port was called with a mapped payload.',
                    'mitigation' => 'The rule cannot tell an interaction contract from missing behaviour coverage, so add one assertion on a real returned value, or accept the finding.',
                ],
            ],
        );
    }

    /**
     * Reports tests that verify mock interactions without a real assertion.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for mock-only tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $hasMock         = false;
            $hasVerification = false;

            // Tally the mock creation and verification calls the test makes.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                $hasMock         = $hasMock || TestQualityNodeHelper::isMockCreationCall($call);
                $hasVerification = $hasVerification || TestQualityNodeHelper::isMockVerificationCall($call);
            }

            // Flag only tests that verify mocks yet make no real assertion at all.
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
