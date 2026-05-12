<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Size;

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
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

final readonly class ParameterCountRule implements RuleInterface
{
    public const ID = 'size.parameter-count';

    /**
     * Describe the parameter-count rule.
     *
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Parameter count',
            pillar: Pillar::Size,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 5,
                'error' => 8,
            ],
        );
    }

    /**
     * Find functions, methods, and closures with too many parameters.
     *
     * @return list<Finding> Findings for callables above configured thresholds.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings = $context->settingsFor($definition);

        $finder = new NodeFinder();
        $nodes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod
                || $node instanceof Function_
                || $node instanceof Closure
                || $node instanceof ArrowFunction;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_|Closure|ArrowFunction $node */
            if ($node instanceof ClassMethod && $this->isPromotedValueObjectConstructor($node)) {
                continue;
            }

            $paramCount = count($node->params);
            $thresholdMatch = $settings->highValueThresholdMatch($paramCount);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $this->resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    '%s has %d parameters, above the %s threshold of %s.',
                    $symbol,
                    $paramCount,
                    $thresholdMatch->severity->value,
                    $this->formatNumber($thresholdMatch->threshold),
                ),
                filePath: $unit->file->displayPath,
                line: $node->getStartLine(),
                severity: $thresholdMatch->severity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                endLine: $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol: $symbol,
                remediation: 'Group related parameters into a value object or configuration class.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'parameters' => $paramCount,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * Exclude final readonly value-object constructors that use property promotion.
     *
     * @return bool True when the constructor shape is an accepted value object.
     */
    private function isPromotedValueObjectConstructor(ClassMethod $node): bool
    {
        if ($node->name->toString() !== '__construct' || $node->params === []) {
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
     * Build a display symbol for a callable node.
     *
     * @return string Callable display symbol.
     */
    private function resolveSymbol(Node $node): string
    {
        if ($node instanceof ClassMethod) {
            $parent = $node->getAttribute('parent');
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
    private function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
