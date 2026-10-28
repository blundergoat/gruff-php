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
 * Flags a snapshot assertion (`toMatchSnapshot`, `assertMatchesSnapshot`) whose captured value is a short
 * scalar - the kind of value a direct `assertSame` would check more clearly, with none of a snapshot's
 * benefit. Runs over every test's assertions; the length cap is tunable. Advisory, medium confidence.
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
     * Reports snapshot assertions that only capture a tiny literal value.
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

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // Inspect each assertion the test makes.
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                $name = TestQualityNodeHelper::callName($call);
                // Only snapshot-style assertions are in scope here.
                if ($name === null || !str_contains($name, 'snapshot')) {
                    continue;
                }

                $literal = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($call));
                // A Pest expectation carries its value on the expect() receiver, so read that too.
                if (!is_string($literal) && $call instanceof \PhpParser\Node\Expr\MethodCall) {
                    $literal = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::pestExpectationValue($call));
                }

                // Leave real snapshots alone; only a short scalar literal is the smell.
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
