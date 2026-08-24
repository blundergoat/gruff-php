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
 * Flags a PHPUnit `setUp()` that is both over the line cap and longer than the class's average test method
 * - shared setup heavy enough that each test's real intent is hidden behind it. Runs per test class; the
 * minimum-lines cap is tunable. Advisory, medium confidence - a heavy setUp is sometimes deliberate.
 */
final readonly class SetupBloatRule implements RuleInterface
{
    /**
     * Stable rule identifier for setup bloat findings.
     */
    public const ID = 'test-quality.setup-bloat';

    /**
     * Describes the setup-bloat rule for the registry and reports.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A class of short, focused tests over one expensive fixture, where the setUp is long only because the tests are deliberately small.',
                    'mitigation' => 'The comparison is against the class\'s average test length, so a suite of one-line tests trips it; raise this rule\'s minSetupLines threshold.',
                ],
            ],
        );
    }

    /**
     * Reports setUp() methods that are longer than the average test method.
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

        // Weigh every test class in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $setup          = null;
            $testLineCounts = [];

            // Find the setUp() method and measure each test method's length.
            foreach ($class->getMethods() as $method) {
                // The setUp() method is the shared fixture under scrutiny.
                if ($method->name->toString() === 'setUp') {
                    $setup = $method;
                    continue;
                }

                // Record the span of each real test method for the average.
                if (TestQualityNodeHelper::isTestMethod($method)) {
                    $testLineCounts[] = max(1, $method->getEndLine() - $method->getStartLine() + 1);
                }
            }

            // Need both a setUp() and at least one test to compare against.
            if (!$setup instanceof Stmt\ClassMethod || $testLineCounts === []) {
                continue;
            }

            $setupLines       = max(1, $setup->getEndLine() - $setup->getStartLine() + 1);
            $averageTestLines = array_sum($testLineCounts) / count($testLineCounts);
            // Only flag a setUp() that is over the cap and longer than the typical test.
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
