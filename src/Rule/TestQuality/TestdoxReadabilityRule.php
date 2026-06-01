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
        // Low confidence: a short name is only a hint of poor testdox output, so this stays advisory and on by default.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Testdox readability',
            pillar:             Pillar::TestQuality,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::Low,
            defaultThresholds:  ['minWords' => 2],
            isEnabledByDefault: true,
        );
    }

    /**
     * Find test names that produce hard-to-read TestDox output.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for unreadable TestDox names.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $threshold = (int) $ruleContext->settingsFor($this->definition())->numericThreshold('minWords');
        $findings  = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            if ($scope->isPest || !$scope->node instanceof ClassMethod) {
                continue;
            }

            $methodName = $scope->name;
            $words      = $this->splitWords($methodName);

            if (count($words) >= $threshold) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s would render as testdox "%s" (%d words), below the %d-word readability threshold.',
                    $scope->symbol,
                    $this->renderTestdox($words),
                    count($words),
                    $threshold,
                ),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Low,
                symbol:      $scope->symbol,
                remediation: 'Rename the test so it describes the scenario and expected behaviour (e.g. testProcess -> testProcessRejectsCancelledOrders).',
                metadata:    ['words' => count($words), 'threshold' => $threshold],
            );
        }

        // Hand back one finding per non-Pest test method whose name yields fewer words than the threshold.
        return $findings;
    }

    /**
     * Split testdox text into words for readability checks.
     *
     * @param string $methodName Raw test method name; the `test` prefix is stripped and CamelCase split into words.
     * @return list<string>
     */
    private function splitWords(string $methodName): array
    {
        $name   = preg_replace('/^test[_]?/i', '', $methodName) ?? $methodName;
        $name   = (string) preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
        $tokens = preg_split('/[\s_]+/', $name) ?: [];

        // Drop empty tokens so the word count reflects real words, not separators between them.
        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    /**
     * @param list<string> $words Words split from the test name, in order; empty when the name reduced to nothing.
     * @return string Rendered TestDox phrase.
     */
    private function renderTestdox(array $words): string
    {
        if ($words === []) {
            // No words to render means an empty testdox phrase, matching how PHPUnit would display it.
            return '';
        }

        // Lower-case the joined words to mirror PHPUnit's testdox formatting in the finding message.
        return strtolower(implode(' ', $words));
    }
}
