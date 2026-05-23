<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Size;

use GruffPhp\Config\RuleSettings;
use GruffPhp\Config\SeverityThreshold;
use GruffPhp\Config\ThresholdMatch;
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
     * @return RuleDefinition Rule metadata and thresholds.
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for callables above configured thresholds.
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
            /** @var ClassMethod|Function_|Closure|ArrowFunction $node Finder predicate restricts results to parameter-bearing function-like nodes. */
            $paramCount = count($node->params);

            if ($node instanceof ClassMethod && $this->isPromotedValueObjectConstructor($node)) {
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
                    severity:         $definition->defaultSeverity,
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
     * @return ThresholdMatch|null Matched threshold, or null when allowed.
     */
    private function thresholdMatch(
        ClassMethod|Function_|Closure|ArrowFunction $node,
        int $paramCount,
        int $constructorMax,
        RuleSettings $settings,
    ): ?ThresholdMatch {
        if ($this->usesConstructorThreshold($node, $paramCount, $constructorMax)) {
            return new ThresholdMatch(
                $constructorMax,
                $this->constructorThresholdSeverity($settings, $constructorMax),
            );
        }

        if ($node instanceof ClassMethod && $this->isConstructor($node) && $constructorMax > 0) {
            return null;
        }

        return $settings->highValueThresholdMatch($paramCount);
    }

    /**
     * Use the configured rule severity for constructor-specific threshold hits.
     *
     * @return Severity Severity selected from the effective rule settings.
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
     * Exclude final readonly value-object constructors that use property promotion.
     *
     * @return bool True when the constructor shape is an accepted value object.
     */
    private function isPromotedValueObjectConstructor(ClassMethod $node): bool
    {
        if (!$this->isConstructor($node) || $node->params === []) {
            return false;
        }

        $parent = $node->getAttribute('parent');
        if (!$parent instanceof Node\Stmt\Class_ || !$parent->isFinal() || !$parent->isReadonly()) {
            return false;
        }

        foreach ($node->params as $param) {
            if ($param->flags === 0 || $param->type === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool True when the node is a PHP constructor.
     */
    private function isConstructor(ClassMethod $node): bool
    {
        return $node->name->toString() === '__construct';
    }

    /**
     * @return bool True when the constructor-specific option caused the finding.
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
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options
     * @return int Non-negative integer option value.
     */
    private function integerOption(array $options, string $name, int $default): int
    {
        $optionValue = $options[$name] ?? $default;

        return is_int($optionValue) ? max(0, $optionValue) : $default;
    }

    /**
     * Build a display symbol for a callable node.
     *
     * @return string Callable display symbol.
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
