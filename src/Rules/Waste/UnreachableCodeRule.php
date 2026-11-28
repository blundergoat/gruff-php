<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Waste;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use GruffPhp\Rules\Shared\StmtChildVisitor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Reports the first positioned, non-Nop statement after a direct `return`, `throw`, or `exit`/`die` in each scanned block.
 *
 * Methods, named functions, and closures are inspected; supported child control-flow blocks are scanned independently.
 * Each block stops when it adds a finding; termination is not propagated between blocks.
 */
final readonly class UnreachableCodeRule implements RuleInterface
{
    /**
     * Stable rule identifier for unreachable code findings.
     */
    public const ID = 'waste.unreachable-code';

    /**
     * Describes the unreachable-code rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning severity: a statement after a return/throw/exit is a real logic error, not a style nit.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unreachable code',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Scans method, named-function, and closure bodies for the first reportable statement after a terminator in each block.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for the first reportable statement after a terminator in each scanned block; empty if none are found.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $functions = NodeIndex::nodesOfAny($analysisUnit, [Stmt\ClassMethod::class, Stmt\Function_::class, Expr\Closure::class]);

        $findings = [];


        foreach ($functions as $fn) {
            /** @var Stmt\ClassMethod|Stmt\Function_|Expr\Closure $fn - NodeIndex selects methods, named functions, and closures. */
            $this->checkBlock($fn->stmts ?? [], $analysisUnit, $findings);
        }

        return $findings;
    }

    /**
     * Reports the first positioned, non-Nop statement after a direct terminator in this block.
     *
     * Child findings do not stop the parent; once this block reports, the reported statement's children and later siblings are skipped.
     *
     * @param array<Node\Stmt> $stmts - Sibling statements of a single block, in source order.
     * @param AnalysisUnit     $analysisUnit - Unit supplying the display path stamped onto any finding.
     * @param list<Finding>    &$findings - Accumulator the caller owns; appended to in place, never reset.
     *
     * @return void - Appends findings to the supplied accumulator without clearing existing entries.
     */
    private function checkBlock(array $stmts, AnalysisUnit $analysisUnit, array &$findings): void
    {
        $definition = $this->definition();
        $terminated = false;


        foreach ($stmts as $stmt) {
            // A positioned statement after a terminator is unreachable; a Nop holds only a trailing comment or a lone semicolon.
            if ($terminated && !$stmt instanceof Stmt\Nop && $stmt->getStartLine() > 0) {
                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     'Unreachable code after terminating statement.',
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $stmt->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    endLine:     $stmt->getEndLine() > 0 ? $stmt->getEndLine() : null,
                    remediation: 'Remove dead code or fix the control flow.',
                );

                // Stop at the first unreachable statement; the rest of this block is the same dead region.
                return;
            }

            if ($this->isTerminating($stmt)) {
                $terminated = true;
            }

            $this->walkChildren($stmt, $analysisUnit, $findings);
        }
    }

    /**
     * Scans the control-flow child blocks exposed by StmtChildVisitor independently of the enclosing block.
     *
     * Termination inside a child block is not propagated to its enclosing or sibling blocks.
     *
     * @param Node\Stmt     $node - Statement whose child blocks (via StmtChildVisitor) get scanned.
     * @param AnalysisUnit  $analysisUnit - Unit forwarded unchanged so nested findings carry the same path.
     * @param list<Finding> &$findings - Accumulator the caller owns; nested findings are appended in place.
     *
     * @return void - Appends findings from supported child blocks to the existing accumulator.
     */
    private function walkChildren(Node\Stmt $node, AnalysisUnit $analysisUnit, array &$findings): void
    {

        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $this->checkBlock($block->statements, $analysisUnit, $findings);
        }
    }

    /**
     * Recognizes direct return statements and standalone exit, die, or throw expressions as terminators.
     *
     * Other jumps and composite control flow are deliberately left unclassified, so some unreachable code is not reported.
     *
     * @param Node $stmt - Statement to classify; non-statement nodes simply fall through to false.
     *
     * @return bool - True for Return_ or an expression statement containing Exit_ or Throw_; false for all other nodes.
     */
    private function isTerminating(Node $stmt): bool
    {
        if ($stmt instanceof Stmt\Return_) {
            return true;
        }


        if ($stmt instanceof Stmt\Expression) {
            $expr = $stmt->expr;

            return $expr instanceof Expr\Exit_
                || $expr instanceof Expr\Throw_;
        }


        return false;
    }
}
