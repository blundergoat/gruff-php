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
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * Detects return statements that lack an immediately preceding explanatory comment.
 */
final readonly class ReturnCommentRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing return comment findings.
     */
    public const ID = 'docs.return-comment';

    /**
     * Describe the return-comment documentation rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Return comment',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            description:     'Requires a one-line comment directly above each return statement, so a reviewer can verify why that value or early exit is returned. Advisory by default; opt in to stricter enforcement via .gruff-php.yaml.',
        );
    }

    /**
     * Find return statements without a direct explanatory comment.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for undocumented return statements.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder     = new NodeFinder();
        $findings   = [];

        foreach ($finder->findInstanceOf($unit->statements, Return_::class) as $return) {
            if (DirectLineComment::hasCommentAbove($unit, $return->getStartLine())) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     'return statement must have a one-line comment directly above it.',
                filePath:    $unit->file->displayPath,
                line:        $return->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                remediation: 'Add a short comment immediately above the return explaining why that value or early exit is returned.',
            );
        }

        return $findings;
    }
}
