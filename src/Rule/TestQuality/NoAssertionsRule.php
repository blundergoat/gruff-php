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
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Detects tests that execute without making a verifiable assertion.
 */
final readonly class NoAssertionsRule implements RuleInterface
{
    /**
     * Stable rule identifier for assertion-free test findings.
     */
    public const ID = 'test-quality.no-assertions';

    /**
     * Describe the no-assertions rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Test without assertions',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find tests that do not contain an observable assertion or expectation.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for assertion-free tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            if ($this->hasObservableExpectation($scope)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s has no detected PHPUnit or Pest assertions.', $scope->symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Warning,
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
     * Detect assertions, mock verifications, or explicit PHPUnit expectation markers.
     *
     * @return bool True when the test has an observable expectation.
     */
    private function hasObservableExpectation(TestQualityScope $scope): bool
    {
        if (TestQualityNodeHelper::assertionCalls($scope) !== []) {
            return true;
        }

        if ($scope->node instanceof ClassMethod && $this->hasExpectedExceptionAnnotation($scope->node)) {
            return true;
        }

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (TestQualityNodeHelper::isMockVerificationCall($call)) {
                return true;
            }

            $name = TestQualityNodeHelper::callName($call);
            if (in_array($name, ['addtoassertioncount', 'marktestincomplete', 'marktestskipped'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect legacy `@expectedException` annotations.
     *
     * @return bool True when the method docblock declares an expected exception.
     */
    private function hasExpectedExceptionAnnotation(ClassMethod $method): bool
    {
        $docText = strtolower($method->getDocComment()?->getText() ?? '');

        return str_contains($docText, '@expectedexception');
    }
}
