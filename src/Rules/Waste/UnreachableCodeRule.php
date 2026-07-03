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
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Find statements that appear after a terminating statement in function-like bodies.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Accumulated findings for statements past terminators; empty when every block can
     *                         fall through.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $functions = NodeIndex::nodesOfAny($analysisUnit, [Stmt\ClassMethod::class, Stmt\Function_::class, Expr\Closure::class]);

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($functions as $fn) {
            /** @var Stmt\ClassMethod|Stmt\Function_|Expr\Closure $fn Finder predicate restricts results to executable function-like nodes. */
            // User view: missing data becomes a safe findings list default.
            $this->checkBlock($fn->stmts ?? [], $analysisUnit, $findings);
        }

        return $findings;
    }

    /**
     * Scan one statement list left to right and flag the first statement that follows a terminator.
     *
     * Only the first unreachable statement in a block is reported (reporting every trailing statement
     * would be redundant noise once the block is known dead); child blocks are still recursed so nested
     * unreachable code is not missed.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param array<Node\Stmt> $stmts - Sibling statements of a single block, in source order.
     * @param AnalysisUnit     $analysisUnit - Unit supplying the display path stamped onto any finding.
     * @param list<Finding>    &$findings - Accumulator the caller owns; appended to in place, never reset.
     *
     * @return void
     */
    private function checkBlock(array $stmts, AnalysisUnit $analysisUnit, array &$findings): void
    {
        $definition = $this->definition();
        $terminated = false;

        // User view: add each item that can appear in findings list.
        foreach ($stmts as $stmt) {
            // User view: choose the findings list branch for this case.
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

                // Stop at the first unreachable statement; the rest of this block is the same dead region.
                return;
            }

            // User view: choose the findings list branch for this case.
            if ($this->isTerminating($stmt)) {
                $terminated = true;
            }

            $this->walkChildren($stmt, $analysisUnit, $findings);
        }
    }

    /**
     * Recurse into every nested block a statement owns (if/else arms, loop bodies, try/catch) so
     * reachability is evaluated independently inside each one. Reachability does not cross block
     * boundaries: a return inside an `if` does not make the statement after the `if` unreachable.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node\Stmt     $node - Statement whose child blocks (via StmtChildVisitor) get scanned.
     * @param AnalysisUnit  $analysisUnit - Unit forwarded unchanged so nested findings carry the same path.
     * @param list<Finding> &$findings - Accumulator the caller owns; nested findings are appended in place.
     *
     * @return void
     */
    private function walkChildren(Node\Stmt $node, AnalysisUnit $analysisUnit, array &$findings): void
    {
        // User view: add each item that can appear in findings list.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $this->checkBlock($block->statements, $analysisUnit, $findings);
        }
    }

    /**
     * Decide whether a statement ends the enclosing block so any sibling after it is unreachable.
     *
     * Deliberately conservative: only `return`, `exit`/`die`, and `throw` count. Control flow that
     * terminates only sometimes (break/continue/goto, or a match/if where every arm returns) is not
     * treated as terminating, so the rule under-reports rather than risk a false positive.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $stmt - Statement to classify; non-statement nodes simply fall through to false.
     *
     * @return bool - True when no following sibling statement can run after this one.
     */
    private function isTerminating(Node $stmt): bool
    {
        // User view: choose the findings list branch for this case.
        if ($stmt instanceof Stmt\Return_) {
            // A bare `return` hands control back to the caller, so nothing after it in this block runs.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if ($stmt instanceof Stmt\Expression) {
            $expr = $stmt->expr;

            // `exit`/`die` halts the process and `throw` unwinds the stack; either ends this block.
            return $expr instanceof Expr\Exit_
                || $expr instanceof Expr\Throw_;
        }

        // Any other statement may fall through, so treat the block as still reachable past it.
        return false;
    }
}
