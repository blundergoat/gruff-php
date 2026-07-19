<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Output\Reporter\FailThreshold;
use GruffPhp\Output\Reporter\FailThresholds;
use GruffPhp\Rules\RuleRegistry;
use InvalidArgumentException;

/**
 * The single, resolved configuration a run analyses against, after defaults and the user's config file
 * have been merged.
 *
 * Everything the user can tune - which rules run and at what thresholds, the PHP version floor, ignore
 * patterns, naming allowlists, secret allowances, and the exit-code gates - ends up here as one
 * immutable object the whole run reads from. It is built from registry defaults (`fromRegistry`) then
 * layered with the user's settings; the `with*()` methods return adjusted copies rather than mutating,
 * so config stays a stable snapshot for the duration of a run.
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
        'age', 'app', 'db', 'dto', 'fs', 'id', 'io', 'key', 'log', 'max', 'min', 'now', 'raw', 'rx', 'tx', 'ui', 'url', 'utc',
    ];

    /**
     * Assembles the effective config from resolved rule settings and run-wide options, refusing a PHP
     * floor gruff cannot support.
     *
     * @param array<string, RuleSettings>  $rules - Effective settings keyed by rule id.
     * @param float                        $minimumPhpVersion - Minimum PHP version used by version-sensitive rules.
     * @param RuleSelection                $ruleSelection - Include/exclude rule selection for the run.
     * @param list<string>                 $ignoredPathPatterns - Path patterns skipped during discovery.
     * @param list<string>                 $acceptedAbbreviations - Abbreviations accepted by naming rules.
     * @param list<string>                 $allowedSecretPreviews - Secret previews explicitly allowed by config.
     * @param array<string, FailThreshold> $minimumSeverity - Per-command exit-code thresholds, keyed by command name.
     * @param FailThresholds|null          $failureConditions - Severity-bucketed count gate from failureConditions config; null when the user set none.
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
        // gruff cannot reason about PHP older than 7.4, so a lower floor is rejected outright.
        if ($this->minimumPhpVersion < 7.4) {
            throw new InvalidArgumentException('Minimum PHP version must be at least 7.4.');
        }
    }

    /**
     * Builds a config from registry defaults, giving every rule its out-of-the-box settings - the
     * starting point the user's config file is then layered onto.
     *
     * @param RuleRegistry $registry - Rule registry supplying default rule definitions.
     *
     * @return self - Config initialised with registry defaults.
     */
    public static function fromRegistry(RuleRegistry $registry): self
    {
        $rules = [];

        // Seed each registered rule with its own default enablement, thresholds, and options.
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
     * Reads one rule's effective settings, failing loudly on an unknown id since that means a caller
     * bug rather than user input.
     *
     * @param string $ruleId - Rule identifier to read.
     *
     * @return RuleSettings - effective settings for the rule; never null, since an unknown id throws instead.
     * @throws InvalidArgumentException When the rule id is unknown.
     */
    public function ruleSettings(string $ruleId): RuleSettings
    {
        return $this->rules[$ruleId]
               // An unknown rule id means a caller bug, so throw rather than invent settings.
               ?? throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
    }

    /**
     * Returns a copy with one rule's settings swapped in, used as config is layered rule by rule.
     *
     * @param string       $ruleId - Rule identifier to replace.
     * @param RuleSettings $settings - New settings for the rule.
     *
     * @return self - Config carrying the updated rule settings.
     * @throws InvalidArgumentException When the rule id is unknown.
     */
    public function withRuleSettings(string $ruleId, RuleSettings $settings): self
    {
        // Only a rule that already exists can be overridden; an unknown id is a caller bug.
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
     * Reads the PHP version floor that version-sensitive rules grade against.
     *
     * @return float - PHP version floor gating version-sensitive rules; always >= 7.4 per the constructor guard.
     */
    public function minimumPhpVersion(): float
    {
        return $this->minimumPhpVersion;
    }

    /**
     * Returns a copy with a different PHP version floor, as set from the user's config.
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
     * Exposes every rule's effective settings, keyed by rule id - what the run iterates to execute rules.
     *
     * @return array<string, RuleSettings> - every rule's effective settings keyed by rule id; never empty, since the registry seeds one entry per rule.
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Reads the include/exclude selection that narrows which rules run.
     *
     * @return RuleSelection - include/exclude filters over the rule map; an empty selection runs every enabled rule.
     */
    public function ruleSelection(): RuleSelection
    {
        return $this->ruleSelection;
    }

    /**
     * Returns a copy with a different rule selection applied.
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
     * Reads the glob patterns discovery skips, so a run only ever scans what the user wants scanned.
     *
     * @return list<string> - glob patterns discovery skips; empty means scan every path the discovery roots reach.
     */
    public function ignoredPathPatterns(): array
    {
        return $this->ignoredPathPatterns;
    }

    /**
     * Returns a copy with a different set of ignore patterns.
     *
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
     * Reads the abbreviations naming rules treat as whole words, so a project's known short forms are
     * not flagged as bad names.
     *
     * @return list<string> - abbreviations naming rules treat as words; the single stored list (built-in seed or the user's wholesale replacement),
     *                      never a merge of both.
     */
    public function acceptedAbbreviations(): array
    {
        return $this->acceptedAbbreviations;
    }

    /**
     * Returns a copy with a different accepted-abbreviation list.
     *
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
     * Reads the redacted secret previews the user has cleared, so sensitive-data rules stop flagging
     * the known false positives.
     *
     * @return list<string> - redacted secret previews cleared as false positives; sensitive-data rules suppress findings matching these, empty means
     *                      suppress none.
     */
    public function allowedSecretPreviews(): array
    {
        return $this->allowedSecretPreviews;
    }

    /**
     * Returns a copy with a different allowed-secret-preview list.
     *
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
     * Reads the exit-code threshold configured for a gating command, or null when that command has none.
     *
     * @param string $command - Gating command name (analyse, report, dashboard).
     *
     * @return FailThreshold|null - Configured threshold for the command; null when the user set none, so the command's built-in default applies.
     */
    public function failThresholdFor(string $command): ?FailThreshold
    {
        // No configured threshold for this command means its built-in default gate applies.
        return $this->minimumSeverity[$command] ?? null;
    }

    /**
     * Returns a copy with a different per-command severity-gate map.
     *
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
     * Reads the severity-bucketed count gate from failureConditions config, when the user set one.
     *
     * @return FailThresholds|null - Configured failure-condition thresholds; null when the user set none.
     */
    public function failureConditions(): ?FailThresholds
    {
        return $this->failureConditions;
    }

    /**
     * Returns a copy with a different failure-condition gate, or none.
     *
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
