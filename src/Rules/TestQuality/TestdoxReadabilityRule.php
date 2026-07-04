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
 * Flags a PHPUnit test method whose name yields too few words to read as a behaviour sentence in testdox
 * output - `testProcess` renders as "process", which tells a reader nothing about the scenario. Runs over
 * every non-Pest test method; the minimum-words cap is tunable. Advisory, low confidence.
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
        );
    }

    /**
     * Reports test names that produce hard-to-read TestDox output.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one advisory finding per non-Pest test method whose name yields fewer words than the threshold; empty when every name
     *                       passes
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $threshold = (int)$ruleContext->settingsFor($this->definition())->numericThreshold('minWords');
        $findings  = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // Only a non-Pest method name renders through testdox here.
            if ($scope->isPest || !$scope->node instanceof ClassMethod) {
                continue;
            }

            $methodName = $scope->name;
            $words      = $this->splitWords($methodName);

            // A name with enough words already reads as a sentence.
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

        return $findings;
    }

    /**
     * Splits a test method name into TestDox words for the readability check.
     *
     * @param string $methodName - Raw test method name; the `test` prefix is stripped and CamelCase split into words.
     *
     * @return list<string> - the test name's words in order, `test` prefix removed and CamelCase split; empty when the name reduces to nothing
     */
    private function splitWords(string $methodName): array
    {
        $name   = preg_replace('/^test[_]?/i', '', $methodName) ?? $methodName;
        $name   = (string)preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
        $tokens = preg_split('/[\s_]+/', $name) ?: [];

        // Drop empty tokens so the word count reflects real words, not separators between them.
        return array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));
    }

    /**
     * Renders the split words as PHPUnit would show them in testdox output.
     *
     * @param list<string> $words - Words split from the test name, in order; empty when the name reduced to nothing.
     *
     * @return string - the words lower-cased and space-joined to mirror PHPUnit's testdox output; empty string when no words remain
     */
    private function renderTestdox(array $words): string
    {
        // No words left means an empty testdox line.
        if ($words === []) {
            return '';
        }

        return strtolower(implode(' ', $words));
    }
}
