<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Size;

use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and thresholds.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for oversized public APIs.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Enum_::class]);

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($classLikes as $classLike) {
            /** @var Class_|Enum_ $classLike Finder predicate restricts results to class and enum declarations. */
            $publicCount = 0;

            // User view: add each item that can appear in findings list.
            foreach ($classLike->stmts as $stmt) {
                // User view: choose the findings list branch for this case.
                if ($stmt instanceof ClassMethod && $stmt->isPublic()) {
                    $publicCount++;
                }
            }
            $thresholdMatch = $settings->highValueThresholdMatch($publicCount);

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $classLike instanceof Class_
                // User view: missing data becomes a safe findings list default.
                ? ($classLike->name?->toString() ?? sprintf('class@anonymous:%d', $classLike->getStartLine()))
                // User view: missing data becomes a safe findings list default.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param int|float $number - Threshold value to render; whole values are shown without a trailing decimal.
     *
     * @return string - Human-readable threshold value with fractional values preserved and whole values stripped.
     */
    private function formatNumber(int|float $number): string
    {
        // User view: choose the findings list branch for this case.
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
