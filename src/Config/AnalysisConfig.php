<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\FailThresholds;
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
     * Universal-programming abbreviations seeded into every config so naming.abbreviation-allowlist
     * does not flood with terms a maintainer would never bother to allowlist by hand. Project-specific
     * vocabulary (domain acronyms) belongs in the user's `allowlists.acceptedAbbreviations` instead.
     * `InitCommand::DEFAULT_ACCEPTED_ABBREVIATIONS` references this constant so the two cannot drift.
     *
     * @var list<string>
     */
    public const DEFAULT_ACCEPTED_ABBREVIATIONS = [
        'age', 'app', 'db', 'fs', 'id', 'io', 'key', 'log', 'max', 'min', 'now', 'raw', 'rx', 'tx', 'ui', 'url',
    ];

    /**
     * @param array<string, RuleSettings>  $rules - Effective settings keyed by rule id.
     * @param float                        $minimumPhpVersion - Minimum PHP version used by version-sensitive rules.
     * @param RuleSelection                $ruleSelection - Include/exclude rule selection for the run.
     * @param list<string>                 $ignoredPathPatterns - Path patterns skipped during discovery.
     * @param list<string>                 $acceptedAbbreviations - Abbreviations accepted by naming rules.
     * @param list<string>                 $allowedSecretPreviews - Secret previews explicitly allowed by config.
     * @param array<string, FailThreshold> $minimumSeverity - Per-command exit-code thresholds, keyed by command name.
     * @param FailThresholds|null          $failureConditions - Severity-bucketed count gate from failureConditions config, when set.
     *
     * @throws InvalidArgumentException When the PHP version floor is below 7.4.
     */
    public function __construct(
        private array           $rules,
        private float           $minimumPhpVersion = self::DEFAULT_MINIMUM_PHP_VERSION,
        private RuleSelection   $ruleSelection = new RuleSelection(),
        private array           $ignoredPathPatterns = [],
        private array           $acceptedAbbreviations = [],
        private array           $allowedSecretPreviews = [],
        private array           $minimumSeverity = [],
        private ?FailThresholds $failureConditions = null,
    ) {
        if ($this->minimumPhpVersion < 7.4) {
            throw new InvalidArgumentException('Minimum PHP version must be at least 7.4.');
        }
    }

    /**
     * Build default settings for every rule in the registry.
     *
     * @param RuleRegistry $registry - Rule registry supplying default rule definitions.
     *
     * @return self - Config initialised with registry defaults.
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

        return new self($rules, acceptedAbbreviations: self::DEFAULT_ACCEPTED_ABBREVIATIONS);
    }

    /**
     * Return the configured settings for a known rule id.
     *
     * @param string $ruleId - Rule identifier to read.
     *
     * @return RuleSettings - effective settings for the rule; never null since an unknown id throws instead
     * @throws InvalidArgumentException When the rule id is unknown.
     */
    public function ruleSettings(string $ruleId): RuleSettings
    {
        return $this->rules[$ruleId]
               ?? throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
    }

    /**
     * Return a copy with one rule's settings replaced.
     *
     * @param string       $ruleId - Rule identifier to replace.
     * @param RuleSettings $settings - New settings for the rule.
     *
     * @return self - Config carrying the updated rule settings.
     * @throws InvalidArgumentException When the rule id is unknown.
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
            $this->minimumSeverity,
            $this->failureConditions,
        );
    }

    /**
     * Return the minimum PHP version used by version-sensitive rules.
     *
     * @return float - PHP version floor gating version-sensitive rules; always >= 7.4 per the constructor guard
     */
    public function minimumPhpVersion(): float
    {
        return $this->minimumPhpVersion;
    }

    /**
     * Return a copy with a different minimum PHP version.
     *
     * @param float $minimumPhpVersion - New minimum PHP version floor.
     *
     * @return self - Config carrying the updated PHP version floor.
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
            $this->minimumSeverity,
            $this->failureConditions,
        );
    }

    /**
     * Expose rule settings keyed by rule identifier.
     *
     * @return array<string, RuleSettings> - every rule's effective settings keyed by rule id; never empty since the registry seeds one entry per rule
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Return the rule include/exclude selection for this analysis run.
     *
     * @return RuleSelection - include/exclude filters over the rule map; an empty selection runs every enabled rule
     */
    public function ruleSelection(): RuleSelection
    {
        return $this->ruleSelection;
    }

    /**
     * Return a copy with a different rule selection.
     *
     * @param RuleSelection $ruleSelection - Rule include/exclude selection to apply.
     *
     * @return self - Config carrying the updated rule selection.
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
            $this->minimumSeverity,
            $this->failureConditions,
        );
    }

    /**
     * Expose configured path ignore patterns.
     *
     * @return list<string> - glob patterns discovery skips; empty means scan every path the discovery roots reach
     */
    public function ignoredPathPatterns(): array
    {
        return $this->ignoredPathPatterns;
    }

    /**
     * @param list<string> $ignoredPathPatterns - Path patterns skipped during discovery.
     *
     * @return self - Config carrying the updated ignored path patterns.
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
            $this->minimumSeverity,
            $this->failureConditions,
        );
    }

    /**
     * Expose identifier abbreviations allowed by naming rules.
     *
     * @return list<string> - abbreviations naming rules treat as words; the single stored list (built-in seed or the user's wholesale replacement),
     *                      never a merge of both
     */
    public function acceptedAbbreviations(): array
    {
        return $this->acceptedAbbreviations;
    }

    /**
     * @param list<string> $acceptedAbbreviations - Abbreviations accepted by naming rules.
     *
     * @return self - Config carrying the updated accepted abbreviation list.
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
            $this->minimumSeverity,
            $this->failureConditions,
        );
    }

    /**
     * Expose redacted secret previews allowed by sensitive-data rules.
     *
     * @return list<string> - redacted secret previews cleared as false positives; sensitive-data rules suppress findings matching these, empty means
     *                      suppress none
     */
    public function allowedSecretPreviews(): array
    {
        return $this->allowedSecretPreviews;
    }

    /**
     * @param list<string> $allowedSecretPreviews - Secret previews explicitly allowed by config.
     *
     * @return self - Config carrying the updated allowed secret preview list.
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
            $this->minimumSeverity,
            $this->failureConditions,
        );
    }

    /**
     * Return the per-command exit-code threshold for the named gating command.
     *
     * @param string $command - Gating command name (analyse, report, dashboard).
     *
     * @return FailThreshold|null - Configured threshold for the command, or null when unset.
     */
    public function failThresholdFor(string $command): ?FailThreshold
    {
        return $this->minimumSeverity[$command] ?? null;
    }

    /**
     * @param array<string, FailThreshold> $minimumSeverity - Per-command exit-code thresholds keyed by command name.
     *
     * @return self - Config carrying the updated minimumSeverity map.
     */
    public function withMinimumSeverity(array $minimumSeverity): self
    {
        return new self(
            $this->rules,
            $this->minimumPhpVersion,
            $this->ruleSelection,
            $this->ignoredPathPatterns,
            $this->acceptedAbbreviations,
            $this->allowedSecretPreviews,
            $minimumSeverity,
            $this->failureConditions,
        );
    }

    /**
     * Return the severity-bucketed count gate from failureConditions config, when set.
     *
     * @return FailThresholds|null - Configured failure-condition thresholds, or null when unset.
     */
    public function failureConditions(): ?FailThresholds
    {
        return $this->failureConditions;
    }

    /**
     * @param FailThresholds|null $failureConditions - Severity-bucketed count gate to apply, or null to clear it.
     *
     * @return self - Config carrying the updated failure conditions.
     */
    public function withFailureConditions(?FailThresholds $failureConditions): self
    {
        return new self(
            $this->rules,
            $this->minimumPhpVersion,
            $this->ruleSelection,
            $this->ignoredPathPatterns,
            $this->acceptedAbbreviations,
            $this->allowedSecretPreviews,
            $this->minimumSeverity,
            $failureConditions,
        );
    }
}
