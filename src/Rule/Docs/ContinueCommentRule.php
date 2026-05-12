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
use PhpParser\Node\Stmt\Continue_;
use PhpParser\NodeFinder;

/**
 * Detects continue statements that lack an immediately preceding explanatory comment.
 */
final readonly class ContinueCommentRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing continue comment findings.
     */
    public const ID = 'docs.continue-comment';

    /**
     * Describe the continue-comment documentation rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Continue comment',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            description:     'Requires a one-line comment directly above each continue statement. Advisory by default; opt in to stricter enforcement via .gruff.yaml.',
        );
    }

    /**
     * Find continue statements without a direct explanatory comment.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for undocumented continue statements.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder     = new NodeFinder();
        $findings   = [];

        foreach ($finder->findInstanceOf($unit->statements, Continue_::class) as $continue) {
            if (DirectLineComment::existsAbove($unit, $continue->getStartLine())) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     'continue statement must have a one-line comment directly above it.',
                filePath:    $unit->file->displayPath,
                line:        $continue->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                remediation: 'Add a short comment immediately above the continue explaining why the loop skips the remaining work.',
            );
        }

        return $findings;
    }
}
