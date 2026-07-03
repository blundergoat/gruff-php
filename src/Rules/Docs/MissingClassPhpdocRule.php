<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

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
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Detects class-like declarations that are missing structural PHPDoc.
 */
final readonly class MissingClassPhpdocRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing class PHPDoc findings.
     */
    public const ID = 'docs.missing-class-phpdoc';

    /**
     * Describe the missing class PHPDoc rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory and high-confidence: a missing class docblock is unambiguous but not build-breaking.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing class PHPDoc',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find class-like declarations that do not have a PHPDoc block.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for undocumented class-like declarations.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, ClassLike::class) as $node) {
            // User view: choose the findings list branch for this case.
            if (!$node instanceof Class_ && !$node instanceof Interface_ && !$node instanceof Trait_ && !$node instanceof Enum_) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($node->name === null) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($node->getDocComment() !== null) {
                continue;
            }

            $kind = $this->classKind($node);
            $name = $node->name->toString();

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the class name).', ucfirst($kind), $name),
                filePath:    $analysisUnit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $name,
                remediation: 'Add a one-line `/** Description. */` block above the type. This rule wants content, not boilerplate - if your project policy is "no comments", that policy is about avoiding comments that restate code, not about removing documentation. The description should answer "what is this for, what does it own, what must callers satisfy."',
                metadata:    [
                    'classKind' => $kind,
                    'name' => $name,
                ],
            );
        }

        return $findings;
    }

    /**
     * Return the declaration kind for a class-like node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Class-like node already confirmed to be a class, interface, trait, or enum.
     *
     * @return string - One of class, interface, trait, or enum.
     */
    private function classKind(Node $node): string
    {
        // Caller has narrowed the node to one of the four kinds, so class is a safe default arm.
        return match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Trait_ => 'trait',
            $node instanceof Enum_ => 'enum',
            default => 'class',
        };
    }
}
