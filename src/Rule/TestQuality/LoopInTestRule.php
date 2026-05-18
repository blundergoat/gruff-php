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
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

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
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for loop constructs in tests.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings   = $context->settingsFor($definition);

        if ($this->isPathIgnored($unit->file->displayPath, $settings->stringListOption('ignoredPathPatterns'))) {
            return [];
        }

        $finder   = new NodeFinder();
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_) as $loop) {
                if (!$loop instanceof Stmt\For_
                    && !$loop instanceof Stmt\Foreach_
                    && !$loop instanceof Stmt\While_
                    && !$loop instanceof Stmt\Do_
                ) {
                    continue;
                }

                if (!$this->hasLoopAssertion($finder, $loop)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s contains a loop; looping assertions often hide multiple scenarios.', $scope->symbol),
                    filePath:    $unit->file->displayPath,
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
    private function hasLoopAssertion(NodeFinder $finder, Stmt\For_|Stmt\Foreach_|Stmt\While_|Stmt\Do_ $loop): bool
    {
        return $finder->findFirst(
            $loop->stmts,
            static fn (Node $node): bool => ($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
                && TestQualityNodeHelper::isAssertionCall($node),
        ) !== null;
    }
}
