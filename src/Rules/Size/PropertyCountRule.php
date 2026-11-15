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
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;

/**
 * Reports excessive property counts in classes, traits, and enums; counts declared properties and promoted constructor parameters.
 * The default limit is 15; lower-band findings are advisory and upper-band findings retain the matched severity unless downgraded.
 * Final readonly classes stay advisory if every directly declared method except __construct declares a non-void, non-never return type.
 */
final readonly class PropertyCountRule implements RuleInterface
{
    /**
     * Stable rule identifier for property count findings.
     */
    public const ID = 'size.property-count';

    /**
     * Describes the property-count rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Property count',
            pillar:            Pillar::Size,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(15, Severity::Error),
        );
    }

    /**
     * Reports each class-like scope whose property count exceeds the configured threshold.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Supplies the configured property-count thresholds; a class below every threshold is not reported.
     *
     * @return list<Finding> - Findings for classes, traits, or enums with too many properties.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];


        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike - NodeIndex restricts results to class-like declarations. */
            $propertyCount  = $this->countProperties($classLike);
            $thresholdMatch = $settings->highValueThresholdMatch($propertyCount);


            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $this->resolveSymbol($classLike);
            $band     = LimitBand::of($propertyCount, $settings->lowestHighValueThreshold());
            $severity = LimitBand::severity($band, $this->isReadonlyDataCarrier($classLike)
                ? Severity::Advisory
                : $thresholdMatch->severity);
            $findingKind = $this->isReadonlyDataCarrier($classLike)
                ? 'readonly-data-carrier'
                : 'property-count';

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: $findingKind === 'readonly-data-carrier'
                    ? sprintf(
                        'Readonly data carrier %s has %d properties, above the %s threshold of %s, but is advisory unless behaviour grows complex.',
                        $symbol,
                        $propertyCount,
                        $thresholdMatch->severity->value,
                        $this->formatNumber($thresholdMatch->threshold),
                    )
                    : sprintf(
                        '%s has %d properties, above the %s threshold of %s.',
                        $symbol,
                        $propertyCount,
                        $thresholdMatch->severity->value,
                        $this->formatNumber($thresholdMatch->threshold),
                    ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $classLike->getStartLine(),
                severity:         $severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $classLike->getEndLine() > 0 ? $classLike->getEndLine() : null,
                symbol:           $symbol,
                remediation:      LimitBand::advice($band, LimitBand::LOWER_PROPERTY, LimitBand::SPLIT_CLASS),
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'properties' => $propertyCount,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $severity->value,
                    'rawThresholdType' => $thresholdMatch->severity->value,
                    'findingKind' => $findingKind,
                    LimitBand::KEY => $band,
                ],
            );
        }

        return $findings;
    }

    /**
     * Counts a class-like's owned state: declared properties plus promoted constructor parameters.
     *
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration whose properties and promoted constructor params are counted.
     *
     * @return int - Declared and promoted property count.
     */
    private function countProperties(Node $classLike): int
    {
        $count = 0;


        foreach ($classLike->stmts as $stmt) {
            // A property declaration can define several names at once.
            if ($stmt instanceof Property) {
                $count += count($stmt->props);
            }


            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                foreach ($stmt->params as $param) {
                    // A non-zero flag set means the param is promoted, so it owns state too.
                    if ($param->flags !== 0) {
                        $count++;
                    }
                }
            }
        }


        return $count;
    }

    /**
     * Classifies final readonly classes by the declared return types of their non-constructor methods.
     *
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration being classified.
     *
     * @return bool - True when the class qualifies for advisory property-count findings by this return-type heuristic.
     */
    private function isReadonlyDataCarrier(Class_|Trait_|Enum_ $classLike): bool
    {

        if (!$classLike instanceof Class_ || !$classLike->isFinal() || !$classLike->isReadonly()) {
            return false;
        }


        foreach ($classLike->getMethods() as $method) {
            // Constructor behavior does not affect eligibility for the advisory downgrade.
            if ($method->name->toString() === '__construct') {
                continue;
            }

            // Return types are only a heuristic for behavior; untyped, void, and never methods prevent the downgrade.


            if ($this->isBehaviourMethod($method)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Classifies methods as behavior when their declared return type is absent, void, or never.
     *
     * @param ClassMethod $method - Method whose declared return type controls the classification.
     *
     * @return bool - True when the declared return type is absent, void, or never.
     */
    private function isBehaviourMethod(ClassMethod $method): bool
    {
        $returnType = $method->returnType;

        // No declared return type: we cannot confirm the method only exposes state, so treat it as
        // behaviour and keep the configured severity rather than the data-carrier downgrade.
        if ($returnType === null) {
            return true;
        }

        return $returnType instanceof Node\Identifier
            && in_array(strtolower($returnType->name), ['void', 'never'], true);
    }

    /**
     * Builds a display name for a class-like node, synthesising a label when it is unnamed.
     *
     * @param Node $node - Class, trait, or enum declaration to render as a finding symbol.
     *
     * @return string - Class-like display symbol.
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

        // Unreachable for the finder's class-like set; kept so an unexpected node still renders a symbol.
        return sprintf('unknown@%d', $node->getStartLine());
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
