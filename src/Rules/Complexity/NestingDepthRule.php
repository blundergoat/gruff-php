<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Complexity;

use GruffPhp\Engine\Config\SeverityThreshold;
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
use GruffPhp\Rules\Shared\StmtChildBlock;
use GruffPhp\Rules\Shared\StmtChildVisitor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Measures the deepest nested control-flow level inside function-like bodies.
 */
final readonly class NestingDepthRule implements RuleInterface
{
    /**
     * Stable rule identifier for nesting depth findings.
     */
    public const ID = 'complexity.nesting-depth';

    /**
     * Describe the nesting-depth rule for the registry and reports.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and default thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Maximum nesting depth',
            pillar:            Pillar::Complexity,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(4, Severity::Error),
        );
    }

    /**
     * Detect functions and methods whose control flow nests too deeply.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Nesting-depth findings for the analysed unit.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            // User view: choose the findings list branch for this case.
            if (!CyclomaticComplexityRule::hasExecutableBody($node)) {
                continue;
            }

            $maxDepth       = self::computeMaximumNestingDepth($node);
            $thresholdMatch = $settings->highValueThresholdMatch($maxDepth);

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has a maximum nesting depth of %d, above the %s threshold of %s.',
                    $symbol,
                    $maxDepth,
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
                remediation:      'Reduce nesting by using early returns, guard clauses, or extracting nested logic.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'depth' => $maxDepth,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_ $node - Function-like node whose body is measured.
     *
     * @return int - The maximum nesting depth inside the function-like node.
     */
    public static function computeMaximumNestingDepth(Node $node): int
    {
        // User view: missing data becomes a safe findings list default.
        return self::walkStatements($node->stmts ?? [], 0);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param array<Node> $stmts - Statements to measure in sequence.
     * @param int         $depth - Nesting depth this statement list sits at.
     *
     * @return int - The maximum nesting depth inside the statement list.
     */
    private static function walkStatements(array $stmts, int $depth): int
    {
        $maximumDepth = $depth;

        // User view: add each item that can appear in findings list.
        foreach ($stmts as $stmt) {
            $maximumDepth = max($maximumDepth, self::walkNode($stmt, $depth));
        }

        return $maximumDepth;
    }

    /**
     * Measure nesting contribution for a statement node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Statement node to measure.
     * @param int  $depth - Nesting depth this node sits at.
     *
     * @return int - The maximum nested depth reached from this node.
     */
    private static function walkNode(Node $node, int $depth): int
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof Stmt\Expression) {
            // An expression statement only deepens nesting through a closure it may contain.
            return self::walkExprNesting($node->expr, $depth);
        }

        // User view: choose the findings list branch for this case.
        if (!StmtChildVisitor::isControlFlowStmt($node)) {
            // A non-control-flow statement adds no nesting, so the depth is unchanged.
            return $depth;
        }

        // A switch construct contributes one nesting level even when it has no cases.
        $maximumDepth = $node instanceof Stmt\Switch_ ? $depth + 1 : $depth;

        // User view: add each item that can appear in findings list.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $blockDepth = match ($block->kind) {
                StmtChildBlock::KIND_TRY_BODY, StmtChildBlock::KIND_FINALLY_BODY => $depth,
                default => $depth + 1,
            };
            $maximumDepth = max($maximumDepth, self::walkStatements($block->statements, $blockDepth));
        }

        return $maximumDepth;
    }

    /**
     * Measure nested closures inside expression statements.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr $expr - Expression to measure; only a closure body adds nesting.
     * @param int  $depth - Nesting depth this expression sits at.
     *
     * @return int - The maximum expression nesting depth.
     */
    private static function walkExprNesting(Expr $expr, int $depth): int
    {
        // User view: choose the findings list branch for this case.
        if ($expr instanceof Closure) {
            // A closure body nests one level below the expression that holds it.
            // User view: missing data becomes a safe findings list default.
            return self::walkStatements($expr->stmts ?? [], $depth + 1);
        }

        $maximumDepth = $depth;

        // User view: add each item that can appear in findings list.
        foreach ($expr->getSubNodeNames() as $name) {
            $subExpression = $expr->$name;

            // User view: choose the findings list branch for this case.
            if ($subExpression instanceof Expr) {
                $maximumDepth = max($maximumDepth, self::walkExprNesting($subExpression, $depth));
            }
        }

        return $maximumDepth;
    }

    /**
     * Render a configured numeric threshold for finding messages.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param int|float $number - Threshold to render; a genuine fraction is kept, a whole value loses its ".0".
     *
     * @return string - The threshold without unnecessary decimal places.
     */
    private static function formatNumber(int|float $number): string
    {
        // User view: choose the findings list branch for this case.
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
