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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Flags a test method that runs several act-then-assert cycles back to back - a sign it packs multiple
 * scenarios into one test, so a failure does not point at a single behaviour. Runs over every test; the
 * cycle threshold is tunable and end-to-end suites can be exempted by path. Advisory, low confidence.
 */
final readonly class MultipleAaaCyclesRule implements RuleInterface
{
    /**
     * Stable rule identifier for repeated AAA cycle findings.
     */
    public const ID = 'test-quality.multiple-aaa-cycles';

    /**
     * Describes the multiple-arrange-act-assert-cycles rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata, defaults, and thresholds.
     */
    public function definition(): RuleDefinition
    {
        // Low confidence: cycle counting is heuristic, so default to advisory and only flag at three or more.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Multiple arrange-act-assert cycles',
            pillar:             Pillar::TestQuality,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::Low,
            defaultThresholds:  ['minCycles' => 3],
            defaultOptions:     ['ignoredPathPatterns' => []],
            isEnabledByDefault: true,
            falsePositiveShapes: [
                [
                    'shape'      => 'One workflow test that must assert between steps, such as an end-to-end journey checking state after each transition.',
                    'mitigation' => 'Act-then-assert transitions are counted without judging whether they form one workflow, so add the suite path to options.ignoredPathPatterns or raise minCycles.',
                ],
            ],
        );
    }

    /**
     * Reports tests that appear to repeat act/assert cycles in one method.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for repeated AAA cycles.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $settings = $ruleContext->settingsFor($this->definition());

        if ($this->isPathIgnored($analysisUnit->file->displayPath, $settings->stringListOption('ignoredPathPatterns'))) {
            // This path is exempted (e.g. an end-to-end suite that legitimately chains scenarios); skip it.
            return [];
        }

        $threshold = (int) $settings->numericThreshold('minCycles');
        $findings  = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $cycles = $this->countActAssertCycles($scope);

            // Stay quiet until the cycle count reaches the configured threshold.
            if ($cycles < $threshold) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s contains %d act-then-assert cycles; consider splitting into focused tests.',
                    $scope->symbol,
                    $cycles,
                ),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Low,
                symbol:      $scope->symbol,
                remediation: 'Each test should arrange once, act once, and assert once. Split this method into separate tests for each scenario. If a path legitimately uses sequential scenarios (e.g. end-to-end suites), add it to `rules.test-quality.multiple-aaa-cycles.options.ignoredPathPatterns` in `.gruff-php.yaml`.',
                metadata:    ['cycles' => $cycles, 'threshold' => $threshold],
            );
        }

        return $findings;
    }

    /**
     * Counts the apparent act-then-assert cycles across a test's top-level statements.
     *
     * @param TestQualityScope $scope - Test method whose top-level statements are scanned for act/assert runs.
     *
     * @return int - Number of detected cycles.
     */
    private function countActAssertCycles(TestQualityScope $scope): int
    {
        $cycles              = 0;
        $sawNonAssertionCall = false;
        $nodeFinder          = new NodeFinder();

        // Walk the test's top-level statements in order.
        foreach ($scope->statements as $stmt) {
            $hasAssertion        = false;
            $hasNonAssertionCall = false;

            $calls = $nodeFinder->find(
                [$stmt],
                static fn (Node $node): bool => $node instanceof Expr\FuncCall
                    || $node instanceof Expr\MethodCall
                    || $node instanceof Expr\StaticCall,
            );

            // Classify every call the statement makes.
            foreach ($calls as $call) {
                // Only real call nodes are act or assertion evidence.
                if (!$call instanceof Expr\FuncCall && !$call instanceof Expr\MethodCall && !$call instanceof Expr\StaticCall) {
                    continue;
                }

                // An assertion closes a cycle; any other call opens a new act.
                if (TestQualityNodeHelper::isAssertionCall($call)) {
                    $hasAssertion = true;
                } elseif (!$this->isNestedInAssertionCall($call)
                    && !TestQualityNodeHelper::isMockCreationCall($call)
                    && !TestQualityNodeHelper::isMockVerificationCall($call)
                ) {
                    $hasNonAssertionCall = true;
                }
            }

            // An assertion after some act completes one arrange-act-assert cycle.
            if ($hasAssertion) {
                // Count the cycle only when a real act preceded this assertion.
                if ($sawNonAssertionCall || $hasNonAssertionCall) {
                    $cycles++;
                }

                $sawNonAssertionCall = false;
                continue;
            }

            $sawNonAssertionCall = $hasNonAssertionCall;
        }

        return $cycles;
    }

    /**
     * Reports whether a project-configured path exemption applies.
     *
     * @param string       $displayPath - Display path of the unit under test, matched after slash normalisation.
     * @param list<string> $patterns - Glob patterns for accepted broad test shapes.
     *
     * @return bool - True when the display path matches an ignored pattern.
     */
    private function isPathIgnored(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        // Weigh the display path against each configured exemption glob.
        foreach ($patterns as $pattern) {
            // A matching pattern opts this path out of the rule.
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a call only computes an argument for an enclosing assertion.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Inner call whose ancestor chain is walked.
     *
     * @return bool - True when the call is nested inside an assertion call.
     */
    private function isNestedInAssertionCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        // Climb the call's ancestors looking for an enclosing assertion.
        while ($parent instanceof Node) {
            if (($parent instanceof Expr\FuncCall || $parent instanceof Expr\MethodCall || $parent instanceof Expr\StaticCall)
                && TestQualityNodeHelper::isAssertionCall($parent)
            ) {
                // An assertion ancestor means this call only builds an assertion argument, not a separate act.
                return true;
            }

            $parent = $parent->getAttribute('parent');
        }

        // Reached the top of the tree without an assertion ancestor, so this call is a standalone act.
        return false;
    }
}
