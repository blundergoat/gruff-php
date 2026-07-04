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
use PhpParser\Node\Stmt;

/**
 * Flags a test method that wires its data provider with the legacy `@dataProvider` docblock annotation
 * instead of the modern `#[DataProvider]` attribute, so a reviewer on PHPUnit 10+ can migrate it. Runs
 * over every test method in the file. Advisory, high confidence - the annotation still works on old suites.
 */
final readonly class DataProviderAnnotationRule implements RuleInterface
{
    /**
     * Stable rule identifier for data provider annotation findings.
     */
    public const ID = 'test-quality.data-provider-annotation';

    /**
     * Describes the data-provider-annotation rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
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
     * Reports legacy data-provider annotations that should use PHPUnit attributes.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for data provider annotation usage.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // Only a real PHPUnit method can carry a docblock annotation.
            if (!$scope->node instanceof Stmt\ClassMethod) {
                continue;
            }

            $comment = $scope->node->getDocComment()?->getText() ?? '';
            // Nothing to migrate unless the method declares a dataProvider annotation.
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

        return $findings;
    }
}
