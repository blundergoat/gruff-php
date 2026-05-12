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
     */
    public function __construct(
        public string $projectRoot,
        public AnalysisConfig $config,
    ) {
    }

    /**
     * Look up effective settings for a rule definition.
     *
     * @return RuleSettings Enabled flag, thresholds, and options for the rule.
     */
    public function settingsFor(RuleDefinition $definition): RuleSettings
    {
        return $this->config->ruleSettings($definition->id);
    }
}
