<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for undocumented class-like declarations.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder     = new NodeFinder();
        $findings   = [];

        foreach ($finder->findInstanceOf($unit->statements, ClassLike::class) as $node) {
            if (!$node instanceof Class_ && !$node instanceof Interface_ && !$node instanceof Trait_ && !$node instanceof Enum_) {
                continue;
            }

            if ($node->name === null) {
                continue;
            }

            if ($node->getDocComment() !== null) {
                continue;
            }

            $kind = $this->classKind($node);
            $name = $node->name->toString();

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s %s has no PHPDoc.', ucfirst($kind), $name),
                filePath:    $unit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $name,
                remediation: 'Add a docblock describing the type\'s purpose.',
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
     * @return string One of class, interface, trait, or enum.
     */
    private function classKind(Node $node): string
    {
        return match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Trait_ => 'trait',
            $node instanceof Enum_ => 'enum',
            default => 'class',
        };
    }
}
