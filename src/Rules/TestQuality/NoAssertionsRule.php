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
 * Reports PHPUnit or Pest tests that execute code without any recognized assertion, expectation, or skip/incomplete marker.
 *
 * When a user enables `test-quality.no-assertions` in `.gruff-php.yaml`, this catches smoke tests that only prove code did not crash.
 * Emits error findings at medium confidence and stays disabled by default so projects can opt in explicitly.
 *
 * Recognizes the following expectation evidence before flagging a test:
 * - Direct PHPUnit assertions, Pest `expect()` calls, mock verifications, and `addToAssertionCount()` / `markTestIncomplete()` / `markTestSkipped()`.
 * - Same-class helper method chains up to eight bodies deep and Laravel `AssertableJson::fromArray()` fluent JSON checks.
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
        return new RuleDefinition(
            id:                  self::ID,
            name:                'Test without assertions',
            pillar:              Pillar::TestQuality,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Error,
            confidence:          Confidence::Medium,
            isEnabledByDefault:  false,
            falsePositiveShapes: [
                [
                    'shape' => 'A test whose checks run inside a project helper with a domain name, such as seeInDatabase() or verifyRendered(), rather than an assert-prefixed call.',
                    'mitigation' => 'Direct supported expectations, invoked same-class helpers within eight method bodies, and addToAssertionCount(), markTestIncomplete(), or markTestSkipped() calls are recognized. External or unresolved helpers still need a visible assertion; helper names alone do not establish a check.',
                ],
            ],
        );
    }

    /**
     * Reports each test scope for which no supported check or exemption is detected.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for scopes with no detected check or exemption; empty when none qualify.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every discovered PHPUnit test method or Pest test callback in the file for observable verification.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // Keep the test quiet once any direct assertion, same-class helper assertion, or fluent JSON check is found.
            if ($this->hasObservableExpectation($scope)
                || InvokedTestMethodEvidence::hasExpectation($scope, $analysisUnit)
                || FluentJsonAssertionEvidence::hasExpectation($scope, $analysisUnit)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s has no detected PHPUnit or Pest assertions.', $scope->symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->anchorLine(),
                severity:    Severity::Error,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                symbol:      $scope->symbol,
                remediation: 'Add an assertion or expectation that proves the observable behaviour this test names.',
                metadata:    ['framework' => $scope->isPest ? 'pest' : 'phpunit'],
            );
        }

        return $findings;
    }

    /**
     * Reports whether the rule detects a supported assertion, verification, expectation, skip, or incomplete marker.
     *
     * @param TestQualityScope $scope - Test scope searched for supported expectation evidence.
     *
     * @return bool - True when supported assertion, verification, expectation, skip, or incomplete evidence is detected.
     */
    private function hasObservableExpectation(TestQualityScope $scope): bool
    {
        // Standard PHPUnit assertions and Pest expect() calls immediately satisfy the rule.
        if (TestQualityNodeHelper::assertionCalls($scope) !== []) {
            return true;
        }

        // A legacy @expectedException docblock annotation counts as observable throw evidence for older PHPUnit tests.
        if ($scope->node instanceof ClassMethod && $this->hasExpectedExceptionAnnotation($scope->node)) {
            return true;
        }

        // Scan remaining calls for mock expectations or explicit PHPUnit assertion-count and skip/incomplete markers.
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            // A supported mock-expectation call such as expects() or shouldReceive() counts as observable verification.
            if (TestQualityNodeHelper::isMockVerificationCall($call)) {
                return true;
            }

            $name = TestQualityNodeHelper::callName($call);
            // Explicitly recording assertion counts or marking a test skipped/incomplete explains why no assert*() call appears.
            if (in_array($name, ['addtoassertioncount', 'marktestincomplete', 'marktestskipped'], true)) {
                return true;
            }
        }

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
