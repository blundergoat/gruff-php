<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\FailThresholds;
use GruffPhp\Reporting\OutputFormat;
use GruffPhp\Rule\RuleRegistry;

/**
 * Carries resolved dependencies and options needed to execute analysis.
 */
final readonly class AnalyseCommandSetup
{
    /**
     * Capture resolved dependencies and options needed to execute analyse.
     *
     * @param string                $projectRoot   Absolute project root used for path resolution.
     * @param AnalyseCommandOptions $options       Validated analyse command options.
     * @param OutputFormat          $format        Reporter format selected for output.
     * @param FailThreshold         $failThreshold  Legacy severity threshold retained for display/back-compat.
     * @param FailThresholds        $failThresholds Resolved count-gate thresholds that decide the exit code.
     * @param AnalysisConfig        $config         Effective analysis configuration.
     * @param string|null           $configPath     Config path used to build the setup, when any.
     * @param RuleRegistry          $registry       Rule registry used for the analysis run.
     */
    public function __construct(
        public string $projectRoot,
        public AnalyseCommandOptions $options,
        public OutputFormat $format,
        public FailThreshold $failThreshold,
        public FailThresholds $failThresholds,
        public AnalysisConfig $config,
        public ?string $configPath,
        public RuleRegistry $registry,
    ) {
    }
}
