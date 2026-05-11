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

final readonly class ReturnCommentRule implements RuleInterface
{
    public const ID = 'docs.return-comment';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Return comment',
            pillar: Pillar::Documentation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            description: 'Requires a one-line comment directly above each return statement.',
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Return_::class) as $return) {
            if (DirectLineComment::existsAbove($unit, $return->getStartLine())) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: 'return statement must have a one-line comment directly above it.',
                filePath: $unit->file->displayPath,
                line: $return->getStartLine(),
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                remediation: 'Add a short comment immediately above the return explaining why that value or early exit is returned.',
            );
        }

        return $findings;
    }
}
