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
 * Flags a test that stands up more mock objects than the configured cap - a signal the test is pinned to
 * its collaborators' choreography rather than the behaviour under test, and will break on any refactor.
 * Runs over every test in the file; the cap is tunable. Advisory, medium confidence.
 */
final readonly class ExcessiveMockingRule implements RuleInterface
{
    /**
     * Stable rule identifier for excessive mocking findings.
     */
    public const ID = 'test-quality.excessive-mocking';

    /**
     * Describes the excessive-mocking rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: mock count is a heuristic for over-specification, so this is advisory and tunable.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Excessive mocking',
            pillar:            Pillar::TestQuality,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Medium,
            defaultThresholds: ['maxMocks' => 3],
            falsePositiveShapes: [
                [
                    'shape'      => 'A test for a coordinator whose real job is to orchestrate several collaborators, so the mock count reflects the production design.',
                    'mitigation' => 'Doubles are counted without weighing the unit\'s responsibility, so raise this rule\'s maxMocks threshold for suites where coordinators are the subject.',
                ],
            ],
        );
    }

    /**
     * Reports tests that create more mocks than the configured threshold.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for heavily mocked tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $maxMocks   = (int) $ruleContext->settingsFor($definition)->numericThreshold('maxMocks');
        $findings   = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $mockCount = 0;
            // Count how many mocks the test stands up.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                // Only mock-creation calls add to the tally.
                if (TestQualityNodeHelper::isMockCreationCall($call)) {
                    $mockCount++;
                }
            }

            // Stay quiet while the mock count is within the configured cap.
            if ($mockCount <= $maxMocks) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s creates %d mocks; more than %d usually signals over-specified collaborators.', $scope->symbol, $mockCount, $maxMocks),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                symbol:      $scope->symbol,
                remediation: 'Prefer fewer collaborators, a higher-level test, or a purpose-built fake.',
                metadata:    ['mockCount' => $mockCount, 'maxMocks' => $maxMocks],
            );
        }

        return $findings;
    }
}
