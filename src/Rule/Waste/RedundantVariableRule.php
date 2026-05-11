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
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class RedundantVariableRule implements RuleInterface
{
    public const ID = 'waste.redundant-variable';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Redundant variable',
            pillar: Pillar::DeadCode,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::High,
            description: 'Flags variables that only store a value immediately returned by the next statement, when the assignment and the return are the only two statements in their block.',
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $findings = [];
        $functions = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof Stmt\ClassMethod
                || $node instanceof Stmt\Function_
                || $node instanceof Closure;
        });

        foreach ($functions as $function) {
            /** @var Stmt\ClassMethod|Stmt\Function_|Closure $function */
            $this->checkBlock($function->stmts ?? [], $unit, $definition, $findings);
        }

        return $findings;
    }

    /**
     * @param array<Stmt> $statements
     * @param list<Finding> &$findings
     */
    private function checkBlock(array $statements, AnalysisUnit $unit, RuleDefinition $definition, array &$findings): void
    {
        $statements = array_values($statements);

        if (count($statements) === 2) {
            $this->flagRedundantPair($statements[0], $statements[1], $unit, $definition, $findings);
        }

        foreach ($statements as $statement) {
            $this->checkChildBlocks($statement, $unit, $definition, $findings);
        }
    }

    /**
     * @param list<Finding> &$findings
     */
    private function flagRedundantPair(Stmt $assignment, Stmt $return, AnalysisUnit $unit, RuleDefinition $definition, array &$findings): void
    {
        if (!$assignment instanceof Stmt\Expression || !$assignment->expr instanceof Assign) {
            return;
        }

        $assignedVariable = $assignment->expr->var;
        if (!$assignedVariable instanceof Variable || !is_string($assignedVariable->name)) {
            return;
        }

        if (!$return instanceof Stmt\Return_ || !$return->expr instanceof Variable) {
            return;
        }

        $returnedVariable = $return->expr;
        if (!is_string($returnedVariable->name) || $returnedVariable->name !== $assignedVariable->name) {
            return;
        }

        $findings[] = new Finding(
            ruleId: $definition->id,
            message: sprintf('Variable $%s is redundant because it is immediately returned.', $assignedVariable->name),
            filePath: $unit->file->displayPath,
            line: $assignment->getStartLine(),
            severity: $definition->defaultSeverity,
            pillar: $definition->pillar,
            tier: $definition->tier,
            confidence: $definition->confidence,
            endLine: $return->getStartLine(),
            symbol: '$' . $assignedVariable->name,
            remediation: sprintf('Return the assigned expression directly instead of storing it in $%s.', $assignedVariable->name),
            metadata: ['variable' => $assignedVariable->name],
        );
    }

    /**
     * @param list<Finding> &$findings
     */
    private function checkChildBlocks(Stmt $statement, AnalysisUnit $unit, RuleDefinition $definition, array &$findings): void
    {
        if ($statement instanceof Stmt\If_) {
            $this->checkBlock($statement->stmts, $unit, $definition, $findings);

            foreach ($statement->elseifs as $elseif) {
                $this->checkBlock($elseif->stmts, $unit, $definition, $findings);
            }

            if ($statement->else !== null) {
                $this->checkBlock($statement->else->stmts, $unit, $definition, $findings);
            }

            return;
        }

        if ($statement instanceof Stmt\For_
            || $statement instanceof Stmt\Foreach_
            || $statement instanceof Stmt\While_
            || $statement instanceof Stmt\Do_
        ) {
            $this->checkBlock($statement->stmts, $unit, $definition, $findings);

            return;
        }

        if ($statement instanceof Stmt\Switch_) {
            foreach ($statement->cases as $case) {
                $this->checkBlock($case->stmts, $unit, $definition, $findings);
            }

            return;
        }

        if ($statement instanceof Stmt\TryCatch) {
            $this->checkBlock($statement->stmts, $unit, $definition, $findings);

            foreach ($statement->catches as $catch) {
                $this->checkBlock($catch->stmts, $unit, $definition, $findings);
            }

            if ($statement->finally !== null) {
                $this->checkBlock($statement->finally->stmts, $unit, $definition, $findings);
            }
        }
    }
}
