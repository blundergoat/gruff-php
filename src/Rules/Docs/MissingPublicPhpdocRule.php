<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for undocumented methods.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, ClassMethod::class) as $classMethod) {
            /** @var ClassMethod $classMethod Finder predicate restricts results to method declarations. */
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit  $analysisUnit - Parsed unit supplying the display path reported in the finding.
     * @param RuleDefinition $definition - Rule metadata supplying severity, pillar, tier, and confidence.
     * @param ClassMethod   $classMethod - Undocumented method whose name and start line are reported.
     *
     * @return Finding - Documentation finding.
     */
    private function findingForMethod(AnalysisUnit $analysisUnit, RuleDefinition $definition, ClassMethod $classMethod): Finding
    {
        $symbol = CyclomaticComplexityRule::resolveSymbol($classMethod);

        // Finding anchored at the method declaration line, flagging its absent docblock.
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
