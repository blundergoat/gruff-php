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

/**
 * Detects legacy data provider annotations that should use PHPUnit attributes.
 */
final readonly class DataProviderAnnotationRule implements RuleInterface
{
    /**
     * Stable rule identifier for data provider annotation findings.
     */
    public const ID = 'test-quality.data-provider-annotation';

    /**
     * Describe the data provider annotation rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory tier: attribute providers need PHPUnit 10+, so the annotation form stays valid on older suites.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Data provider annotation',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find legacy data provider annotations in PHPUnit tests.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for data provider annotation usage.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            if (!$scope->node instanceof Stmt\ClassMethod) {
                continue;
            }

            $comment = $scope->node->getDocComment()?->getText() ?? '';
            if (!str_contains($comment, '@dataProvider')) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s uses @dataProvider instead of the PHPUnit attribute form.', $scope->symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                symbol:      $scope->symbol,
                remediation: 'Prefer #[DataProvider] for PHPUnit 10+ codebases when the project supports attributes.',
            );
        }

        // One finding per method whose docblock still carries the legacy @dataProvider annotation.
        return $findings;
    }
}
