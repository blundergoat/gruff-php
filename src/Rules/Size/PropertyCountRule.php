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
 * Flags a class, trait, or enum that owns so many properties its state has likely sprawled beyond one
 * clear responsibility - though an immutable data carrier gets a gentler advisory instead of an error.
 *
 * Runs per file over every class-like scope, counting declared properties plus promoted constructor
 * params against the threshold (default error above 15). A final readonly class whose methods only
 * expose state is treated as a data carrier and softened to advisory.
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
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for classes, traits, or enums with too many properties.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];

        // Check each class, trait, and enum in the file.
        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike Finder predicate restricts results to class-like declarations. */
            $propertyCount  = $this->countProperties($classLike);
            $thresholdMatch = $settings->highValueThresholdMatch($propertyCount);

            // A count within the threshold is fine, so skip it.
            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $this->resolveSymbol($classLike);
            $severity = $this->isReadonlyDataCarrier($classLike)
                ? Severity::Advisory
                : $thresholdMatch->severity;
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
                remediation:      'Group related properties into value objects or extract sub-components.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'properties' => $propertyCount,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $severity->value,
                    'rawThresholdType' => $thresholdMatch->severity->value,
                    'findingKind' => $findingKind,
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

        // Add up declared properties and promoted constructor params.
        foreach ($classLike->stmts as $stmt) {
            // A property declaration can define several names at once.
            if ($stmt instanceof Property) {
                $count += count($stmt->props);
            }

            // The constructor may promote parameters into properties.
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                foreach ($stmt->params as $param) {
                    // A non-zero flag set means the param is promoted, so it owns state too.
                    if ($param->flags !== 0) {
                        $count++;
                    }
                }
            }
        }

        // Sum of declared properties plus promoted constructor params; both own instance state.
        return $count;
    }

    /**
     * Reports whether a class is a final readonly data carrier, which earns the softer advisory severity.
     *
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration being classified.
     *
     * @return bool - True when the class is a final readonly data carrier.
     */
    private function isReadonlyDataCarrier(Class_|Trait_|Enum_ $classLike): bool
    {
        // Only a final readonly class can be a pure data carrier.
        if (!$classLike instanceof Class_ || !$classLike->isFinal() || !$classLike->isReadonly()) {
            return false;
        }

        // Check every method other than the constructor.
        foreach ($classLike->getMethods() as $method) {
            // The constructor sets state; it is not behaviour.
            if ($method->name->toString() === '__construct') {
                continue;
            }

            // A data carrier answers questions about its own state (accessors, `with*()` copies, `equals()`),
            // which all return a value; a method that returns nothing or is untyped is a command, so the class
            // is a behaviour-carrying service whose property count keeps its configured severity.
            if ($this->isBehaviourMethod($method)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reports whether a method performs behaviour (returns void/never or is untyped) rather than expose state.
     *
     * @param ClassMethod $method - Parameterless method to classify.
     *
     * @return bool - True when the method returns nothing or is untyped, so it acts rather than accesses state.
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
            // Named class shows its name; an anonymous class falls back to its start line.
            return $node->name?->toString() ?? sprintf('class@anonymous:%d', $node->getStartLine());
        }

        if ($node instanceof Trait_) {
            // Traits are always named, but guard the nullable name and anchor to the line if absent.
            return $node->name?->toString() ?? sprintf('trait@%d', $node->getStartLine());
        }

        if ($node instanceof Enum_) {
            // Enums are always named, but guard the nullable name and anchor to the line if absent.
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
        // A genuine fraction keeps its decimals; a whole value is shown without them.
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
