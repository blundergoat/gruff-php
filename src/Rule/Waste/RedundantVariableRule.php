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

/**
 * Detects temporary variables that only hold a value immediately returned by the same block.
 */
final readonly class RedundantVariableRule implements RuleInterface
{
    /**
     * Stable rule identifier for redundant variable findings.
     */
    public const ID = 'waste.redundant-variable';

    /**
     * Describe the redundant variable waste rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Redundant variable',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            description:     'Flags variables that only store a value immediately returned by the next statement, when the assignment and the return are the only two statements in their block.',
        );
    }

    /**
     * Find temporary variables that are immediately returned.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     * @return list<Finding> Findings for redundant return variables.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $findings   = [];
        $functions  = $nodeFinder->find($analysisUnit->statements, static function (Node $node): bool {
            return $node instanceof Stmt\ClassMethod
                || $node instanceof Stmt\Function_
                || $node instanceof Closure;
        });

        foreach ($functions as $function) {
            /** @var Stmt\ClassMethod|Stmt\Function_|Closure $function Finder predicate restricts results to function-like nodes. */
            $this->checkBlock($function->stmts ?? [], $analysisUnit, $definition, $findings);
        }

        return $findings;
    }

    /**
     * @param array<Stmt>   $statements
     * @param list<Finding> &$findings
     * @return void
     */
    private function checkBlock(array $statements, AnalysisUnit $analysisUnit, RuleDefinition $definition, array &$findings): void
    {
        $statements = array_values($statements);

        if (count($statements) === 2) {
            $this->flagRedundantPair(
                assignment:      $statements[0],
                returnStatement: $statements[1],
                analysisUnit:            $analysisUnit,
                definition:      $definition,
                findings:        $findings,
            );
        }

        foreach ($statements as $statement) {
            $this->checkChildBlocks($statement, $analysisUnit, $definition, $findings);
        }
    }

    /**
     * @param list<Finding> &$findings
     * @return void
     */
    private function flagRedundantPair(Stmt $assignment, Stmt $returnStatement, AnalysisUnit $analysisUnit, RuleDefinition $definition, array &$findings): void
    {
        if (!$assignment instanceof Stmt\Expression || !$assignment->expr instanceof Assign) {
            return;
        }

        $assignedVariable = $assignment->expr->var;
        if (!$assignedVariable instanceof Variable || !is_string($assignedVariable->name)) {
            return;
        }

        if (!$returnStatement instanceof Stmt\Return_ || !$returnStatement->expr instanceof Variable) {
            return;
        }

        $returnedVariable = $returnStatement->expr;
        if (!is_string($returnedVariable->name) || $returnedVariable->name !== $assignedVariable->name) {
            return;
        }

        $findings[] = new Finding(
            ruleId:      $definition->id,
            message:     sprintf('Variable $%s is redundant because it is immediately returned.', $assignedVariable->name),
            filePath:    $analysisUnit->file->displayPath,
            line:        $assignment->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            endLine:     $returnStatement->getStartLine(),
            symbol:      '$' . $assignedVariable->name,
            remediation: sprintf('Return the assigned expression directly instead of storing it in $%s.', $assignedVariable->name),
            metadata:    ['variable' => $assignedVariable->name],
        );
    }

    /**
     * @param list<Finding> &$findings
     * @return void
     */
    private function checkChildBlocks(Stmt $statement, AnalysisUnit $analysisUnit, RuleDefinition $definition, array &$findings): void
    {
        if ($statement instanceof Stmt\If_) {
            $this->checkBlock($statement->stmts, $analysisUnit, $definition, $findings);

            foreach ($statement->elseifs as $elseif) {
                $this->checkBlock($elseif->stmts, $analysisUnit, $definition, $findings);
            }

            if ($statement->else !== null) {
                $this->checkBlock($statement->else->stmts, $analysisUnit, $definition, $findings);
            }

            return;
        }

        if ($statement instanceof Stmt\For_
            || $statement instanceof Stmt\Foreach_
            || $statement instanceof Stmt\While_
            || $statement instanceof Stmt\Do_
        ) {
            $this->checkBlock($statement->stmts, $analysisUnit, $definition, $findings);

            return;
        }

        if ($statement instanceof Stmt\Switch_) {
            foreach ($statement->cases as $case) {
                $this->checkBlock($case->stmts, $analysisUnit, $definition, $findings);
            }

            return;
        }

        if ($statement instanceof Stmt\TryCatch) {
            $this->checkBlock($statement->stmts, $analysisUnit, $definition, $findings);

            foreach ($statement->catches as $catch) {
                $this->checkBlock($catch->stmts, $analysisUnit, $definition, $findings);
            }

            if ($statement->finally !== null) {
                $this->checkBlock($statement->finally->stmts, $analysisUnit, $definition, $findings);
            }
        }
    }
}
