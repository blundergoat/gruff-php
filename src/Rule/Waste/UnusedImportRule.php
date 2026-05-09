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
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;

final readonly class UnusedImportRule implements RuleInterface
{
    public const ID = 'waste.unused-import';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Unused import',
            pillar: Pillar::DeadCode,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $uses = $finder->findInstanceOf($unit->statements, Use_::class);

        if ($uses === []) {
            return [];
        }

        /** @var list<Use_> $useStatements */
        $useStatements = $uses;
        $sourceWithoutUses = $this->removeUseStatements($unit->source, $useStatements);
        $findings = [];

        foreach ($useStatements as $use) {
            foreach ($use->uses as $useUse) {
                $alias = $useUse->getAlias()->toString();

                if ($this->isNameUsed($alias, $sourceWithoutUses)) {
                    continue;
                }

                $fullName = $useUse->name->toString();

                $findings[] = new Finding(
                    ruleId: $definition->id,
                    message: sprintf('Import %s is unused.', $fullName),
                    filePath: $unit->file->displayPath,
                    line: $use->getStartLine(),
                    severity: $definition->defaultSeverity,
                    pillar: $definition->pillar,
                    tier: $definition->tier,
                    confidence: $definition->confidence,
                    symbol: $alias,
                    remediation: 'Remove the unused import statement.',
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<Use_> $uses
     */
    private function removeUseStatements(string $source, array $uses): string
    {
        $lines = explode("\n", $source);

        foreach ($uses as $use) {
            $startLine = $use->getStartLine();
            $endLine = $use->getEndLine();

            if ($startLine > 0 && $endLine > 0) {
                for ($i = $startLine - 1; $i < $endLine; $i++) {
                    if (isset($lines[$i])) {
                        $lines[$i] = '';
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    private function isNameUsed(string $alias, string $source): bool
    {
        return preg_match('/\b' . preg_quote($alias, '/') . '\b/', $source) === 1;
    }
}
