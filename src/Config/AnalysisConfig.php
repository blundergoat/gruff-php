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

    /**
     * Build default settings for every rule in the registry.
     *
     * @return self Config initialised with registry defaults.
     */
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

    /**
     * Return the configured settings for a known rule id.
     *
     * @return RuleSettings Settings for the requested rule.
     */
    public function ruleSettings(string $ruleId): RuleSettings
    {
        return $this->rules[$ruleId]
            ?? throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
    }

    /**
     * Return a copy with one rule's settings replaced.
     *
     * @return self Config carrying the updated rule settings.
     */
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

    /**
     * Return the minimum PHP version used by version-sensitive rules.
     *
     * @return float Minimum supported PHP version.
     */
    public function minimumPhpVersion(): float
    {
        return $this->minimumPhpVersion;
    }

    /**
     * Return a copy with a different minimum PHP version.
     *
     * @return self Config carrying the updated PHP version floor.
     */
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

    /**
     * Return the rule include/exclude selection for this analysis run.
     *
     * @return RuleSelection Rule selection constraints.
     */
    public function ruleSelection(): RuleSelection
    {
        return $this->ruleSelection;
    }

    /**
     * Return a copy with a different rule selection.
     *
     * @return self Config carrying the updated rule selection.
     */
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
     *
     * @return self Config carrying the updated ignored path patterns.
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
     *
     * @return self Config carrying the updated accepted abbreviation list.
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
     *
     * @return self Config carrying the updated allowed secret preview list.
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
