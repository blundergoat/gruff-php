<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory at medium confidence: a heavy setUp may be deliberate, so this nudges rather than gates.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for oversized setup methods.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition    = $this->definition();
        $minSetupLines = (int) $ruleContext->settingsFor($definition)->numericThreshold('minSetupLines');
        $findings      = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $setup          = null;
            $testLineCounts = [];

            // User view: add each item that can appear in findings list.
            foreach ($class->getMethods() as $method) {
                // User view: choose the findings list branch for this case.
                if ($method->name->toString() === 'setUp') {
                    $setup = $method;
                    continue;
                }

                // User view: choose the findings list branch for this case.
                if (TestQualityNodeHelper::isTestMethod($method)) {
                    $testLineCounts[] = max(1, $method->getEndLine() - $method->getStartLine() + 1);
                }
            }

            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if (!$setup instanceof Stmt\ClassMethod || $testLineCounts === []) {
                continue;
            }

            $setupLines       = max(1, $setup->getEndLine() - $setup->getStartLine() + 1);
            $averageTestLines = array_sum($testLineCounts) / count($testLineCounts);
            // User view: choose the findings list branch for this case.
            if ($setupLines < $minSetupLines || $setupLines <= $averageTestLines) {
                continue;
            }

            // User view: missing data becomes a safe findings list default.
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
