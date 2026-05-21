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
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;

/**
 * Detects classes with public APIs large enough to dilute their responsibility.
 */
final readonly class PublicMethodCountRule implements RuleInterface
{
    /**
     * Stable rule identifier for public method count findings.
     */
    public const ID = 'size.public-method-count';

    /**
     * Describe the public method count rule.
     *
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Public method count',
            pillar:            Pillar::Size,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(25, Severity::Error),
        );
    }

    /**
     * Find classes and enums with too many public methods.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for oversized public APIs.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Enum_::class]);

        $findings = [];

        foreach ($classLikes as $classLike) {
            /** @var Class_|Enum_ $classLike Finder predicate restricts results to class and enum declarations. */
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
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has %d public methods, above the %s threshold of %s.',
                    $symbol,
                    $publicCount,
                    $thresholdMatch->severity->value,
                    $this->formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $classLike->getStartLine(),
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $classLike->getEndLine() > 0 ? $classLike->getEndLine() : null,
                symbol:           $symbol,
                remediation:      'Split the class into smaller, focused interfaces and implementations.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'publicMethods' => $publicCount,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
     * @return string Human-readable threshold value.
     */
    private function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
