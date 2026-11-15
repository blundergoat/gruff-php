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
use GruffPhp\Rules\Size\SubstantiveLineCounter;
use PhpParser\Node\Stmt;

/**
 * Flags a PHPUnit setUp() whose substantive line count reaches the configured minimum and exceeds
 * the class's average test-method length. Runs per class with at least one recognised test method.
 * Advisory, medium confidence: a large shared fixture can be deliberate.
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
     * @return RuleDefinition - Setup-bloat metadata with advisory severity, medium confidence, and the default setup-line threshold.
     */
    public function definition(): RuleDefinition
    {
        // A heavy setUp may be deliberate, so the rule defaults to a medium-confidence advisory.
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
     * Reports setUp() methods at or above the minimum length and longer than the class's average test.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Supplies the minSetupLines threshold.
     *
     * @return list<Finding> - Findings for oversized setup methods.
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
                    $testLineCounts[] = max(1, SubstantiveLineCounter::countRange($analysisUnit, $method->getStartLine(), $method->getEndLine()));
                }
            }


            if (!$setup instanceof Stmt\ClassMethod || $testLineCounts === []) {
                continue;
            }

            // Code lines only (FAMILY-CONTRACT section 12): comments, attributes and blank lines are free on both sides.
            $setupLines       = max(1, SubstantiveLineCounter::countRange($analysisUnit, $setup->getStartLine(), $setup->getEndLine()));
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
