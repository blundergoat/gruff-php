<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Complexity;

use GruffPhp\Config\SeverityThreshold;
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
use GruffPhp\Rule\StmtChildBlock;
use GruffPhp\Rule\StmtChildVisitor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Measures the number of independent execution paths through function-like bodies.
 */
final readonly class NpathComplexityRule implements RuleInterface
{
    /**
     * Stable rule identifier for NPath complexity findings.
     */
    public const ID = 'complexity.npath';

    /**
     * Upper bound used to keep path-count multiplication finite.
     */
    private const MAX_NPATH = 100_000;

    /**
     * Describe the rule for the registry and reports.
     *
     * @return RuleDefinition
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'NPath complexity',
            pillar:            Pillar::Complexity,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(200, Severity::Error),
        );
    }

    /**
     * Flag methods whose NPath complexity (independent execution paths) exceeds the configured threshold.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding>
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $npath          = self::computeNpathComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($npath);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol     = CyclomaticComplexityRule::resolveSymbol($node);
            $capped     = $npath >= self::MAX_NPATH;
            $npathLabel = $capped ? '>=' . self::formatNumber(self::MAX_NPATH) . ' (cap reached)' : self::formatNumber($npath);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has an NPath complexity of %s, above the %s threshold of %s.',
                    $symbol,
                    $npathLabel,
                    $thresholdMatch->severity->value,
                    self::formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $node->getStartLine(),
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol:           $symbol,
                remediation:      'Reduce the number of independent execution paths by simplifying conditionals.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'npath' => $npath,
                    'capped' => $capped,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node
     * @return int NPath complexity score for the function-like node.
     */
    public static function computeNpathComplexity(Node $node): int
    {
        return self::walkBlock($node->stmts ?? []);
    }

    /**
     * @param array<Node> $stmts
     * @return int NPath complexity score for the statement list.
     */
    private static function walkBlock(array $stmts): int
    {
        $npath = 1;

        foreach ($stmts as $stmt) {
            $npath = min($npath * self::walkStatement($stmt), self::MAX_NPATH);
        }

        return $npath;
    }

    /**
     * Dispatch a statement node to the NPath handler matching its control-flow shape.
     *
     * @return int The NPath contribution of this statement.
     */
    private static function walkStatement(Node $node): int
    {
        if (!StmtChildVisitor::isControlFlowStmt($node)) {
            return 1;
        }

        if ($node instanceof Stmt\If_) {
            return self::walkIf($node);
        }

        if ($node instanceof Stmt\Switch_) {
            return self::walkSwitch($node);
        }

        if ($node instanceof Stmt\TryCatch) {
            return self::walkTryCatch($node);
        }

        // For / Foreach / While / Do: one loop-body block at +1.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            return self::walkBlock($block->statements) + 1;
        }

        return 1;
    }

    /**
     * Sum the NPath contributions of an `if` chain (if + elseifs + else, plus boolean-condition expansion).
     *
     * @return int
     */
    private static function walkIf(Stmt\If_ $node): int
    {
        $paths   = self::countConditionPaths($node->cond);
        $hasElse = false;

        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $paths += self::walkBlock($block->statements);

            if ($block->kind === StmtChildBlock::KIND_ELSEIF_BODY) {
                /** @var Stmt\ElseIf_ $owner Block kind discriminator narrows the owner so ->cond is accessible. */
                $owner = $block->owner;
                $paths += self::countConditionPaths($owner->cond);
            }

            if ($block->kind === StmtChildBlock::KIND_ELSE_BODY) {
                $hasElse = true;
            }
        }

        if (!$hasElse) {
            $paths += 1;
        }

        return $paths;
    }

    /**
     * Sum the NPath contributions of a `switch` statement; each case body adds its own path count plus an implicit default.
     *
     * @return int
     */
    private static function walkSwitch(Stmt\Switch_ $node): int
    {
        $paths      = 0;
        $hasDefault = false;

        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $paths += max(1, self::walkBlock($block->statements));

            /** @var Stmt\Case_ $owner Block kind discriminator narrows the owner so ->cond is accessible. */
            $owner = $block->owner;
            if ($owner->cond === null) {
                $hasDefault = true;
            }
        }

        if (!$hasDefault) {
            $paths += 1;
        }

        return max(1, $paths);
    }

    /**
     * Sum the NPath contributions of a try / catch block (each catch arm adds its own paths).
     *
     * @return int
     */
    private static function walkTryCatch(Stmt\TryCatch $node): int
    {
        $paths = 0;

        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            if ($block->kind === StmtChildBlock::KIND_TRY_BODY
                || $block->kind === StmtChildBlock::KIND_CATCH_BODY
            ) {
                $paths += self::walkBlock($block->statements);
            }
        }

        return max(1, $paths);
    }

    /**
     * Count the boolean-operator paths in a condition expression (`a && b` adds 1, `a && b || c` adds 2, etc.).
     *
     * @return int
     */
    private static function countConditionPaths(Expr $expr): int
    {
        if ($expr instanceof BinaryOp\BooleanAnd
            || $expr instanceof BinaryOp\BooleanOr
            || $expr instanceof BinaryOp\LogicalAnd
            || $expr instanceof BinaryOp\LogicalOr
        ) {
            return 1 + self::countConditionPaths($expr->left) + self::countConditionPaths($expr->right);
        }

        return 0;
    }

    /**
     * Format an NPath value with thousands separators; preserves fractional values that are not whole.
     *
     * @return string
     */
    private static function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return number_format((int) $number);
    }
}
