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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Detects statements that cannot run after a terminating control-flow statement.
 */
final readonly class UnreachableCodeRule implements RuleInterface
{
    /**
     * Stable rule identifier for unreachable code findings.
     */
    public const ID = 'waste.unreachable-code';

    /**
     * Describe the unreachable code rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * Find statements that appear after a terminating statement in function-like bodies.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     * @return list<Finding> Findings for unreachable statements.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $functions = NodeIndex::nodesOfAny($analysisUnit, [Stmt\ClassMethod::class, Stmt\Function_::class, Expr\Closure::class]);

        $findings = [];

        foreach ($functions as $fn) {
            /** @var Stmt\ClassMethod|Stmt\Function_|Expr\Closure $fn Finder predicate restricts results to executable function-like nodes. */
            $this->checkBlock($fn->stmts ?? [], $analysisUnit, $findings);
        }

        return $findings;
    }

    /**
     * @param array<Node\Stmt> $stmts
     * @param list<Finding>    &$findings
     *
     * @return void
     */
    private function checkBlock(array $stmts, AnalysisUnit $analysisUnit, array &$findings): void
    {
        $definition = $this->definition();
        $terminated = false;

        foreach ($stmts as $stmt) {
            if ($terminated && $stmt->getStartLine() > 0) {
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

                return;
            }

            if ($this->isTerminating($stmt)) {
                $terminated = true;
            }

            $this->walkChildren($stmt, $analysisUnit, $findings);
        }
    }

    /**
     * @param list<Finding> &$findings
     *
     * @return void
     */
    private function walkChildren(Node\Stmt $node, AnalysisUnit $analysisUnit, array &$findings): void
    {
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $this->checkBlock($block->statements, $analysisUnit, $findings);
        }
    }

    /**
     * Detect statements that terminate control flow for the enclosing block.
     *
     * @return bool True when no following sibling statement can execute.
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
