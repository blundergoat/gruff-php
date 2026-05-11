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
use PhpParser\NodeFinder;

final readonly class PublicMethodCountRule implements RuleInterface
{
    public const ID = 'size.public-method-count';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Public method count',
            pillar: Pillar::Size,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 15,
                'error' => 25,
            ],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings = $context->settingsFor($definition);

        $finder = new NodeFinder();
        $classLikes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof Class_ || $node instanceof Enum_;
        });

        $findings = [];

        foreach ($classLikes as $classLike) {
            /** @var Class_|Enum_ $classLike */
            $publicCount = 0;

            foreach ($classLike->stmts as $stmt) {
                if ($stmt instanceof ClassMethod && $stmt->isPublic()) {
                    $publicCount++;
                }
            }
            $thresholdMatch = $settings->highValueThresholdMatch($publicCount);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $classLike instanceof Class_
                ? ($classLike->name?->toString() ?? sprintf('class@anonymous:%d', $classLike->getStartLine()))
                : ($classLike->name?->toString() ?? sprintf('enum@%d', $classLike->getStartLine()));

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    '%s has %d public methods, above the %s threshold of %s.',
                    $symbol,
                    $publicCount,
                    $thresholdMatch->severity->value,
                    $this->formatNumber($thresholdMatch->threshold),
                ),
                filePath: $unit->file->displayPath,
                line: $classLike->getStartLine(),
                severity: $thresholdMatch->severity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                endLine: $classLike->getEndLine() > 0 ? $classLike->getEndLine() : null,
                symbol: $symbol,
                remediation: 'Split the class into smaller, focused interfaces and implementations.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'publicMethods' => $publicCount,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    private function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
