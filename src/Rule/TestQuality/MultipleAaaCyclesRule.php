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
use PhpParser\NodeFinder;

/**
 * Detects tests that repeat arrange-act-assert cycles in one method.
 */
final readonly class MultipleAaaCyclesRule implements RuleInterface
{
    /**
     * Stable rule identifier for repeated AAA cycle findings.
     */
    public const ID = 'test-quality.multiple-aaa-cycles';

    /**
     * Describe the multiple arrange-act-assert cycles rule.
     *
     * @return RuleDefinition Rule metadata, defaults, and thresholds.
     */
    public function definition(): RuleDefinition
    {
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
        );
    }

    /**
     * Find tests that appear to repeat act/assert cycles in one method.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for repeated AAA cycles.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $settings = $ruleContext->settingsFor($this->definition());

        if ($this->isPathIgnored($analysisUnit->file->displayPath, $settings->stringListOption('ignoredPathPatterns'))) {
            return [];
        }

        $threshold = (int) $settings->numericThreshold('minCycles');
        $findings  = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $cycles = $this->countActAssertCycles($scope);

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
     * Count apparent act-then-assert cycles across top-level test statements.
     *
     * @return int Number of detected cycles.
     */
    private function countActAssertCycles(TestQualityScope $scope): int
    {
        $cycles              = 0;
        $sawNonAssertionCall = false;
        $nodeFinder          = new NodeFinder();

        foreach ($scope->statements as $stmt) {
            $hasAssertion        = false;
            $hasNonAssertionCall = false;

            $calls = $nodeFinder->find(
                [$stmt],
                static fn (Node $node): bool => $node instanceof Expr\FuncCall
                    || $node instanceof Expr\MethodCall
                    || $node instanceof Expr\StaticCall,
            );

            foreach ($calls as $call) {
                if (!$call instanceof Expr\FuncCall && !$call instanceof Expr\MethodCall && !$call instanceof Expr\StaticCall) {
                    continue;
                }

                if (TestQualityNodeHelper::isAssertionCall($call)) {
                    $hasAssertion = true;
                } elseif (!$this->isNestedInAssertionCall($call)
                    && !TestQualityNodeHelper::isMockCreationCall($call)
                    && !TestQualityNodeHelper::isMockVerificationCall($call)
                ) {
                    $hasNonAssertionCall = true;
                }
            }

            if ($hasAssertion) {
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
     * Check whether a project-configured path exemption applies.
     *
     * @param list<string> $patterns Glob patterns for accepted broad test shapes.
     * @return bool True when the display path matches an ignored pattern.
     */
    private function isPathIgnored(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect whether a call is used only to compute an assertion argument.
     *
     * @return bool True when the call is nested inside an assertion call.
     */
    private function isNestedInAssertionCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        while ($parent instanceof Node) {
            if (($parent instanceof Expr\FuncCall || $parent instanceof Expr\MethodCall || $parent instanceof Expr\StaticCall)
                && TestQualityNodeHelper::isAssertionCall($parent)
            ) {
                return true;
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }
}
