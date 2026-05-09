<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Rule\RuleRegistry;
use InvalidArgumentException;

final readonly class AnalysisConfig
{
    public const DEFAULT_MINIMUM_PHP_VERSION = 8.3;

    /**
     * @param array<string, RuleSettings> $rules
     */
    public function __construct(
        private array $rules,
        private float $minimumPhpVersion = self::DEFAULT_MINIMUM_PHP_VERSION,
    )
    {
        if ($this->minimumPhpVersion < 7.4) {
            throw new InvalidArgumentException('Minimum PHP version must be at least 7.4.');
        }
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

        return new self($rules, $this->minimumPhpVersion);
    }

    public function minimumPhpVersion(): float
    {
        return $this->minimumPhpVersion;
    }

    public function withMinimumPhpVersion(float $minimumPhpVersion): self
    {
        return new self($this->rules, $minimumPhpVersion);
    }

    /**
     * @return array<string, RuleSettings>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
