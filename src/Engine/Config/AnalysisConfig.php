<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Output\Reporter\FailThreshold;
use GruffPhp\Output\Reporter\FailThresholds;
use GruffPhp\Rules\RuleRegistry;
use InvalidArgumentException;

/**
 * Holds the resolved settings used throughout one user analysis after defaults and file configuration are merged.
 *
 * It carries rules, thresholds, PHP version, paths, naming, migration fields, and exit gates.
 * `with*()` methods return copies, keeping the user's effective configuration stable for the entire run.
 */
final readonly class AnalysisConfig
{
    /**
     * Default PHP version floor for version-sensitive rules.
     */
    public const DEFAULT_MINIMUM_PHP_VERSION = 8.3;

    /** Default line ceiling for structural PHP analysis. */
    public const DEFAULT_DEEP_SCAN_MAX_LINES = 20_000;

    /** Default byte ceiling for structural PHP analysis. */
    public const DEFAULT_DEEP_SCAN_MAX_BYTES = 2_000_000;

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
     * @param array<string, RuleSettings>                                                            $rules                 - Effective settings keyed by rule id.
     * @param float                                                                                  $minimumPhpVersion     - Minimum PHP version used by version-sensitive rules.
     * @param RuleSelection                                                                          $ruleSelection         - Include/exclude rule selection for the run.
     * @param list<string>                                                                           $ignoredPathPatterns   - Path patterns skipped during discovery.
     * @param list<string>                                                                           $acceptedAbbreviations - Abbreviations accepted by naming rules.
     * @param list<string>                                                                           $allowedSecretPreviews - Legacy field; empty means no migration value, and entries have no analysis effect.
     * @param array<string, FailThreshold>                                                           $minimumSeverity       - Per-command exit-code thresholds, keyed by command name.
     * @param FailThresholds|null                                                                    $failureConditions     - Severity count gate; null means the user configured no failureConditions block.
     * @param list<SensitiveExclusion>                                                               $sensitiveExclusions   - Reviewed sensitive-data exclusions in configuration order; empty means nothing is suppressed.
     * @param array{enabled: bool, maxLines: int, maxBytes: int, override: 'default'|'config'|'cli'} $deepScanBudget        - Effective structural-analysis budget and its winning source.
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
        private array           $sensitiveExclusions = [],
        private array           $deepScanBudget = [
            'enabled' => true,
            'maxLines' => self::DEFAULT_DEEP_SCAN_MAX_LINES,
            'maxBytes' => self::DEFAULT_DEEP_SCAN_MAX_BYTES,
            'override' => 'default',
        ],
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
     * @param string       $ruleId   - Rule identifier to replace.
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
            $this->sensitiveExclusions,
            $this->deepScanBudget,
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
            $this->sensitiveExclusions,
            $this->deepScanBudget,
        );
    }

    /**
     * Exposes every rule's effective settings, keyed by rule id - what the run iterates to execute rules.
     *
     * @return array<string, RuleSettings> - Effective settings keyed by rule id; never empty because the registry seeds every rule
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
            $this->sensitiveExclusions,
            $this->deepScanBudget,
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
            $this->sensitiveExclusions,
            $this->deepScanBudget,
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
            $this->sensitiveExclusions,
            $this->deepScanBudget,
        );
    }

    /**
     * Reads the retained legacy secret-preview list for migration and configuration identity only.
     * Users receive an empty list from every accepted file config; analysis never uses it to hide findings.
     *
     * @return list<string> - retained migration field; empty for every accepted file configuration, never null
     */
    public function allowedSecretPreviews(): array
    {
        return $this->allowedSecretPreviews;
    }

    /**
     * Returns a copy with a different retained legacy secret-preview list for internal migration flows.
     * Empty means no legacy value; non-empty programmatic input remains inert and cannot change the user's findings.
     *
     * @param list<string> $allowedSecretPreviews - Retained migration value; empty or populated, it has no analysis effect and is never null.
     *
     * @return self - Config carrying the supplied migration list; findings remain unchanged when the list is empty or populated
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
            $this->sensitiveExclusions,
            $this->deepScanBudget,
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
            $this->sensitiveExclusions,
            $this->deepScanBudget,
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
            $this->sensitiveExclusions,
            $this->deepScanBudget,
        );
    }

    /**
     * Reads the reviewed sensitive-data exclusions that may hide a finding, each carrying the
     * rationale its author wrote and each counted in the report's audit rows.
     *
     * @return list<SensitiveExclusion> - configured exclusions in configuration order, so a position is its audit index; empty means the run
     *                                  suppresses nothing.
     */
    public function sensitiveExclusions(): array
    {
        return $this->sensitiveExclusions;
    }

    /**
     * Returns a copy carrying a different set of sensitive-data exclusions.
     *
     * @param list<SensitiveExclusion> $sensitiveExclusions - Validated exclusions in configuration order.
     *
     * @return self - Config carrying the updated sensitive-data exclusions.
     */
    public function withSensitiveExclusions(array $sensitiveExclusions): self
    {
        return new self(
            $this->rules,
            $this->minimumPhpVersion,
            $this->ruleSelection,
            $this->ignoredPathPatterns,
            $this->acceptedAbbreviations,
            $this->allowedSecretPreviews,
            $this->minimumSeverity,
            $this->failureConditions,
            $sensitiveExclusions,
            $this->deepScanBudget,
        );
    }

    /**
     * Reads the effective structural-analysis budget and whether defaults, config, or CLI supplied it.
     *
     * @return array{enabled: bool, maxLines: int, maxBytes: int, override: 'default'|'config'|'cli'}
     */
    public function deepScanBudget(): array
    {
        return $this->deepScanBudget;
    }

    /**
     * Returns a copy carrying a validated structural-analysis budget.
     *
     * @param string $override - Source that supplied the effective value; must be default, config, or cli.
     */
    public function withDeepScanBudget(bool $enabled, int $maxLines, int $maxBytes, string $override): self
    {
        if ($maxLines < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException('Deep-scan limits must be positive integers.');
        }

        if (!in_array($override, ['default', 'config', 'cli'], true)) {
            throw new InvalidArgumentException('Deep-scan override must be default, config, or cli.');
        }

        return new self(
            $this->rules,
            $this->minimumPhpVersion,
            $this->ruleSelection,
            $this->ignoredPathPatterns,
            $this->acceptedAbbreviations,
            $this->allowedSecretPreviews,
            $this->minimumSeverity,
            $this->failureConditions,
            $this->sensitiveExclusions,
            [
                'enabled' => $enabled,
                'maxLines' => $maxLines,
                'maxBytes' => $maxBytes,
                'override' => $override,
            ],
        );
    }
}
