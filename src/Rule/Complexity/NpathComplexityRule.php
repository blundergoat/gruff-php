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
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

final readonly class NpathComplexityRule implements RuleInterface
{
    public const ID = 'complexity.npath';

    private const MAX_NPATH = 100_000;

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'NPath complexity',
            pillar: Pillar::Complexity,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 200,
                'error' => 500,
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
            $npath = self::compute($node);

            if ($npath <= $warningThreshold) {
                continue;
            }

            $severity = $npath > $errorThreshold ? Severity::Error : Severity::Warning;
            $threshold = $severity === Severity::Error ? $errorThreshold : $warningThreshold;
            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    '%s has an NPath complexity of %d, above the %s threshold of %s.',
                    $symbol,
                    $npath,
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
                remediation: 'Reduce the number of independent execution paths by simplifying conditionals.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'npath' => $npath,
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
        return self::walkBlock($node->stmts ?? []);
    }

    /**
     * @param array<Node> $stmts
     */
    private static function walkBlock(array $stmts): int
    {
        $npath = 1;

        foreach ($stmts as $stmt) {
            $npath = self::clamp($npath * self::walkStatement($stmt));
        }

        return $npath;
    }

    private static function walkStatement(Node $node): int
    {
        if ($node instanceof Stmt\If_) {
            $paths = self::walkBlock($node->stmts) + self::countConditionPaths($node->cond);

            foreach ($node->elseifs as $elseif) {
                $paths += self::walkBlock($elseif->stmts) + self::countConditionPaths($elseif->cond);
            }

            if ($node->else !== null) {
                $paths += self::walkBlock($node->else->stmts);
            } else {
                $paths += 1; // implicit else
            }

            return $paths;
        }

        if ($node instanceof Stmt\Switch_) {
            $paths = 0;
            $hasDefault = false;

            foreach ($node->cases as $case) {
                $paths += max(1, self::walkBlock($case->stmts));

                if ($case->cond === null) {
                    $hasDefault = true;
                }
            }

            if (!$hasDefault) {
                $paths += 1;
            }

            return max(1, $paths);
        }

        if ($node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
        ) {
            return self::walkBlock($node->stmts) + 1;
        }

        if ($node instanceof Stmt\TryCatch) {
            $paths = self::walkBlock($node->stmts);

            foreach ($node->catches as $catch) {
                $paths += self::walkBlock($catch->stmts);
            }

            return max(1, $paths);
        }

        return 1;
    }

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

    private static function clamp(int $value): int
    {
        return min($value, self::MAX_NPATH);
    }

    private static function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
