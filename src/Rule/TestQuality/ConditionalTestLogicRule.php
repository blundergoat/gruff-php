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
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Detects branches in tests that make outcomes depend on local control flow.
 */
final readonly class ConditionalTestLogicRule implements RuleInterface
{
    /**
     * Stable rule identifier for conditional test logic findings.
     */
    public const ID = 'test-quality.conditional-logic';

    /**
     * Describe the conditional test logic rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Conditional test logic',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find test cases that hide behavior behind conditionals.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for conditional logic inside tests.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder   = new NodeFinder();
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach ($finder->findInstanceOf($scope->statements, Stmt\If_::class) as $conditional) {
                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s contains conditional logic; tests should usually be linear.', $scope->symbol),
                    filePath:    $unit->file->displayPath,
                    line:        $conditional->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::High,
                    symbol:      $scope->symbol,
                    remediation: 'Split branches into separate test cases with explicit setup and expectations.',
                );
            }
        }

        return $findings;
    }
}
