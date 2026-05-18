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
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

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
     * @return RuleDefinition Rule metadata and default thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                       self::ID,
            name:                     'Maximum nesting depth',
            pillar:                   Pillar::Complexity,
            tier:                     RuleTier::V01,
            defaultSeverity:          Severity::Error,
            confidence:               Confidence::High,
            severityThreshold: new SeverityThreshold(5, Severity::Error),
        );
    }

    /**
     * Detect functions and methods whose control flow nests too deeply.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Nesting-depth findings for the analysed unit.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings   = $context->settingsFor($definition);

        $finder = new NodeFinder();
        $nodes  = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod
                || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $maxDepth       = self::computeMaximumNestingDepth($node);
            $thresholdMatch = $settings->highValueThresholdMatch($maxDepth);

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
                filePath:         $unit->file->displayPath,
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
     * @param ClassMethod|Function_ $node
     * @return int The maximum nesting depth inside the function-like node.
     */
    public static function computeMaximumNestingDepth(Node $node): int
    {
        return self::walkStatements($node->stmts ?? [], 0);
    }

    /**
     * @param array<Node> $stmts
     * @return int The maximum nesting depth inside the statement list.
     */
    private static function walkStatements(array $stmts, int $depth): int
    {
        $maximumDepth = $depth;

        foreach ($stmts as $stmt) {
            $maximumDepth = max($maximumDepth, self::walkNode($stmt, $depth));
        }

        return $maximumDepth;
    }

    /**
     * Measure nesting contribution for a statement node.
     *
     * @return int The maximum nested depth reached from this node.
     */
    private static function walkNode(Node $node, int $depth): int
    {
        return match (true) {
            $node instanceof Stmt\If_ => self::walkIf($node, $depth),
            $node instanceof Stmt\For_ => self::walkStatements($node->stmts, $depth + 1),
            $node instanceof Stmt\Foreach_ => self::walkStatements($node->stmts, $depth + 1),
            $node instanceof Stmt\While_ => self::walkStatements($node->stmts, $depth + 1),
            $node instanceof Stmt\Do_ => self::walkStatements($node->stmts, $depth + 1),
            $node instanceof Stmt\Switch_ => self::walkSwitch($node, $depth),
            $node instanceof Stmt\TryCatch => self::walkTryCatch($node, $depth),
            $node instanceof Stmt\Expression => self::walkExprNesting($node->expr, $depth),
            default => $depth,
        };
    }

    /**
     * Measure the deepest branch inside an if/elseif/else chain.
     *
     * @return int The maximum branch nesting depth.
     */
    private static function walkIf(Stmt\If_ $node, int $depth): int
    {
        $inner        = $depth + 1;
        $maximumDepth = self::walkStatements($node->stmts, $inner);

        foreach ($node->elseifs as $elseif) {
            $maximumDepth = max($maximumDepth, self::walkStatements($elseif->stmts, $inner));
        }

        if ($node->else !== null) {
            $maximumDepth = max($maximumDepth, self::walkStatements($node->else->stmts, $inner));
        }

        return $maximumDepth;
    }

    /**
     * Measure the deepest branch inside a switch statement.
     *
     * @return int The maximum switch-case nesting depth.
     */
    private static function walkSwitch(Stmt\Switch_ $node, int $depth): int
    {
        $maximumDepth = $depth + 1;

        foreach ($node->cases as $case) {
            $maximumDepth = max($maximumDepth, self::walkStatements($case->stmts, $depth + 1));
        }

        return $maximumDepth;
    }

    /**
     * Measure try/catch/finally nesting without penalising the try body itself.
     *
     * @return int The maximum exception-handling nesting depth.
     */
    private static function walkTryCatch(Stmt\TryCatch $node, int $depth): int
    {
        $maximumDepth = self::walkStatements($node->stmts, $depth);

        foreach ($node->catches as $catch) {
            $maximumDepth = max($maximumDepth, self::walkStatements($catch->stmts, $depth + 1));
        }

        if ($node->finally !== null) {
            $maximumDepth = max($maximumDepth, self::walkStatements($node->finally->stmts, $depth));
        }

        return $maximumDepth;
    }

    /**
     * Measure nested closures inside expression statements.
     *
     * @return int The maximum expression nesting depth.
     */
    private static function walkExprNesting(Expr $expr, int $depth): int
    {
        if ($expr instanceof Closure) {
            return self::walkStatements($expr->stmts ?? [], $depth + 1);
        }

        $maximumDepth = $depth;

        foreach ($expr->getSubNodeNames() as $name) {
            $subExpression = $expr->$name;

            if ($subExpression instanceof Expr) {
                $maximumDepth = max($maximumDepth, self::walkExprNesting($subExpression, $depth));
            }
        }

        return $maximumDepth;
    }

    /**
     * Render a configured numeric threshold for finding messages.
     *
     * @return string The threshold without unnecessary decimal places.
     */
    private static function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
