<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Detects loop constructs in tests that obscure individual cases.
 */
final readonly class LoopInTestRule implements RuleInterface
{
    /**
     * Stable rule identifier for loop-in-test findings.
     */
    public const ID = 'test-quality.loop-in-test';

    /**
     * Describe the loop in test rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Loop in test',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            defaultOptions:  ['ignoredPathPatterns' => []],
        );
    }

    /**
     * Find tests with loops that can obscure individual cases.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for loop constructs in tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        if ($this->isPathIgnored($analysisUnit->file->displayPath, $settings->stringListOption('ignoredPathPatterns'))) {
            return [];
        }

        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            foreach (NodeIndex::descendantsOfAny($scope->node, [Stmt\For_::class, Stmt\Foreach_::class, Stmt\While_::class, Stmt\Do_::class]) as $loop) {
                if (!$this->hasLoopAssertion($loop)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s contains a loop; looping assertions often hide multiple scenarios.', $scope->symbol),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $loop->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::High,
                    symbol:      $scope->symbol,
                    remediation: 'Use data providers or named scenarios so each failing case is visible.',
                );
            }
        }

        return $findings;
    }

    /**
     * Check whether a project-configured path exemption applies.
     *
     * @param list<string> $patterns Glob patterns for accepted test shapes.
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
     * Check whether a loop actually contains an assertion-like call.
     *
     * @return bool True when a PHPUnit/Pest assertion is inside the loop body.
     */
    private function hasLoopAssertion(Stmt\For_|Stmt\Foreach_|Stmt\While_|Stmt\Do_ $loop): bool
    {
        foreach ($loop->stmts as $stmt) {
            if ($this->hasAssertionDescendant($stmt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively check whether a statement subtree contains an assertion call.
     *
     * @return bool True when a PHPUnit/Pest assertion call is reachable from the statement.
     */
    private function hasAssertionDescendant(Node $node): bool
    {
        if (($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
            && TestQualityNodeHelper::isAssertionCall($node)
        ) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subTree = $node->{$subNodeName};
            if ($subTree instanceof Node && $this->hasAssertionDescendant($subTree)) {
                return true;
            }
            if (is_array($subTree)) {
                foreach ($subTree as $item) {
                    if ($item instanceof Node && $this->hasAssertionDescendant($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
