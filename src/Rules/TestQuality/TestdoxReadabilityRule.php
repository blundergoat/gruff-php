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
 * Flags non-Pest test methods whose names split into fewer words than the configured minimum.
 * Uses a configurable minWords threshold as a readability heuristic.
 * Findings are advisory and have low confidence; word count does not establish sentence quality.
 */
final readonly class TestdoxReadabilityRule implements RuleInterface
{
    /**
     * Stable rule identifier for TestDox readability findings.
     */
    public const ID = 'test-quality.testdox-readability';

    /**
     * Describes the testdox-readability rule for the registry and reports.
     *
     * @return RuleDefinition - identity, pillar/tier, advisory severity, and the default minWords=2 threshold; enabled by default
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A short test name that is already precise because its class supplies the subject, such as testInvoke on a single-action class.',
                    'mitigation' => 'Only the method name is tokenised, never the class it sits in, so lengthen the name or raise this rule\'s minWords threshold.',
                ],
            ],
        );
    }

    /**
     * Reports test method names whose word count falls below the readability threshold.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose discovered test methods are checked.
     * @param RuleContext $ruleContext - Supplies the minWords threshold for this analysis pass.
     *
     * @return list<Finding> - One advisory finding per non-Pest test method below minWords; empty when none qualify.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $threshold = (int)$ruleContext->settingsFor($this->definition())->numericThreshold('minWords');
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
                ruleId:      self::ID,
                message:     sprintf(
                                 '%s would render as testdox "%s" (%d words), below the %d-word readability threshold.',
                                 $scope->symbol,
                                 $this->renderTestdox($words),
                                 count($words),
                                 $threshold,
                             ),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->anchorLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Low,
                symbol:      $scope->symbol,
                remediation: 'Rename the test so it describes the scenario and expected behaviour (e.g. testProcess -> testProcessRejectsCancelledOrders).',
                metadata:    ['words' => count($words), 'threshold' => $threshold],
            );
        }

        return $findings;
    }

    /**
     * Splits a test method name into TestDox words for the readability check.
     *
     * @param string $methodName - Raw test method name; the test prefix is stripped and CamelCase split into words.
     *
     * @return list<string> - the test name's words in order, test prefix removed and CamelCase split; empty when the name reduces to nothing
     */
    private function splitWords(string $methodName): array
    {
        // Ignore PHPUnit naming syntax and treat inner capitals, spaces, and underscores as word boundaries for the threshold.
        $name   = preg_replace('/^test[_]?/i', '', $methodName) ?? $methodName;
        $name   = (string)preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
        $tokens = preg_split('/[\s_]+/', $name) ?: [];

        // Drop empty tokens so the word count reflects real words, not separators between them.
        return array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));
    }

    /**
     * Renders the split words for this rule's finding preview.
     *
     * @param list<string> $words - Words split from the test name, in order; empty when the name reduced to nothing.
     *
     * @return string - Lowercase words joined by spaces for the finding preview; empty when no words remain.
     */
    private function renderTestdox(array $words): string
    {
        if ($words === []) {
            return '';
        }

        return strtolower(implode(' ', $words));
    }
}
