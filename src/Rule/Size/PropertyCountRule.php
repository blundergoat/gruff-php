<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Size;

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
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Detects types with enough properties to suggest broad state ownership.
 */
final readonly class PropertyCountRule implements RuleInterface
{
    /**
     * Stable rule identifier for property count findings.
     */
    public const ID = 'size.property-count';

    /**
     * Describe the property-count rule.
     *
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                       self::ID,
            name:                     'Property count',
            pillar:                   Pillar::Size,
            tier:                     RuleTier::V01,
            defaultSeverity:          Severity::Error,
            confidence:               Confidence::High,
            defaultSeverityThreshold: new SeverityThreshold(15, Severity::Error),
        );
    }

    /**
     * Find class-like scopes whose declared property count exceeds thresholds.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for classes, traits, or enums with too many properties.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings   = $context->settingsFor($definition);

        $finder     = new NodeFinder();
        $classLikes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof Class_
                || $node instanceof Trait_
                || $node instanceof Enum_;
        });

        $findings = [];

        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike Finder predicate restricts results to class-like declarations. */
            $propertyCount  = $this->countProperties($classLike);
            $thresholdMatch = $settings->highValueThresholdMatch($propertyCount);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $this->resolveSymbol($classLike);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has %d properties, above the %s threshold of %s.',
                    $symbol,
                    $propertyCount,
                    $thresholdMatch->severity->value,
                    $this->formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $unit->file->displayPath,
                line:             $classLike->getStartLine(),
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $classLike->getEndLine() > 0 ? $classLike->getEndLine() : null,
                symbol:           $symbol,
                remediation:      'Group related properties into value objects or extract sub-components.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'properties' => $propertyCount,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * @param Class_|Trait_|Enum_ $classLike
     *
     * @return int Declared and promoted property count.
     */
    private function countProperties(Node $classLike): int
    {
        $count = 0;

        foreach ($classLike->stmts as $stmt) {
            if ($stmt instanceof Property) {
                $count += count($stmt->props);
            }

            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                foreach ($stmt->params as $param) {
                    if ($param->flags !== 0) {
                        $count++;
                    }
                }
            }
        }

        return $count;
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
