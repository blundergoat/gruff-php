<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Rule\RuleRegistry;
use InvalidArgumentException;

final readonly class AnalysisConfig
{
    /**
     * @param array<string, RuleSettings> $rules
     */
    public function __construct(private array $rules)
    {
    }

    public static function fromRegistry(RuleRegistry $registry): self
    {
        $rules = [];

        foreach ($registry->all() as $rule) {
            $definition = $rule->definition();
            $rules[$definition->id] = new RuleSettings(true, $definition->defaultThresholds);
        }

        return new self($rules);
    }

    public function ruleSettings(string $ruleId): RuleSettings
    {
        return $this->rules[$ruleId]
            ?? throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
    }

    public function withRuleSettings(string $ruleId, RuleSettings $settings): self
    {
        if (!isset($this->rules[$ruleId])) {
            throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
        }

        $rules = $this->rules;
        $rules[$ruleId] = $settings;

        return new self($rules);
    }

    /**
     * @return array<string, RuleSettings>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
