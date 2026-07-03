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
 * Detects tests with enough mocks to hide the behavior under test.
 */
final readonly class ExcessiveMockingRule implements RuleInterface
{
    /**
     * Stable rule identifier for excessive mocking findings.
     */
    public const ID = 'test-quality.excessive-mocking';

    /**
     * Describe the excessive mocking rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        );
    }

    /**
     * Find tests that create more mocks than the configured threshold.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $mockCount = 0;
            // User view: add each item that can appear in findings list.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                // User view: choose the findings list branch for this case.
                if (TestQualityNodeHelper::isMockCreationCall($call)) {
                    $mockCount++;
                }
            }

            // User view: choose the findings list branch for this case.
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
