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
 * Detects TestDox names that are too hard to read as behavior descriptions.
 */
final readonly class TestdoxReadabilityRule implements RuleInterface
{
    /**
     * Stable rule identifier for TestDox readability findings.
     */
    public const ID = 'test-quality.testdox-readability';

    /**
     * Describe the TestDox readability rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Testdox readability',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Low,
            defaultThresholds: ['minWords' => 3],
            defaultEnabled: false,
        );
    }

    /**
     * Find test names that produce hard-to-read TestDox output.
     *
     * @return list<Finding> Findings for unreadable TestDox names.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $threshold = (int) $context->settingsFor($this->definition())->numericThreshold('minWords');
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            if ($scope->isPest || !$scope->node instanceof ClassMethod) {
                continue;
            }

            $methodName = $scope->name;
            $words = $this->splitWords($methodName);

            if (count($words) >= $threshold) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf(
                    '%s would render as testdox "%s" (%d words), below the %d-word readability threshold.',
                    $scope->symbol,
                    $this->renderTestdox($words),
                    count($words),
                    $threshold,
                ),
                filePath: $unit->file->displayPath,
                line: $scope->line,
                severity: Severity::Advisory,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::Low,
                symbol: $scope->symbol,
                remediation: 'Rename the test so it reads as a sentence describing the scenario and expected behaviour (e.g. testProcessOrder -> testProcessOrderMarksItAsPaid).',
                metadata: ['words' => count($words), 'threshold' => $threshold],
            );
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function splitWords(string $methodName): array
    {
        $name = preg_replace('/^test[_]?/i', '', $methodName) ?? $methodName;
        $name = (string) preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
        $tokens = preg_split('/[\s_]+/', $name) ?: [];

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    /**
     * @param list<string> $words
     * @return string Rendered TestDox phrase.
     */
    private function renderTestdox(array $words): string
    {
        if ($words === []) {
            return '';
        }

        return strtolower(implode(' ', $words));
    }
}
