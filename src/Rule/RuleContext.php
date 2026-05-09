<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;

final readonly class RuleContext
{
    public function __construct(
        public string $projectRoot,
        public AnalysisConfig $config,
    ) {
    }

    public function settingsFor(RuleDefinition $definition): RuleSettings
    {
        return $this->config->ruleSettings($definition->id);
    }
}
