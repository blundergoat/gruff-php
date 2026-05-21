<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Waste;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use GruffPhp\Rule\StmtChildVisitor;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;

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
        $findings   = [];
        $functions  = NodeIndex::nodesOfAny($analysisUnit, [Stmt\ClassMethod::class, Stmt\Function_::class, Closure::class]);

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
        foreach (StmtChildVisitor::childBlocks($statement) as $block) {
            $this->checkBlock($block->statements, $analysisUnit, $definition, $findings);
        }
    }
}
