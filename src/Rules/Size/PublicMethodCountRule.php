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
 * Reports excessive counts of declared public methods in classes and enums.
 * Includes public constructors; inherited methods and methods supplied only by traits are not counted.
 *
 * The default limit is 25; lower-band findings are advisory and upper-band findings retain the matched severity.
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
     * Reports each class or enum whose declared public-method count exceeds the configured threshold.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Supplies the configured public-method thresholds; a class below every threshold is not reported.
     *
     * @return list<Finding> - Findings for oversized declared public APIs.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Enum_::class]);

        $findings = [];


        foreach ($classLikes as $classLike) {
            /** @var Class_|Enum_ $classLike - NodeIndex restricts results to class and enum declarations. */
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

            $band = LimitBand::of($publicCount, $settings->lowestHighValueThreshold());

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
                severity:         LimitBand::severity($band, $thresholdMatch->severity),
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $classLike->getEndLine() > 0 ? $classLike->getEndLine() : null,
                symbol:           $symbol,
                remediation:      LimitBand::advice($band, LimitBand::LOWER_PUBLIC_METHOD, LimitBand::SPLIT_CLASS),
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'publicMethods' => $publicCount,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                    LimitBand::KEY => $band,
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

        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
