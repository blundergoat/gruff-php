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
 * Detects test classes that inherit from production classes instead of exercising them.
 */
final readonly class ExtendsProductionClassRule implements RuleInterface
{
    /**
     * Stable rule identifier for production inheritance findings.
     */
    public const ID = 'test-quality.extends-production-class';

    /**
     * Describe the test extends production class rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Test extends production class',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Error,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find test classes that inherit directly from production classes.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for tests extending production types.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $className = $class->name?->toString();
            if ($className === null || $class->extends === null) {
                continue;
            }

            if (!str_ends_with($className, 'Test') && !str_ends_with($className, 'Tests')) {
                continue;
            }

            $parent      = $class->extends;
            $parentShort = strtolower($parent->getLast());

            if (str_ends_with($parentShort, 'testcase')) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s extends %s, which is not a recognised test base class.',
                    $className,
                    $parent->toString(),
                ),
                filePath:    $analysisUnit->file->displayPath,
                line:        $class->getStartLine(),
                severity:    Severity::Error,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                symbol:      $className,
                remediation: 'Test classes should extend a *TestCase base. If you need to reach private members, compose the production class as a collaborator and exercise it through its public surface.',
                metadata:    ['parent' => $parent->toString()],
            );
        }

        return $findings;
    }
}
