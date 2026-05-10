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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

final readonly class SutNotCalledRule implements RuleInterface
{
    public const ID = 'test-quality.sut-not-called';

    private const SUBPROCESS_FUNCTIONS = ['shell_exec', 'proc_open', 'popen', 'passthru', 'system', 'exec'];

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
            $candidates = $this->candidateSutNames($scope->name);
            if ($scope->isPest || $candidates === [] || TestQualityNodeHelper::assertionCalls($scope) === []) {
                continue;
            }

            if ($this->invokesSubprocess($scope)) {
                continue;
            }

            if ($this->hasNamedSutCall($scope, $candidates)) {
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
                metadata: ['candidates' => $candidates],
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $candidates
     */
    private function hasNamedSutCall(TestQualityScope $scope, array $candidates): bool
    {
        $candidateSet = array_fill_keys($candidates, true);

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockCreationCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            if ($name !== null && isset($candidateSet[TestQualityNodeHelper::normalizedTestName($name)])) {
                return true;
            }
        }

        return false;
    }

    private function invokesSubprocess(TestQualityScope $scope): bool
    {
        $finder = new NodeFinder();

        $hasProcessNew = $finder->find(
            $scope->statements,
            static function (Node $node): bool {
                if (!$node instanceof Expr\New_ || !$node->class instanceof Name) {
                    return false;
                }

                $short = strtolower($node->class->getLast());

                return $short === 'process' || $short === 'phpprocess';
            },
        ) !== [];

        if ($hasProcessNew) {
            return true;
        }

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if ($call instanceof Expr\FuncCall) {
                $name = TestQualityNodeHelper::functionName($call);
                if ($name !== null && in_array($name, self::SUBPROCESS_FUNCTIONS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function candidateSutNames(string $testName): array
    {
        if (!str_starts_with($testName, 'test') || str_contains($testName, '_')) {
            return [];
        }

        $afterTest = substr($testName, 4);
        if ($afterTest === '') {
            return [];
        }

        if (preg_match('/(Returns|Throws|Builds|Creates|Processes|Renders|Sends|Handles|Calls|Can|Should|When|With|And)/', $afterTest, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $markerOffset = $match[0][1];
        if ($markerOffset <= 0) {
            return [];
        }

        $beforeMarker = substr($afterTest, 0, $markerOffset);
        if (strlen($beforeMarker) < 3) {
            return [];
        }

        $candidates = [TestQualityNodeHelper::normalizedTestName($beforeMarker)];

        if (preg_match('/^([A-Z][a-z]+)/', $beforeMarker, $verbMatch) === 1) {
            $verb = $verbMatch[1];
            $candidates[] = TestQualityNodeHelper::normalizedTestName($verb);

            if (str_ends_with($verb, 's') && strlen($verb) >= 4) {
                $candidates[] = TestQualityNodeHelper::normalizedTestName(substr($verb, 0, -1));
            }
        }

        return array_values(array_unique($candidates));
    }
}
