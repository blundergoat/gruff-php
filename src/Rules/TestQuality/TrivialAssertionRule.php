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
 * Detects assertions whose expected and actual values make the check tautological.
 */
final readonly class TrivialAssertionRule implements RuleInterface
{
    /**
     * Stable rule identifier for trivial assertion findings.
     */
    public const ID = 'test-quality.trivial-assertion';

    /**
     * Describe the trivial assertion rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning by default: an assertion that passes by construction gives false confidence, so flag it loudly.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Trivial assertion',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find assertions that can pass without checking meaningful behavior.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for trivial assertions.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                if (!TestQualityNodeHelper::isTrivialAssertion($call)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s contains an assertion that passes by construction.', $scope->symbol),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $call->getStartLine(),
                    severity:    Severity::Warning,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::High,
                    symbol:      $scope->symbol,
                    remediation: 'Assert behavior from the system under test instead of comparing known literals.',
                );
            }
        }

        return $findings;
    }
}
