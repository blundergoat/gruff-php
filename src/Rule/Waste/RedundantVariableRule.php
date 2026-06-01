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
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory, not warning: an assign-then-return is legible, so flag it as a cleanup hint a team opts into.
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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per assign-then-return pair found across every function-like body.
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
     * Inspect a single statement list, then recurse into the child blocks of each statement.
     *
     * The pattern is only flagged when the assignment and the return are the block's *only* two
     * statements (count === 2); a variable used more than once first is not redundant. Recursion still
     * visits nested blocks so the same two-statement shape inside an inner scope is caught.
     *
     * @param array<Stmt>    $statements - Sibling statements of one block, in source order.
     * @param AnalysisUnit   $analysisUnit - Unit supplying the display path stamped onto any finding.
     * @param RuleDefinition $definition - Pre-resolved metadata reused for every finding this pass.
     * @param list<Finding>  &$findings - Accumulator the caller owns; appended to in place, never reset.
     *
     * @return void
     */
    private function checkBlock(array $statements, AnalysisUnit $analysisUnit, RuleDefinition $definition, array &$findings): void
    {
        $statements = array_values($statements);

        if (count($statements) === 2) {
            $this->flagRedundantPair(
                assignment:      $statements[0],
                returnStatement: $statements[1],
                analysisUnit:    $analysisUnit,
                definition:      $definition,
                findings:        $findings,
            );
        }

        foreach ($statements as $statement) {
            $this->checkChildBlocks($statement, $analysisUnit, $definition, $findings);
        }
    }

    /**
     * Append a finding when the two given statements are exactly "assign $x" then "return $x".
     *
     * Every guard below is a precondition the caller must have satisfied for the pair to qualify; any
     * unmet guard means this is not the redundant shape and the method exits without recording anything.
     * A `@var`/`@phpstan-var` narrowing docblock on either statement is treated as load-bearing and
     * suppresses the finding, because inlining the return would drop that type contract.
     *
     * @param Stmt           $assignment - First statement; must be an expression wrapping an assignment.
     * @param Stmt           $returnStatement - Second statement; must return the same bare variable.
     * @param AnalysisUnit   $analysisUnit - Unit supplying the display path stamped onto the finding.
     * @param RuleDefinition $definition - Pre-resolved metadata copied into the finding.
     * @param list<Finding>  &$findings - Accumulator the caller owns; appended to in place, never reset.
     *
     * @return void
     */
    private function flagRedundantPair(Stmt $assignment, Stmt $returnStatement, AnalysisUnit $analysisUnit, RuleDefinition $definition, array &$findings): void
    {
        if (!$assignment instanceof Stmt\Expression || !$assignment->expr instanceof Assign) {
            // Not an assignment statement, so there is no temporary variable to collapse.
            return;
        }

        $assignedVariable = $assignment->expr->var;
        if (!$assignedVariable instanceof Variable || !is_string($assignedVariable->name)) {
            // Assigning to a property/array element/etc., not a plain `$name`, so the pattern does not apply.
            return;
        }

        if (!$returnStatement instanceof Stmt\Return_ || !$returnStatement->expr instanceof Variable) {
            // Second statement is not `return <variable>`, so it cannot be returning the just-assigned temp.
            return;
        }

        $returnedVariable = $returnStatement->expr;
        if (!is_string($returnedVariable->name) || $returnedVariable->name !== $assignedVariable->name) {
            // Returns a different variable than the one assigned, so neither line is redundant.
            return;
        }

        if ($this->hasPhpStanNarrowingTag($assignment, $returnStatement, $assignedVariable->name)) {
            // A type-narrowing docblock makes the temp load-bearing; inlining would lose the pinned type.
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
     * Re-run the block check on each nested block a statement contains (if/else arms, loop and try
     * bodies), so the assign-then-return pattern is detected at every depth, not just top level.
     *
     * @param Stmt           $statement - Statement whose child blocks (via StmtChildVisitor) get scanned.
     * @param AnalysisUnit   $analysisUnit - Unit forwarded unchanged so nested findings carry the same path.
     * @param RuleDefinition $definition - Pre-resolved metadata forwarded to the recursive check.
     * @param list<Finding>  &$findings - Accumulator the caller owns; nested findings are appended in place.
     *
     * @return void
     */
    private function checkChildBlocks(Stmt $statement, AnalysisUnit $analysisUnit, RuleDefinition $definition, array &$findings): void
    {
        foreach (StmtChildVisitor::childBlocks($statement) as $block) {
            $this->checkBlock($block->statements, $analysisUnit, $definition, $findings);
        }
    }

    /**
     * Detect when a `@var`/`@phpstan-var`/`@psalm-var` docblock on the assignment or
     * return statement narrows the variable's type. The intermediate variable then
     * carries a type-system contract that bare return-of-expression would lose, so
     * the redundant-variable finding must be suppressed for that assign.
     *
     * @param Stmt   $assignment - Statement holding the assignment expression.
     * @param Stmt   $returnStatement - Following return statement.
     * @param string $variableName - Bare variable name being assigned and returned.
     *
     * @return bool - True when the docblock pins a type for the variable.
     */
    private function hasPhpStanNarrowingTag(Stmt $assignment, Stmt $returnStatement, string $variableName): bool
    {
        $pattern = '/@(?:var|phpstan-var|psalm-var)\s+\S+\s+\$' . preg_quote($variableName, '/') . '\b/';

        foreach ([$returnStatement, $assignment] as $statement) {
            $docComment = $statement->getDocComment();
            // Match a narrowing PHPDoc tag for the exact temporary variable, not another variable with the same prefix.
            if ($docComment !== null && preg_match($pattern, $docComment->getText()) === 1) {
                // Found a `@var $name` narrowing on this statement, so the temp carries a real type contract.
                return true;
            }
        }

        // Neither statement pins the variable's type, so collapsing the temp is safe.
        return false;
    }
}
