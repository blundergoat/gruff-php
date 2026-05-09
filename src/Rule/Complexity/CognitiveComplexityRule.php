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
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

final readonly class CognitiveComplexityRule implements RuleInterface
{
    public const ID = 'complexity.cognitive';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Cognitive complexity',
            pillar: Pillar::Complexity,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 15,
                'error' => 30,
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
            $cc = self::compute($node);

            if ($cc <= $warningThreshold) {
                continue;
            }

            $severity = $cc > $errorThreshold ? Severity::Error : Severity::Warning;
            $threshold = $severity === Severity::Error ? $errorThreshold : $warningThreshold;
            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    '%s has a cognitive complexity of %d, above the %s threshold of %s.',
                    $symbol,
                    $cc,
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
                remediation: 'Reduce nesting and extract complex conditions into named methods.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'complexity' => $cc,
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
        $body = $node->stmts ?? [];

        return self::walkStatements($body, 0);
    }

    /**
     * @param array<Node> $stmts
     */
    private static function walkStatements(array $stmts, int $nesting): int
    {
        $total = 0;

        foreach ($stmts as $stmt) {
            $total += self::walkNode($stmt, $nesting);
        }

        return $total;
    }

    private static function walkNode(Node $node, int $nesting): int
    {
        $total = 0;

        if ($node instanceof Stmt\If_) {
            $total += 1 + $nesting;
            $total += self::walkBooleanOperators($node->cond);
            $total += self::walkStatements($node->stmts, $nesting + 1);

            foreach ($node->elseifs as $elseif) {
                $total += 1; // elseif: +1, no nesting penalty
                $total += self::walkBooleanOperators($elseif->cond);
                $total += self::walkStatements($elseif->stmts, $nesting + 1);
            }

            if ($node->else !== null) {
                $total += 1; // else: +1, no nesting penalty
                $total += self::walkStatements($node->else->stmts, $nesting + 1);
            }

            return $total;
        }

        if ($node instanceof Stmt\Switch_) {
            $total += 1 + $nesting;

            foreach ($node->cases as $case) {
                $total += self::walkStatements($case->stmts, $nesting + 1);
            }

            return $total;
        }

        if ($node instanceof Stmt\For_) {
            $total += 1 + $nesting;
            $total += self::walkStatements($node->stmts, $nesting + 1);

            return $total;
        }

        if ($node instanceof Stmt\Foreach_) {
            $total += 1 + $nesting;
            $total += self::walkStatements($node->stmts, $nesting + 1);

            return $total;
        }

        if ($node instanceof Stmt\While_) {
            $total += 1 + $nesting;
            $total += self::walkBooleanOperators($node->cond);
            $total += self::walkStatements($node->stmts, $nesting + 1);

            return $total;
        }

        if ($node instanceof Stmt\Do_) {
            $total += 1 + $nesting;
            $total += self::walkBooleanOperators($node->cond);
            $total += self::walkStatements($node->stmts, $nesting + 1);

            return $total;
        }

        if ($node instanceof Stmt\TryCatch) {
            $total += self::walkStatements($node->stmts, $nesting);

            foreach ($node->catches as $catch) {
                $total += 1 + $nesting;
                $total += self::walkStatements($catch->stmts, $nesting + 1);
            }

            if ($node->finally !== null) {
                $total += self::walkStatements($node->finally->stmts, $nesting);
            }

            return $total;
        }

        if ($node instanceof Stmt\Break_ || $node instanceof Stmt\Continue_) {
            if ($node->num !== null) {
                $total += 1; // labeled break/continue: +1, no nesting
            }

            return $total;
        }

        if ($node instanceof Stmt\Goto_) {
            $total += 1;

            return $total;
        }

        if ($node instanceof Stmt\Expression) {
            return self::walkExprCognitive($node->expr, $nesting);
        }

        if ($node instanceof Stmt\Return_) {
            if ($node->expr !== null) {
                return self::walkExprCognitive($node->expr, $nesting);
            }

            return 0;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->$name;

            if ($sub instanceof Node) {
                $total += self::walkNode($sub, $nesting);
            } elseif (is_array($sub)) {
                foreach ($sub as $child) {
                    if ($child instanceof Node) {
                        $total += self::walkNode($child, $nesting);
                    }
                }
            }
        }

        return $total;
    }

    private static function walkExprCognitive(Expr $expr, int $nesting): int
    {
        $total = 0;

        if ($expr instanceof Expr\Ternary) {
            $total += 1 + $nesting;
            $total += self::walkExprCognitive($expr->cond, $nesting);

            if ($expr->if !== null) {
                $total += self::walkExprCognitive($expr->if, $nesting + 1);
            }

            $total += self::walkExprCognitive($expr->else, $nesting + 1);

            return $total;
        }

        if ($expr instanceof Closure) {
            $total += self::walkStatements($expr->stmts ?? [], $nesting + 1);

            return $total;
        }

        if ($expr instanceof Expr\ArrowFunction) {
            $total += self::walkExprCognitive($expr->expr, $nesting + 1);

            return $total;
        }

        foreach ($expr->getSubNodeNames() as $name) {
            $sub = $expr->$name;

            if ($sub instanceof Expr) {
                $total += self::walkExprCognitive($sub, $nesting);
            } elseif (is_array($sub)) {
                foreach ($sub as $child) {
                    if ($child instanceof Expr) {
                        $total += self::walkExprCognitive($child, $nesting);
                    }
                }
            }
        }

        return $total;
    }

    private static function walkBooleanOperators(Expr $expr): int
    {
        if (!$expr instanceof BinaryOp\BooleanAnd
            && !$expr instanceof BinaryOp\BooleanOr
            && !$expr instanceof BinaryOp\LogicalAnd
            && !$expr instanceof BinaryOp\LogicalOr
        ) {
            return 0;
        }

        $total = 0;
        $flat = [];
        self::flattenBooleanChain($expr, $flat);

        $lastOperatorClass = null;

        foreach ($flat as $operatorClass) {
            if ($operatorClass !== $lastOperatorClass) {
                $total++;
                $lastOperatorClass = $operatorClass;
            }
        }

        return $total;
    }

    /**
     * @param list<class-string> $result
     */
    private static function flattenBooleanChain(Expr $expr, array &$result): void
    {
        $isBoolOp = $expr instanceof BinaryOp\BooleanAnd
            || $expr instanceof BinaryOp\BooleanOr
            || $expr instanceof BinaryOp\LogicalAnd
            || $expr instanceof BinaryOp\LogicalOr;

        if (!$isBoolOp) {
            return;
        }

        /** @var BinaryOp $expr */
        self::flattenBooleanChain($expr->left, $result);
        $result[] = $expr::class;
        self::flattenBooleanChain($expr->right, $result);
    }

    private static function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
