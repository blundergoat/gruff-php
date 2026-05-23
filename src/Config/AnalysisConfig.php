<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Rule\RuleRegistry;
use InvalidArgumentException;

/**
 * Holds the effective analyzer configuration after defaults and file settings are merged.
 */
final readonly class AnalysisConfig
{
    /**
     * Default PHP version floor for version-sensitive rules.
     */
    public const DEFAULT_MINIMUM_PHP_VERSION = 8.3;

    /**
     * @param array<string, RuleSettings> $rules                 Effective settings keyed by rule id.
     * @param float                       $minimumPhpVersion     Minimum PHP version used by version-sensitive rules.
     * @param RuleSelection               $ruleSelection         Include/exclude rule selection for the run.
     * @param list<string>                $ignoredPathPatterns   Path patterns skipped during discovery.
     * @param list<string>                $acceptedAbbreviations Abbreviations accepted by naming rules.
     * @param list<string>                $allowedSecretPreviews Secret previews explicitly allowed by config.
     * @throws InvalidArgumentException When the PHP version floor is below 7.4.
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
     * @param RuleRegistry $registry Rule registry supplying default rule definitions.
     * @return self Config initialised with registry defaults.
     */
    public static function fromRegistry(RuleRegistry $registry): self
    {
        $rules = [];

        foreach ($registry->all() as $rule) {
            $definition             = $rule->definition();
            $rules[$definition->id] = new RuleSettings(
                $definition->isEnabledByDefault,
                $definition->defaultThresholds,
                $definition->defaultOptions,
                $definition->severityThreshold,
            );
        }

        return new self($rules);
    }

    /**
     * Return the configured settings for a known rule id.
     *
     * @param string $ruleId Rule identifier to read.
     * @throws InvalidArgumentException When the rule id is unknown.
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
     * @param string       $ruleId   Rule identifier to replace.
     * @param RuleSettings $settings New settings for the rule.
     * @throws InvalidArgumentException When the rule id is unknown.
     * @return self Config carrying the updated rule settings.
     */
    public function withRuleSettings(string $ruleId, RuleSettings $settings): self
    {
        if (!isset($this->rules[$ruleId])) {
            throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
        }

        $rules          = $this->rules;
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
     * @param float $minimumPhpVersion New minimum PHP version floor.
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
     * Expose rule settings keyed by rule identifier.
     *
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
     * @param RuleSelection $ruleSelection Rule include/exclude selection to apply.
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
     * Expose configured path ignore patterns.
     *
     * @return list<string>
     */
    public function ignoredPathPatterns(): array
    {
        return $this->ignoredPathPatterns;
    }

    /**
     * @param list<string> $ignoredPathPatterns Path patterns skipped during discovery.
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
     * Expose identifier abbreviations allowed by naming rules.
     *
     * @return list<string>
     */
    public function acceptedAbbreviations(): array
    {
        return $this->acceptedAbbreviations;
    }

    /**
     * @param list<string> $acceptedAbbreviations Abbreviations accepted by naming rules.
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
     * Expose redacted secret previews allowed by sensitive-data rules.
     *
     * @return list<string>
     */
    public function allowedSecretPreviews(): array
    {
        return $this->allowedSecretPreviews;
    }

    /**
     * @param list<string> $allowedSecretPreviews Secret previews explicitly allowed by config.
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
