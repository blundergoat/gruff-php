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
 * Detects callables whose parameter lists exceed the configured size threshold.
 *
 * Final readonly classes whose constructor has every parameter promoted with a
 * type are exempt from the main threshold; they fire only when the parameter
 * count also exceeds the `promotedConstructorMaxParameters` option (default 25).
 * Non-exempt constructors inherit the main threshold unless
 * `constructorMaxParameters` is set above zero.
 */
final readonly class ParameterCountRule implements RuleInterface
{
    /**
     * Stable rule identifier for parameter count findings.
     */
    public const ID = 'size.parameter-count';

    /**
     * Describe the parameter-count rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Find functions, methods, and closures with too many parameters.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for callables above configured thresholds.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition      = $this->definition();
        $settings        = $ruleContext->settingsFor($definition);
        $promotedCeiling = $this->integerOption($settings->options, 'promotedConstructorMaxParameters', 25);
        $constructorMax  = $this->integerOption($settings->options, 'constructorMaxParameters', 0);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class, Closure::class, ArrowFunction::class]);

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_|Closure|ArrowFunction $node Finder predicate restricts results to parameter-bearing function-like nodes. */
            $paramCount = count($node->params);

            // User view: choose the findings list branch for this case.
            if ($node instanceof ClassMethod && $this->isPromotedValueObjectConstructor($node)) {
                // User view: choose the findings list branch for this case.
                if ($paramCount <= $promotedCeiling) {
                    continue;
                }

                $symbol = $this->resolveSymbol($node);

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
                    remediation:      'Split the value object, or group related parameters into nested value objects.',
                    secondaryPillars: $definition->secondaryPillars,
                    metadata:         [
                        'parameters' => $paramCount,
                        'promotedConstructorMaxParameters' => $promotedCeiling,
                        'findingKind' => 'promoted-ctor-ceiling',
                        'thresholdType' => Severity::Advisory->value,
                    ],
                );

                continue;
            }

            $thresholdMatch = $this->thresholdMatch($node, $paramCount, $constructorMax, $settings);

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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

            // User view: choose the findings list branch for this case.
            if ($constructorThreshold) {
                $metadata['constructorMaxParameters'] = $constructorMax;
                $metadata['findingKind']              = 'constructor-threshold';
            }

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
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol:           $symbol,
                remediation:      'Group related parameters into a value object or configuration class.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         $metadata,
            );
        }

        return $findings;
    }

    /**
     * Pick the threshold that applies to a callable.
     *
     * Constructor-specific configuration is opt-in: zero means the constructor
     * inherits the main rule threshold.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Callable judged; only its constructor-ness matters.
     * @param int $paramCount - Declared parameter count, compared against the chosen threshold.
     * @param int $constructorMax - Constructor-specific cap; zero disables it and defers to the main threshold.
     * @param RuleSettings $settings - Effective settings supplying the main high-value threshold ladder.
     *
     * @return ThresholdMatch|null - Matched threshold, or null when allowed.
     */
    private function thresholdMatch(
        ClassMethod|Function_|Closure|ArrowFunction $node,
        int $paramCount,
        int $constructorMax,
        RuleSettings $settings,
    ): ?ThresholdMatch {
        // User view: choose the findings list branch for this case.
        if ($this->usesConstructorThreshold($node, $paramCount, $constructorMax)) {
            // Constructor breached its opt-in cap; judge it by that cap, not the general ladder.
            return new ThresholdMatch(
                $constructorMax,
                $this->constructorThresholdSeverity($settings, $constructorMax),
            );
        }

        // User view: choose the findings list branch for this case.
        if ($node instanceof ClassMethod && $this->isConstructor($node) && $constructorMax > 0) {
            // A configured constructor under its cap is exempt from the main ladder, so it never double-fires.
            return null;
        }

        // Everything else falls back to the general high-value threshold for its parameter count.
        return $settings->highValueThresholdMatch($paramCount);
    }

    /**
     * Use the configured rule severity for constructor-specific threshold hits.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param RuleSettings $settings - Effective settings whose explicit severity, when set, wins outright.
     * @param int          $constructorMax - Configured cap; probed one above to read the ladder's severity for that band.
     *
     * @return Severity - Severity selected from the effective rule settings.
     */
    private function constructorThresholdSeverity(RuleSettings $settings, int $constructorMax): Severity
    {
        // User view: choose the findings list branch for this case.
        if ($settings->severityThreshold instanceof SeverityThreshold) {
            // An explicitly configured severity overrides whatever the high-value ladder would pick.
            return $settings->severityThreshold->severity;
        }

        $thresholdMatch = $settings->highValueThresholdMatch($constructorMax + 1);

        // Borrow the ladder's severity just past the cap; default to Error when the ladder offers none.
        return $thresholdMatch instanceof ThresholdMatch ? $thresholdMatch->severity : Severity::Error;
    }

    /**
     * Exclude final readonly value-object constructors that use property promotion.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod $node - Method node tested for the final-readonly, fully-promoted constructor shape.
     *
     * @return bool - True when the constructor shape is an accepted value object.
     */
    private function isPromotedValueObjectConstructor(ClassMethod $node): bool
    {
        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if (!$this->isConstructor($node) || $node->params === []) {
            // Only a non-empty constructor can be a value object; anything else is judged normally.
            return false;
        }

        $parent = $node->getAttribute('parent');
        // User view: choose the findings list branch for this case.
        if (!$parent instanceof Node\Stmt\Class_ || !$parent->isFinal() || !$parent->isReadonly()) {
            // The immutability guarantee relies on the enclosing class being final and readonly.
            return false;
        }

        // User view: add each item that can appear in findings list.
        foreach ($node->params as $param) {
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($param->flags === 0 || $param->type === null) {
                // A bare or untyped parameter means this is a plain constructor, not a typed value object.
                return false;
            }
        }

        // User view: add each item that can appear in findings list.
        foreach ($parent->getMethods() as $method) {
            // User view: choose the findings list branch for this case.
            if ($method->name->toString() === '__construct') {
                continue;
            }

            // A value object answers questions about its own state (accessors, `with*()` copies, `equals()`),
            // which all return a value; a method that returns nothing or is untyped is a command, so the class
            // is a behaviour-carrying service and its constructor stays on the normal threshold.
            // User view: choose the findings list branch for this case.
            if ($this->isBehaviourMethod($method)) {
                return false;
            }
        }

        // Typed promoted properties on a final readonly class with no behaviour methods: the value-object shape.
        return true;
    }

    /**
     * Decide whether a non-constructor method performs behaviour rather than expose state.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod $method - Non-constructor method to classify.
     *
     * @return bool - True when the method returns nothing or is untyped, so it acts rather than accesses state.
     */
    private function isBehaviourMethod(ClassMethod $method): bool
    {
        $returnType = $method->returnType;

        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($returnType === null) {
            return true;
        }

        return $returnType instanceof Node\Identifier
            && in_array(strtolower($returnType->name), ['void', 'never'], true);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Callable being judged; only a constructor can qualify.
     * @param int $paramCount - Declared parameter count, compared against the constructor cap.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options - Effective rule options keyed by option name.
     * @param string $name - Option key to read out of the bag.
     * @param int $default - Fallback used when the key is absent or not an integer.
     *
     * @return int - Non-negative integer option value.
     */
    private function integerOption(array $options, string $name, int $default): int
    {
        // User view: missing data becomes a safe findings list default.
        $optionValue = $options[$name] ?? $default;

        return is_int($optionValue) ? max(0, $optionValue) : $default;
    }

    /**
     * Build a display symbol for a callable node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Callable node (method, function, or closure) to render as a finding symbol.
     *
     * @return string - Callable display symbol.
     */
    private function resolveSymbol(Node $node): string
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof ClassMethod) {
            $parent    = $node->getAttribute('parent');
            $className = $parent instanceof Node\Stmt\Class_
                || $parent instanceof Node\Stmt\Trait_
                || $parent instanceof Node\Stmt\Enum_
                // User view: missing data becomes a safe findings list default.
                ? ($parent->name?->toString() ?? 'class@anonymous')
                : null;

            // Qualify with the owning type when known; an anonymous class leaves just the bare method name.
            // User view: missing data becomes the expected findings list state.
            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }

        // User view: choose the findings list branch for this case.
        if ($node instanceof Function_) {
            return $node->name->toString() . '()';
        }

        return sprintf('Closure@%d', $node->getStartLine());
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
