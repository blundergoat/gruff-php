<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Size;

use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Engine\Config\ThresholdMatch;
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
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Reports functions, methods, closures and arrow functions whose parameter counts exceed their active limits.
 * The default main threshold is 10; a positive constructorMaxParameters replaces it for other constructors.
 *
 * Non-empty, fully typed promoted constructors in final readonly classes use promotedConstructorMaxParameters (default 25).
 * This exemption also requires every other declared method to have a return type other than void or never.
 * Findings above the promoted-constructor ceiling remain advisory.
 */
final readonly class ParameterCountRule implements RuleInterface
{
    /**
     * Stable rule identifier for parameter count findings.
     */
    public const ID = 'size.parameter-count';

    /**
     * Describes the parameter-count rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Parameter count',
            pillar:            Pillar::Size,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            defaultOptions:    ['promotedConstructorMaxParameters' => 25, 'constructorMaxParameters' => 0],
            severityThreshold: new SeverityThreshold(10, Severity::Error),
        );
    }

    /**
     * Reports each callable whose parameter count exceeds the threshold that applies to it.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit containing the callable parameter lists to measure.
     * @param RuleContext $ruleContext - Supplies effective thresholds and constructor-limit options.
     *
     * @return list<Finding> - One finding per callable above its applicable limit; empty when none qualify.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition      = $this->definition();
        $settings        = $ruleContext->settingsFor($definition);
        $promotedCeiling = $this->integerOption($settings->options, 'promotedConstructorMaxParameters', 25);
        $constructorMax  = $this->integerOption($settings->options, 'constructorMaxParameters', 0);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class, Closure::class, ArrowFunction::class]);

        $findings = [];


        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_|Closure|ArrowFunction $node - Callable node selected by NodeIndex. */
            $paramCount = count($node->params);

            // Eligible promoted constructors bypass both the main threshold and the constructor-specific cap.
            if ($node instanceof ClassMethod && $this->isPromotedValueObjectConstructor($node)) {

                if ($paramCount <= $promotedCeiling) {
                    continue;
                }

                $symbol = $this->resolveSymbol($node);
                $band   = LimitBand::of($paramCount, $promotedCeiling);

                $findings[] = new Finding(
                    ruleId:  $definition->id,
                    message: sprintf(
                        'Promoted value-object constructor %s has %d parameters, above the value-object ceiling of %d.',
                        $symbol,
                        $paramCount,
                        $promotedCeiling,
                    ),
                    filePath:         $analysisUnit->file->displayPath,
                    line:             $node->getStartLine(),
                    severity:         Severity::Advisory,
                    pillar:           $definition->pillar,
                    tier:             $definition->tier,
                    confidence:       $definition->confidence,
                    endLine:          $node->getEndLine() > 0 ? $node->getEndLine() : null,
                    symbol:           $symbol,
                    remediation:      LimitBand::advice($band, LimitBand::LOWER_PARAMETER, 'Split the value object, or group related parameters into nested value objects.'),
                    secondaryPillars: $definition->secondaryPillars,
                    metadata:         [
                        'parameters' => $paramCount,
                        'promotedConstructorMaxParameters' => $promotedCeiling,
                        'findingKind' => 'promoted-ctor-ceiling',
                        'thresholdType' => Severity::Advisory->value,
                        LimitBand::KEY => $band,
                    ],
                );

                continue;
            }

            $thresholdMatch = $this->thresholdMatch($node, $paramCount, $constructorMax, $settings);


            if ($thresholdMatch === null) {
                continue;
            }

            $symbol               = $this->resolveSymbol($node);
            $constructorThreshold = $this->usesConstructorThreshold($node, $paramCount, $constructorMax);
            $metadata             = [
                'parameters' => $paramCount,
                'threshold' => $thresholdMatch->threshold,
                'thresholdType' => $thresholdMatch->severity->value,
            ];


            if ($constructorThreshold) {
                $metadata['constructorMaxParameters'] = $constructorMax;
                $metadata['findingKind']              = 'constructor-threshold';
            }

            $band                     = LimitBand::of($paramCount, $constructorThreshold ? $constructorMax : $settings->lowestHighValueThreshold());
            $metadata[LimitBand::KEY] = $band;

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: $constructorThreshold
                    ? sprintf(
                        'Constructor %s has %d parameters, above the constructor threshold of %s.',
                        $symbol,
                        $paramCount,
                        $this->formatNumber($thresholdMatch->threshold),
                    )
                    : sprintf(
                        '%s has %d parameters, above the %s threshold of %s.',
                        $symbol,
                        $paramCount,
                        $thresholdMatch->severity->value,
                        $this->formatNumber($thresholdMatch->threshold),
                    ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $node->getStartLine(),
                severity:         LimitBand::severity($band, $thresholdMatch->severity),
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol:           $symbol,
                remediation:      LimitBand::advice($band, LimitBand::LOWER_PARAMETER, LimitBand::GROUP_PARAMETERS),
                secondaryPillars: $definition->secondaryPillars,
                metadata:         $metadata,
            );
        }

        return $findings;
    }

    /**
     * Uses the opt-in constructor cap when breached, exempts constructors at or below that cap, and otherwise checks the main threshold ladder.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Callable judged; only its constructor-ness matters.
     *
     * @param int          $paramCount     - Declared parameter count, compared against the chosen threshold.
     * @param int          $constructorMax - Constructor-specific cap; zero disables it and defers to the main threshold.
     * @param RuleSettings $settings       - Effective settings supplying the main high-value threshold ladder.
     *
     * @return ThresholdMatch|null - Matched threshold, or null when allowed.
     */
    private function thresholdMatch(
        ClassMethod|Function_|Closure|ArrowFunction $node,
        int $paramCount,
        int $constructorMax,
        RuleSettings $settings,
    ): ?ThresholdMatch {
        if ($this->usesConstructorThreshold($node, $paramCount, $constructorMax)) {
            // Constructor breached its opt-in cap; judge it by that cap, not the general ladder.
            return new ThresholdMatch(
                $constructorMax,
                $this->constructorThresholdSeverity($settings, $constructorMax),
            );
        }

        if ($node instanceof ClassMethod && $this->isConstructor($node) && $constructorMax > 0) {
            // A configured constructor at or below its cap is exempt from the general thresholds, so no finding is produced.
            return null;
        }


        return $settings->highValueThresholdMatch($paramCount);
    }

    /**
     * Uses the single-threshold severity when present, otherwise the severity just above the constructor cap.
     *
     * @param RuleSettings $settings - Settings supplying a single-threshold override or the main threshold ladder.
     * @param int $constructorMax - Constructor cap; the ladder is queried at this value plus one.
     *
     * @return Severity - Single-threshold severity, matched ladder severity, or Error when the ladder has no match.
     */
    private function constructorThresholdSeverity(RuleSettings $settings, int $constructorMax): Severity
    {
        if ($settings->severityThreshold instanceof SeverityThreshold) {

            return $settings->severityThreshold->severity;
        }

        $thresholdMatch = $settings->highValueThresholdMatch($constructorMax + 1);


        return $thresholdMatch instanceof ThresholdMatch ? $thresholdMatch->severity : Severity::Error;
    }

    /**
     * Checks signature-based eligibility for the promoted-constructor exemption.
     * Other declared methods must be typed and must not return void or never.
     *
     * @param ClassMethod $node - Candidate constructor whose parameters, owning class and sibling methods are checked.
     *
     * @return bool - True when non-empty typed promotion, a final readonly class and the sibling return-type checks all hold.
     */
    private function isPromotedValueObjectConstructor(ClassMethod $node): bool
    {
        if (!$this->isConstructor($node) || $node->params === []) {
            // Empty constructors and non-constructors do not qualify for the promoted-parameter exemption.
            return false;
        }

        $parent = $node->getAttribute('parent');
        if (!$parent instanceof Node\Stmt\Class_ || !$parent->isFinal() || !$parent->isReadonly()) {
            // The immutability guarantee relies on the enclosing class being final and readonly.
            return false;
        }


        foreach ($node->params as $param) {
            if ($param->flags === 0 || $param->type === null) {
                // A bare or untyped parameter means this is a plain constructor, not a typed value object.
                return false;
            }
        }

        // The exemption uses sibling return types as a value-object heuristic; it does not inspect method bodies.
        foreach ($parent->getMethods() as $method) {

            if ($method->name->toString() === '__construct') {
                continue;
            }

            // A void, never or missing return type disqualifies the constructor from the promoted-parameter ceiling.


            if ($this->isBehaviourMethod($method)) {
                return false;
            }
        }


        return true;
    }

    /**
     * Applies the return-type heuristic used to exclude behaviour methods from the value-object exemption.
     *
     * @param ClassMethod $method - Non-constructor method whose declared return type is inspected.
     *
     * @return bool - True for no declared return type or plain void/never; other declared types return false.
     */
    private function isBehaviourMethod(ClassMethod $method): bool
    {
        $returnType = $method->returnType;

        // No declared return type means we cannot confirm it only exposes state, so treat it as behaviour.
        if ($returnType === null) {
            return true;
        }

        return $returnType instanceof Node\Identifier
            && in_array(strtolower($returnType->name), ['void', 'never'], true);
    }

    /**
     * Reports whether a method is the class constructor.
     *
     * @param ClassMethod $node - Method node whose name decides whether it is the constructor.
     *
     * @return bool - True when the node is a PHP constructor.
     */
    private function isConstructor(ClassMethod $node): bool
    {
        return $node->name->toString() === '__construct';
    }

    /**
     * Reports whether the opt-in constructor cap is what a constructor's parameter count breached.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Callable being judged; only a constructor can qualify.
     *
     * @param int $paramCount     - Declared parameter count, compared against the constructor cap.
     * @param int $constructorMax - Configured constructor cap; must be above zero for the opt-in path to apply.
     *
     * @return bool - True when the constructor-specific option caused the finding.
     */
    private function usesConstructorThreshold(
        ClassMethod|Function_|Closure|ArrowFunction $node,
        int $paramCount,
        int $constructorMax,
    ): bool {
        return $node instanceof ClassMethod
            && $this->isConstructor($node)
            && $constructorMax > 0
            && $paramCount > $constructorMax;
    }

    /**
     * Reads an integer rule option, clamping supplied integers at zero and otherwise returning the default.
     *
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options - Effective rule options keyed by option name.
     *
     * @param string $name    - Option key to read out of the bag.
     * @param int    $default - Fallback used when the key is absent or not an integer.
     *
     * @return int - Configured integer clamped at zero, or the unchanged default when the option is absent or not an integer.
     */
    private function integerOption(array $options, string $name, int $default): int
    {
        $optionValue = $options[$name] ?? $default;

        return is_int($optionValue) ? max(0, $optionValue) : $default;
    }

    /**
     * Builds a display symbol for a callable node (Class::method(), function(), or Closure@line).
     *
     * @param Node $node - Method, function, closure or arrow function to label in a finding.
     *
     * @return string - Qualified or bare method name, function name, or Closure@line label for closures and arrow functions.
     */
    private function resolveSymbol(Node $node): string
    {

        if ($node instanceof ClassMethod) {
            $parent    = $node->getAttribute('parent');
            $className = $parent instanceof Node\Stmt\Class_
                || $parent instanceof Node\Stmt\Trait_
                || $parent instanceof Node\Stmt\Enum_
                ? ($parent->name?->toString() ?? 'class@anonymous')
                : null;

            // Anonymous classes use class@anonymous; a missing or unsupported parent leaves the method unqualified.
            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }


        if ($node instanceof Function_) {
            return $node->name->toString() . '()';
        }

        return sprintf('Closure@%d', $node->getStartLine());
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
