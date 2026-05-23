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
use PhpParser\Node\Stmt;

/**
 * Detects oversized setup methods that make individual tests depend on shared state.
 */
final readonly class SetupBloatRule implements RuleInterface
{
    /**
     * Stable rule identifier for setup bloat findings.
     */
    public const ID = 'test-quality.setup-bloat';

    /**
     * Describe the setup bloat rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Setup bloat',
            pillar:            Pillar::TestQuality,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Medium,
            defaultThresholds: ['minSetupLines' => 8],
        );
    }

    /**
     * Find setup methods that exceed the configured size threshold.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for oversized setup methods.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition    = $this->definition();
        $minSetupLines = (int) $ruleContext->settingsFor($definition)->numericThreshold('minSetupLines');
        $findings      = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $setup          = null;
            $testLineCounts = [];

            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() === 'setUp') {
                    $setup = $method;
                    continue;
                }

                if (TestQualityNodeHelper::isTestMethod($method)) {
                    $testLineCounts[] = max(1, $method->getEndLine() - $method->getStartLine() + 1);
                }
            }

            if (!$setup instanceof Stmt\ClassMethod || $testLineCounts === []) {
                continue;
            }

            $setupLines       = max(1, $setup->getEndLine() - $setup->getStartLine() + 1);
            $averageTestLines = array_sum($testLineCounts) / count($testLineCounts);
            if ($setupLines < $minSetupLines || $setupLines <= $averageTestLines) {
                continue;
            }

            $className  = $class->name?->toString() ?? sprintf('anonymous@%d', $class->getStartLine());
            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s::setUp() is longer than the average test method.', $className),
                filePath:    $analysisUnit->file->displayPath,
                line:        $setup->getStartLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                symbol:      $className . '::setUp()',
                remediation: 'Inline scenario-specific setup or extract named builders when shared setup hides test intent.',
                metadata:    ['setupLines' => $setupLines, 'averageTestLines' => round($averageTestLines, 2)],
            );
        }

        return $findings;
    }
}
