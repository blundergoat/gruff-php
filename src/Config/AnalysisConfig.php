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
     * @param list<string> $ignoredPathPatterns
     * @param list<string> $acceptedAbbreviations
     * @param list<string> $allowedSecretPreviews
     */
    public function __construct(
        private array $rules,
        private float $minimumPhpVersion = self::DEFAULT_MINIMUM_PHP_VERSION,
        private RuleSelection $ruleSelection = new RuleSelection(),
        private array $ignoredPathPatterns = [],
        private array $acceptedAbbreviations = [],
        private array $allowedSecretPreviews = [],
    ) {
        if ($this->minimumPhpVersion < 7.4) {
            throw new InvalidArgumentException('Minimum PHP version must be at least 7.4.');
        }
    }

    public static function fromRegistry(RuleRegistry $registry): self
    {
        $rules = [];

        foreach ($registry->all() as $rule) {
            $definition = $rule->definition();
            $rules[$definition->id] = new RuleSettings(
                $definition->defaultEnabled,
                $definition->defaultThresholds,
                $definition->defaultOptions,
            );
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

        return new self(
            $rules,
            $this->minimumPhpVersion,
            $this->ruleSelection,
            $this->ignoredPathPatterns,
            $this->acceptedAbbreviations,
            $this->allowedSecretPreviews,
        );
    }

    public function minimumPhpVersion(): float
    {
        return $this->minimumPhpVersion;
    }

    public function withMinimumPhpVersion(float $minimumPhpVersion): self
    {
        return new self(
            $this->rules,
            $minimumPhpVersion,
            $this->ruleSelection,
            $this->ignoredPathPatterns,
            $this->acceptedAbbreviations,
            $this->allowedSecretPreviews,
        );
    }

    /**
     * @return array<string, RuleSettings>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function ruleSelection(): RuleSelection
    {
        return $this->ruleSelection;
    }

    public function withRuleSelection(RuleSelection $ruleSelection): self
    {
        return new self(
            $this->rules,
            $this->minimumPhpVersion,
            $ruleSelection,
            $this->ignoredPathPatterns,
            $this->acceptedAbbreviations,
            $this->allowedSecretPreviews,
        );
    }

    /**
     * @return list<string>
     */
    public function ignoredPathPatterns(): array
    {
        return $this->ignoredPathPatterns;
    }

    /**
     * @param list<string> $ignoredPathPatterns
     */
    public function withIgnoredPathPatterns(array $ignoredPathPatterns): self
    {
        return new self(
            $this->rules,
            $this->minimumPhpVersion,
            $this->ruleSelection,
            $ignoredPathPatterns,
            $this->acceptedAbbreviations,
            $this->allowedSecretPreviews,
        );
    }

    /**
     * @return list<string>
     */
    public function acceptedAbbreviations(): array
    {
        return $this->acceptedAbbreviations;
    }

    /**
     * @param list<string> $acceptedAbbreviations
     */
    public function withAcceptedAbbreviations(array $acceptedAbbreviations): self
    {
        return new self(
            $this->rules,
            $this->minimumPhpVersion,
            $this->ruleSelection,
            $this->ignoredPathPatterns,
            $acceptedAbbreviations,
            $this->allowedSecretPreviews,
        );
    }

    /**
     * @return list<string>
     */
    public function allowedSecretPreviews(): array
    {
        return $this->allowedSecretPreviews;
    }

    /**
     * @param list<string> $allowedSecretPreviews
     */
    public function withAllowedSecretPreviews(array $allowedSecretPreviews): self
    {
        return new self(
            $this->rules,
            $this->minimumPhpVersion,
            $this->ruleSelection,
            $this->ignoredPathPatterns,
            $this->acceptedAbbreviations,
            $allowedSecretPreviews,
        );
    }
}
