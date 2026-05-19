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
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

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

        $nodeFinder = new NodeFinder();
        $nodes      = $nodeFinder->find($analysisUnit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod
                || $node instanceof Function_;
        });

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
        return match (true) {
            $node instanceof Stmt\If_ => self::walkIf($node),
            $node instanceof Stmt\Switch_ => self::walkSwitch($node),
            $node instanceof Stmt\For_ => self::walkBlock($node->stmts) + 1,
            $node instanceof Stmt\Foreach_ => self::walkBlock($node->stmts) + 1,
            $node instanceof Stmt\While_ => self::walkBlock($node->stmts) + 1,
            $node instanceof Stmt\Do_ => self::walkBlock($node->stmts) + 1,
            $node instanceof Stmt\TryCatch => self::walkTryCatch($node),
            default => 1,
        };
    }

    /**
     * Sum the NPath contributions of an `if` chain (if + elseifs + else, plus boolean-condition expansion).
     *
     * @return int
     */
    private static function walkIf(Stmt\If_ $node): int
    {
        $paths = self::walkBlock($node->stmts) + self::countConditionPaths($node->cond);

        foreach ($node->elseifs as $elseif) {
            $paths += self::walkBlock($elseif->stmts) + self::countConditionPaths($elseif->cond);
        }

        if ($node->else !== null) {
            return $paths + self::walkBlock($node->else->stmts);
        }

        return $paths + 1;
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

    /**
     * Sum the NPath contributions of a try / catch block (each catch arm adds its own paths).
     *
     * @return int
     */
    private static function walkTryCatch(Stmt\TryCatch $node): int
    {
        $paths = self::walkBlock($node->stmts);

        foreach ($node->catches as $catch) {
            $paths += self::walkBlock($catch->stmts);
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
