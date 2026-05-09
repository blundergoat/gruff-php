<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CognitiveComplexityRule;
use GruffPhp\Rule\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rule\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rule\Waste\CommentedOutCodeRule;
use GruffPhp\Rule\Waste\EmptyClassRule;
use GruffPhp\Rule\Waste\EmptyMethodRule;
use GruffPhp\Rule\Waste\UnreachableCodeRule;
use GruffPhp\Rule\Waste\UnusedImportRule;
use GruffPhp\Rule\Waste\UnusedParameterRule;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\Complexity\HalsteadVolumeRule;
use GruffPhp\Rule\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rule\Complexity\NestingDepthRule;
use GruffPhp\Rule\Complexity\NpathComplexityRule;
use GruffPhp\Rule\Size\AverageMethodLengthRule;
use GruffPhp\Rule\Size\ClassLengthRule;
use GruffPhp\Rule\Size\FileLengthRule;
use GruffPhp\Rule\Size\MethodLengthRule;
use GruffPhp\Rule\Size\ParameterCountRule;
use GruffPhp\Rule\Size\PropertyCountRule;
use GruffPhp\Rule\Size\PublicMethodCountRule;
use InvalidArgumentException;

final class RuleRegistry
{
    /** @var array<string, RuleInterface> */
    private array $rules;

    /**
     * @param list<RuleInterface> $rules
     */
    public function __construct(array $rules)
    {
        $indexedRules = [];

        foreach ($rules as $rule) {
            $id = $rule->definition()->id;

            if (isset($indexedRules[$id])) {
                throw new InvalidArgumentException(sprintf('Duplicate rule id "%s".', $id));
            }

            $indexedRules[$id] = $rule;
        }

        ksort($indexedRules, SORT_STRING);
        $this->rules = $indexedRules;
    }

    public static function defaults(): self
    {
        return new self([
            new CognitiveComplexityRule(),
            new CyclomaticComplexityRule(),
            new HalsteadVolumeRule(),
            new MaintainabilityIndexRule(),
            new NestingDepthRule(),
            new NpathComplexityRule(),
            new UnusedPrivateMethodRule(),
            new UnusedPrivatePropertyRule(),
            new CommentedOutCodeRule(),
            new EmptyClassRule(),
            new EmptyMethodRule(),
            new UnreachableCodeRule(),
            new UnusedImportRule(),
            new UnusedParameterRule(),
            new AverageMethodLengthRule(),
            new ClassLengthRule(),
            new FileLengthRule(),
            new MethodLengthRule(),
            new ParameterCountRule(),
            new PropertyCountRule(),
            new PublicMethodCountRule(),
        ]);
    }

    /**
     * @return list<RuleInterface>
     */
    public function all(): array
    {
        return array_values($this->rules);
    }

    public function has(string $ruleId): bool
    {
        return isset($this->rules[$ruleId]);
    }

    public function get(string $ruleId): RuleInterface
    {
        return $this->rules[$ruleId]
            ?? throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
    }

    /**
     * @return list<RuleInterface>
     */
    public function enabledRules(AnalysisConfig $config): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (RuleInterface $rule): bool => $config->ruleSettings($rule->definition()->id)->enabled,
        ));
    }

    /**
     * @param list<AnalysisUnit> $units
     * @return list<Finding>
     */
    public function analyse(array $units, RuleContext $context): array
    {
        $findings = [];

        foreach ($units as $unit) {
            if ($unit->hasParseErrors()) {
                continue;
            }

            foreach ($this->enabledRules($context->config) as $rule) {
                array_push($findings, ...$rule->analyse($unit, $context));
            }
        }

        usort(
            $findings,
            static fn (Finding $left, Finding $right): int => [
                $left->filePath,
                $left->line ?? 0,
                $left->ruleId,
                $left->message,
            ] <=> [
                $right->filePath,
                $right->line ?? 0,
                $right->ruleId,
                $right->message,
            ],
        );

        return $findings;
    }
}
