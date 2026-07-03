<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Output\Reporter\FailThreshold;
use GruffPhp\Output\Reporter\FailThresholds;
use GruffPhp\Output\Reporter\OutputFormat;
use GruffPhp\Rules\RuleRegistry;

/**
 * Immutable bundle of everything the `analyse` command needs to run one scan.
 *
 * Built once the command has validated the user's flags and loaded config: it carries the project
 * root, parsed options, output format, the fail thresholds that decide the exit code, the effective
 * config, and the rule registry — so the analysis pipeline runs without re-reading CLI input.
 */
final readonly class AnalyseCommandSetup
{
    /**
     * Capture resolved dependencies and options needed to execute analyse.
     *
     * @param string                $projectRoot    - Absolute project root used for path resolution.
     * @param AnalyseCommandOptions $options        - Validated analyse command options.
     * @param OutputFormat          $format         - Reporter format selected for output.
     * @param FailThreshold         $failThreshold  - Legacy severity threshold retained for display/back-compat.
     * @param FailThresholds        $failThresholds - Resolved count-gate thresholds that decide the exit code.
     * @param AnalysisConfig        $config         - Effective analysis configuration.
     * @param string|null           $configPath     - Config path used to build the setup; null when the run used no config file.
     * @param RuleRegistry          $registry       - Rule registry used for the analysis run.
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
