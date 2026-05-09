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

final readonly class CyclomaticComplexityRule implements RuleInterface
{
    public const ID = 'complexity.cyclomatic';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Cyclomatic complexity',
            pillar: Pillar::Complexity,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 10,
                'error' => 20,
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
            $ccn = self::compute($node);

            if ($ccn <= $warningThreshold) {
                continue;
            }

            $severity = $ccn > $errorThreshold ? Severity::Error : Severity::Warning;
            $threshold = $severity === Severity::Error ? $errorThreshold : $warningThreshold;
            $symbol = self::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    '%s has a cyclomatic complexity of %d, above the %s threshold of %s.',
                    $symbol,
                    $ccn,
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
                remediation: 'Reduce branching by extracting conditions or splitting the method.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'complexity' => $ccn,
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
        $ccn = 1;

        $finder = new NodeFinder();
        $body = $node->stmts ?? [];

        $all = $finder->find($body, static function (Node $child): bool {
            if ($child instanceof Stmt\If_
                || $child instanceof Stmt\ElseIf_
                || $child instanceof Stmt\For_
                || $child instanceof Stmt\Foreach_
                || $child instanceof Stmt\While_
                || $child instanceof Stmt\Do_
                || $child instanceof Stmt\Catch_
            ) {
                return true;
            }

            if ($child instanceof Stmt\Case_ && $child->cond !== null) {
                return true;
            }

            if ($child instanceof BinaryOp\BooleanAnd
                || $child instanceof BinaryOp\BooleanOr
                || $child instanceof BinaryOp\LogicalAnd
                || $child instanceof BinaryOp\LogicalOr
                || $child instanceof BinaryOp\LogicalXor
            ) {
                return true;
            }

            if ($child instanceof Expr\Ternary) {
                return true;
            }

            if ($child instanceof BinaryOp\Coalesce) {
                return true;
            }

            return false;
        });

        $ccn += count($all);

        return $ccn;
    }

    public static function resolveSymbol(ClassMethod|Function_ $node): string
    {
        if ($node instanceof ClassMethod) {
            $parent = $node->getAttribute('parent');
            $className = $parent instanceof Stmt\Class_
                || $parent instanceof Stmt\Trait_
                || $parent instanceof Stmt\Enum_
                ? ($parent->name?->toString() ?? 'class@anonymous')
                : null;

            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }

        return $node->name->toString() . '()';
    }

    private static function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
