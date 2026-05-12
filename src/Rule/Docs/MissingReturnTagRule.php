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
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Detects documented methods that omit an explicit @return contract.
 */
final readonly class MissingReturnTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing @return tag findings.
     */
    public const ID = 'docs.missing-return-tag';

    /**
     * Describe the missing @return tag rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing @return tag',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            description:     'Every documented method must declare its return contract with an @return tag, including methods declared void or never. Constructors and destructors are exempt.',
        );
    }

    /**
     * Find documented function-like declarations that lack an @return tag.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for missing return tags.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder     = new NodeFinder();
        $nodes      = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            if ($node instanceof ClassMethod && in_array($node->name->toString(), ['__construct', '__destruct'], true)) {
                continue;
            }

            $docComment = $node->getDocComment();

            if ($docComment === null) {
                continue;
            }

            $docText = $docComment->getText();

            if (str_contains($docText, '@return')) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s has a docblock but no @return tag.', $symbol),
                filePath:    $unit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Add @return tag documenting the return value.',
            );
        }

        return $findings;
    }

}
