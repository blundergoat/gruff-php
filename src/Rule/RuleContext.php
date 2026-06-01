<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;

/**
 * Supplies project configuration and root path to rule execution.
 */
final readonly class RuleContext
{
    /**
     * Capture project-level context and effective rule configuration.
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
     * Look up effective settings for a rule definition.
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
