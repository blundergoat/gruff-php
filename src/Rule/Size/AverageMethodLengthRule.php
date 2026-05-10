<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Size;

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
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

final readonly class AverageMethodLengthRule implements RuleInterface
{
    public const ID = 'size.average-method-length';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Average method length',
            pillar: Pillar::Size,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 20,
                'error' => 40,
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
        $classLikes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof Class_
                || $node instanceof Trait_
                || $node instanceof Enum_;
        });

        $findings = [];

        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike */
            $methods = array_filter(
                $classLike->stmts,
                static fn (Node $stmt): bool => $stmt instanceof ClassMethod,
            );

            if ($methods === []) {
                continue;
            }

            $totalLines = 0;

            foreach ($methods as $method) {
                $totalLines += $this->logicalLineCount($method);
            }

            $average = $totalLines / count($methods);

            if ($average <= $warningThreshold) {
                continue;
            }

            $severity = $average > $errorThreshold ? Severity::Error : Severity::Warning;
            $threshold = $severity === Severity::Error ? $errorThreshold : $warningThreshold;
            $symbol = $this->resolveSymbol($classLike);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    '%s has an average method length of %.1f lines across %d methods, above the %s threshold of %s.',
                    $symbol,
                    $average,
                    count($methods),
                    $severity->value,
                    $this->formatNumber($threshold),
                ),
                filePath: $unit->file->displayPath,
                line: $classLike->getStartLine(),
                severity: $severity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                endLine: $classLike->getEndLine() > 0 ? $classLike->getEndLine() : null,
                symbol: $symbol,
                remediation: 'Refactor long methods into smaller units to reduce average length.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'averageLength' => round($average, 1),
                    'methodCount' => count($methods),
                    'totalLines' => $totalLines,
                    'threshold' => $threshold,
                    'thresholdType' => $severity->value,
                ],
            );
        }

        return $findings;
    }

    private function logicalLineCount(ClassMethod $method): int
    {
        $finder = new NodeFinder();
        $lines = [];

        foreach ($finder->find($method->stmts ?? [], static fn (Node $node): bool => $node instanceof Stmt && !$node instanceof Nop) as $statement) {
            $line = $statement->getStartLine();

            if ($line > 0) {
                $lines[$line] = true;
            }
        }

        return count($lines);
    }

    private function resolveSymbol(Node $node): string
    {
        if ($node instanceof Class_) {
            return $node->name?->toString() ?? sprintf('class@anonymous:%d', $node->getStartLine());
        }

        if ($node instanceof Trait_) {
            return $node->name?->toString() ?? sprintf('trait@%d', $node->getStartLine());
        }

        if ($node instanceof Enum_) {
            return $node->name?->toString() ?? sprintf('enum@%d', $node->getStartLine());
        }

        return sprintf('unknown@%d', $node->getStartLine());
    }

    private function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
