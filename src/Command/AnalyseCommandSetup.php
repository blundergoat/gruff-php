<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\OutputFormat;
use GruffPhp\Rule\RuleRegistry;

final readonly class AnalyseCommandSetup
{
    /**
     * Capture resolved dependencies and options needed to execute analyse.
     */
    public function __construct(
        public string $projectRoot,
        public AnalyseCommandOptions $options,
        public OutputFormat $format,
        public FailThreshold $failThreshold,
        public AnalysisConfig $config,
        public ?string $configPath,
        public RuleRegistry $registry,
    ) {
    }
}
