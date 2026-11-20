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
 * Flags snapshot assertions of short string literals, where a direct assertion may be clearer.
 * Analyses assertions in each discovered test scope with a configurable byte-length limit.
 * Findings are advisory and have medium confidence.
 */
final readonly class TrivialSnapshotRule implements RuleInterface
{
    /**
     * Stable rule identifier for trivial snapshot findings.
     */
    public const ID = 'test-quality.trivial-snapshot';

    /**
     * Describes the trivial-snapshot rule for the registry and reports.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A short snapshot kept deliberately so a rendered value stays reviewable in the snapshot file alongside its larger siblings.',
                    'mitigation' => 'Only the captured literal\'s length is judged, not why it is snapshotted, so raise this rule\'s maxLiteralLength threshold.',
                ],
            ],
        );
    }

    /**
     * Reports supported snapshot-call shapes whose captured value is a short string literal.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose discovered assertion-call shapes are checked.
     * @param RuleContext $ruleContext - Supplies the maxLiteralLength threshold for this analysis pass.
     *
     * @return list<Finding> - One advisory finding per matching call shape; empty when none qualify.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $maxLength  = (int) $ruleContext->settingsFor($definition)->numericThreshold('maxLiteralLength');
        $findings   = [];


        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {

            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                $name = TestQualityNodeHelper::callName($call);

                if ($name === null || !str_contains($name, 'snapshot')) {
                    continue;
                }

                $literal = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($call));
                // A Pest expectation carries its value on the expect() receiver, so read that too.
                if (!is_string($literal) && $call instanceof \PhpParser\Node\Expr\MethodCall) {
                    $literal = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::pestExpectationValue($call));
                }


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
