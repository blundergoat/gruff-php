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

final readonly class SutNotCalledRule implements RuleInterface
{
    public const ID = 'test-quality.sut-not-called';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Test name mentions SUT that is not called',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Low,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $candidate = $this->candidateSutName($scope->name);
            if ($scope->isPest || $candidate === null || TestQualityNodeHelper::assertionCalls($scope) === []) {
                continue;
            }

            if ($this->hasNamedSutCall($scope, $candidate)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('%s name implies a SUT behavior, but no matching method call was detected.', $scope->symbol),
                filePath: $unit->file->displayPath,
                line: $scope->line,
                severity: Severity::Advisory,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::Low,
                symbol: $scope->symbol,
                remediation: 'Check whether the test name still matches the behavior under test; this heuristic ignores custom dispatch and helpers.',
                metadata: ['candidate' => $candidate],
            );
        }

        return $findings;
    }

    private function hasNamedSutCall(TestQualityScope $scope, string $candidate): bool
    {
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockCreationCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            if ($name !== null && TestQualityNodeHelper::normalizedTestName($name) === $candidate) {
                return true;
            }
        }

        return false;
    }

    private function candidateSutName(string $testName): ?string
    {
        if (!str_starts_with($testName, 'test') || str_contains($testName, '_')) {
            return null;
        }

        $afterTest = substr($testName, 4);
        if ($afterTest === '') {
            return null;
        }

        if (preg_match('/(Returns|Throws|Builds|Creates|Processes|Renders|Sends|Handles|Calls|Can|Should|When|With|And)/', $afterTest, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $markerOffset = $match[0][1];
        if ($markerOffset <= 0) {
            return null;
        }

        $candidate = substr($afterTest, 0, $markerOffset);

        return strlen($candidate) >= 3 ? TestQualityNodeHelper::normalizedTestName($candidate) : null;
    }
}
