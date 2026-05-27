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
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Stmt\ClassMethod;

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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for undocumented methods.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, ClassMethod::class) as $classMethod) {
            /** @var ClassMethod $classMethod Finder predicate restricts results to method declarations. */
            if ($classMethod->getDocComment() !== null) {
                continue;
            }

            $findings[] = $this->findingForMethod($analysisUnit, $definition, $classMethod);
        }

        return $findings;
    }

    /**
     * Build the missing PHPDoc finding for one method.
     *
     * @return Finding Documentation finding.
     */
    private function findingForMethod(AnalysisUnit $analysisUnit, RuleDefinition $definition, ClassMethod $classMethod): Finding
    {
        $symbol = CyclomaticComplexityRule::resolveSymbol($classMethod);

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('Method %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the method signature).', $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $classMethod->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Add a one-line `/** Description. */` block above the method. This rule wants content, not boilerplate - if your project policy is "no comments", that policy is about avoiding comments that restate code, not about removing documentation. The description should answer "what is this for, what does it return at the edge value, what must the caller satisfy."',
        );
    }
}
