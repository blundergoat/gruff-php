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
 * Flags a class or enum that exposes so many public methods its responsibility has likely blurred, a
 * size signal that the type is doing too much for one caller to hold in their head.
 *
 * Runs per file over every class and enum, counting public methods and reporting any past the threshold
 * (default error above 25). The finding names the type and its public-method count.
 */
final readonly class PublicMethodCountRule implements RuleInterface
{
    /**
     * Stable rule identifier for public method count findings.
     */
    public const ID = 'size.public-method-count';

    /**
     * Describes the public-method-count rule for the registry and reports.
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
     * Reports each class or enum whose public-method count exceeds the configured threshold.
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

        // Check each class and enum in the file.
        foreach ($classLikes as $classLike) {
            /** @var Class_|Enum_ $classLike Finder predicate restricts results to class and enum declarations. */
            $publicCount = 0;

            // Count the public methods on this type.
            foreach ($classLike->stmts as $stmt) {
                // Only public methods count toward the API surface.
                if ($stmt instanceof ClassMethod && $stmt->isPublic()) {
                    $publicCount++;
                }
            }
            $thresholdMatch = $settings->highValueThresholdMatch($publicCount);

            // A count within the threshold is fine, so skip it.
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
     * Formats a threshold number for the message, dropping a whole number's ".0" tail.
     *
     * @param int|float $number - Threshold value to render; whole values are shown without a trailing decimal.
     *
     * @return string - Human-readable threshold value with fractional values preserved and whole values stripped.
     */
    private function formatNumber(int|float $number): string
    {
        // A genuine fraction keeps its decimals; a whole value is shown without them.
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
