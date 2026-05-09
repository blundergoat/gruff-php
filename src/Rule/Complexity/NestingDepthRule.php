<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Complexity;

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

final readonly class NestingDepthRule implements RuleInterface
{
    public const ID = 'complexity.nesting-depth';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Maximum nesting depth',
            pillar: Pillar::Complexity,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 4,
                'error' => 6,
            ],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings = $context->settingsFor($definition);
        $warningThreshold = $settings->numericThreshold('warning');
        $errorThreshold = $settings->numericThreshold('error');

        $finder = new NodeFinder();
        $nodes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod
                || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node */
            $maxDepth = self::compute($node);

            if ($maxDepth <= $warningThreshold) {
                continue;
            }

            $severity = $maxDepth > $errorThreshold ? Severity::Error : Severity::Warning;
            $threshold = $severity === Severity::Error ? $errorThreshold : $warningThreshold;
            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    '%s has a maximum nesting depth of %d, above the %s threshold of %s.',
                    $symbol,
                    $maxDepth,
                    $severity->value,
                    self::formatNumber($threshold),
                ),
                filePath: $unit->file->displayPath,
                line: $node->getStartLine(),
                severity: $severity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                endLine: $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol: $symbol,
                remediation: 'Reduce nesting by using early returns, guard clauses, or extracting nested logic.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'depth' => $maxDepth,
                    'threshold' => $threshold,
                    'thresholdType' => $severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    public static function compute(Node $node): int
    {
        return self::walkStatements($node->stmts ?? [], 0);
    }

    /**
     * @param array<Node> $stmts
     */
    private static function walkStatements(array $stmts, int $depth): int
    {
        $max = $depth;

        foreach ($stmts as $stmt) {
            $max = max($max, self::walkNode($stmt, $depth));
        }

        return $max;
    }

    private static function walkNode(Node $node, int $depth): int
    {
        if ($node instanceof Stmt\If_) {
            $inner = $depth + 1;
            $max = self::walkStatements($node->stmts, $inner);

            foreach ($node->elseifs as $elseif) {
                $max = max($max, self::walkStatements($elseif->stmts, $inner));
            }

            if ($node->else !== null) {
                $max = max($max, self::walkStatements($node->else->stmts, $inner));
            }

            return $max;
        }

        if ($node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
        ) {
            return self::walkStatements($node->stmts, $depth + 1);
        }

        if ($node instanceof Stmt\Switch_) {
            $max = $depth + 1;

            foreach ($node->cases as $case) {
                $max = max($max, self::walkStatements($case->stmts, $depth + 1));
            }

            return $max;
        }

        if ($node instanceof Stmt\TryCatch) {
            $max = self::walkStatements($node->stmts, $depth);

            foreach ($node->catches as $catch) {
                $max = max($max, self::walkStatements($catch->stmts, $depth + 1));
            }

            if ($node->finally !== null) {
                $max = max($max, self::walkStatements($node->finally->stmts, $depth));
            }

            return $max;
        }

        if ($node instanceof Stmt\Expression) {
            return self::walkExprNesting($node->expr, $depth);
        }

        return $depth;
    }

    private static function walkExprNesting(Expr $expr, int $depth): int
    {
        if ($expr instanceof Closure) {
            return self::walkStatements($expr->stmts ?? [], $depth + 1);
        }

        $max = $depth;

        foreach ($expr->getSubNodeNames() as $name) {
            $sub = $expr->$name;

            if ($sub instanceof Expr) {
                $max = max($max, self::walkExprNesting($sub, $depth));
            }
        }

        return $max;
    }

    private static function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
