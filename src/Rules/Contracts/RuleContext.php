<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Contracts;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;

/**
 * The per-run context handed to every rule as it executes, carrying the project root and the effective
 * config so a rule can read its own thresholds and options.
 *
 * Rules never load config themselves; they ask this object. It bundles the analysis root (for resolving
 * paths) with the merged AnalysisConfig, and settingsFor() hands back the enabled flag, thresholds, and
 * options that apply to one rule once defaults and any user override are combined.
 */
final readonly class RuleContext
{
    /**
     * Captures the project root and effective config for one analysis run.
     *
     * @param string         $projectRoot - Project root for the analysis run.
     * @param AnalysisConfig $config - Effective analysis configuration.
     */
    public function __construct(
        public string $projectRoot,
        public AnalysisConfig $config,
    ) {
    }

    /**
     * Hands a rule the effective settings - enabled, thresholds, options - that apply to it this run.
     *
     * @param RuleDefinition $definition - Rule definition whose settings should be read.
     *
     * @return RuleSettings - Enabled flag, thresholds, and options for the rule.
     */
    public function settingsFor(RuleDefinition $definition): RuleSettings
    {
        // Resolution is by the definition's id; config owns merging defaults with any per-rule override.
        return $this->config->ruleSettings($definition->id);
    }
}
