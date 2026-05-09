<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Waste;

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
use PhpParser\NodeFinder;

final readonly class EmptyClassRule implements RuleInterface
{
    public const ID = 'waste.empty-class';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Empty class',
            pillar: Pillar::DeadCode,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $classes = $finder->findInstanceOf($unit->statements, Class_::class);

        $findings = [];

        foreach ($classes as $class) {
            if ($class->isAbstract() || $class->isAnonymous()) {
                continue;
            }

            if ($class->stmts !== []) {
                continue;
            }

            $symbol = $class->name?->toString() ?? sprintf('class@anonymous:%d', $class->getStartLine());

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf('%s is an empty class with no members.', $symbol),
                filePath: $unit->file->displayPath,
                line: $class->getStartLine(),
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                endLine: $class->getEndLine() > 0 ? $class->getEndLine() : null,
                symbol: $symbol,
                remediation: 'Add members or remove the class if it serves no purpose.',
            );
        }

        return $findings;
    }
}
