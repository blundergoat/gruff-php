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
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

final readonly class ClassLengthRule implements RuleInterface
{
    public const ID = 'size.class-length';

    /**
     * Describe the class-length rule.
     *
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Class length',
            pillar: Pillar::Size,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 300,
                'error' => 500,
            ],
        );
    }

    /**
     * Find class-like scopes whose physical line length exceeds thresholds.
     *
     * @return list<Finding> Findings for oversized classes, traits, or enums.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings = $context->settingsFor($definition);

        $finder = new NodeFinder();
        $nodes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof Class_
                || $node instanceof Trait_
                || $node instanceof Enum_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            $startLine = $node->getStartLine();
            $endLine = $node->getEndLine();

            if ($startLine < 0 || $endLine < 0) {
                continue;
            }

            $length = $endLine - $startLine + 1;
            $thresholdMatch = $settings->highValueThresholdMatch($length);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $this->resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    '%s is %d lines, above the %s threshold of %s.',
                    $symbol,
                    $length,
                    $thresholdMatch->severity->value,
                    $this->formatNumber($thresholdMatch->threshold),
                ),
                filePath: $unit->file->displayPath,
                line: $startLine,
                severity: $thresholdMatch->severity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                endLine: $endLine,
                symbol: $symbol,
                remediation: 'Split large classes into smaller, focused units.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'lines' => $length,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * Build a display symbol for a class-like node.
     *
     * @return string Class-like display symbol.
     */
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

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
     * @return string Human-readable threshold value.
     */
    private function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
