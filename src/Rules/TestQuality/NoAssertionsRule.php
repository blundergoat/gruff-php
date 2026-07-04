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
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Flags a test that runs without a single observable check - no PHPUnit/Pest assertion, no mock
 * verification, no expectException, no explicit assertion-count marker - so it proves nothing and passes
 * as long as the code does not throw. Runs over every test in the file. Error severity, medium confidence.
 */
final readonly class NoAssertionsRule implements RuleInterface
{
    /**
     * Stable rule identifier for assertion-free test findings.
     */
    public const ID = 'test-quality.no-assertions';

    /**
     * Describes the no-assertions rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Error severity: a test that asserts nothing proves nothing, so it should fail the gate by default.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Test without assertions',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Error,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Reports tests that do not contain an observable assertion or expectation.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for assertion-free tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // A test with any observable expectation is already proving something.
            if ($this->hasObservableExpectation($scope)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s has no detected PHPUnit or Pest assertions.', $scope->symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Error,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                symbol:      $scope->symbol,
                remediation: 'Add an assertion or expectation that proves observable behavior, or disable this rule for custom assertion wrappers.',
                metadata:    ['framework' => $scope->isPest ? 'pest' : 'phpunit'],
            );
        }

        return $findings;
    }

    /**
     * Reports whether a test has any observable assertion, verification, or expectation marker.
     *
     * @param TestQualityScope $scope - Test scope whose body is searched for any observable expectation.
     *
     * @return bool - True when the test has an observable expectation.
     */
    private function hasObservableExpectation(TestQualityScope $scope): bool
    {
        if (TestQualityNodeHelper::assertionCalls($scope) !== []) {
            // A direct assertion call is the clearest proof of intent, so the test is already covered.
            return true;
        }

        if ($scope->node instanceof ClassMethod && $this->hasExpectedExceptionAnnotation($scope->node)) {
            // A legacy @expectedException docblock asserts a throw, so count it as an observable expectation.
            return true;
        }

        // Fall back to scanning the test's calls for a mock check or explicit marker.
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (TestQualityNodeHelper::isMockVerificationCall($call)) {
                // A mock verification (such as a Mockery expectation) is checked at teardown, so it counts.
                return true;
            }

            $name = TestQualityNodeHelper::callName($call);
            if (in_array($name, ['addtoassertioncount', 'marktestincomplete', 'marktestskipped'], true)) {
                // These PHPUnit calls register an explicit expectation or deliberate skip, not an empty test.
                return true;
            }
        }

        // No assertion, expectation annotation, mock verification, or marker found, so the test verifies nothing.
        return false;
    }

    /**
     * Reports whether a method docblock declares a legacy `@expectedException` annotation.
     *
     * @param ClassMethod $classMethod - Test method whose docblock is checked for the legacy annotation.
     *
     * @return bool - True when the method docblock declares an expected exception.
     */
    private function hasExpectedExceptionAnnotation(ClassMethod $classMethod): bool
    {
        $docText = strtolower($classMethod->getDocComment()?->getText() ?? '');

        // Lower-cased match so the legacy annotation is recognised regardless of how the author cased it.
        return str_contains($docText, '@expectedexception');
    }
}
