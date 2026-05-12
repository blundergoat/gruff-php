<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Detects method declarations that are missing local PHPDoc.
 */
final readonly class MissingPublicPhpdocRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing method PHPDoc findings.
     */
    public const ID = 'docs.missing-public-phpdoc';

    /**
     * Describe the missing method PHPDoc rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing method PHPDoc',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Error,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find method declarations that do not have a local PHPDoc block.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for undocumented methods.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder     = new NodeFinder();
        $findings   = [];

        foreach ($finder->findInstanceOf($unit->statements, ClassMethod::class) as $method) {
            /** @var ClassMethod $method Finder predicate restricts results to method declarations. */
            if ($method->getDocComment() !== null) {
                continue;
            }

            $findings[] = $this->findingForMethod($unit, $definition, $method);
        }

        return $findings;
    }

    /**
     * Build the missing PHPDoc finding for one method.
     *
     * @return Finding Documentation finding.
     */
    private function findingForMethod(AnalysisUnit $unit, RuleDefinition $definition, ClassMethod $method): Finding
    {
        $symbol = CyclomaticComplexityRule::resolveSymbol($method);

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('Method %s has no PHPDoc.', $symbol),
            filePath:    $unit->file->displayPath,
            line:        $method->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Add a docblock describing the method\'s purpose.',
        );
    }
}
