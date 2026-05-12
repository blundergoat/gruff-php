<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Console\Application;
use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\OutputFormat;
use GruffPhp\Rule\RuleRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Builds validated analyse command setup from console input.
 */
final readonly class AnalyseCommandSetupBuilder
{
    /**
     * Build the validated analysis setup from console input.
     *
     * @return AnalyseCommandSetupResult Ready setup or formatted usage/config error.
     */
    public function build(InputInterface $input): AnalyseCommandSetupResult
    {
        $projectRoot = getcwd();

        if ($projectRoot === false) {
            return AnalyseCommandSetupResult::plainError(
                '<error>Unable to determine current working directory.</error>',
                Command::FAILURE,
            );
        }

        $options = AnalyseCommandOptions::fromInput($input);
        if ($options->usageError() === '--no-config cannot be combined with --config.') {
            return AnalyseCommandSetupResult::plainError(
                '<error>--no-config cannot be combined with --config.</error>',
                Command::INVALID,
            );
        }

        $formatResult = $this->format($input->getOption('format'));
        if (!$formatResult instanceof OutputFormat) {
            return AnalyseCommandSetupResult::plainError($formatResult, Command::INVALID);
        }

        $failThreshold = $this->failThreshold($input->getOption('fail-on'));
        if (!$failThreshold instanceof FailThreshold) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport(
                    $options,
                    $formatResult,
                    $failThreshold,
                    sprintf('Unsupported fail threshold "%s". Use advisory, warning, error, or none.', $failThreshold),
                ),
                $formatResult,
            );
        }

        $mutationBudget = $this->mutationBudget($input->getOption('mutation-budget'));
        if ($mutationBudget === false) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport(
                    $options,
                    $formatResult,
                    $failThreshold->value,
                    'Unsupported mutation budget. Use a non-negative integer.',
                ),
                $formatResult,
            );
        }

        $options = $options->withMutationBudget($mutationBudget);
        $usageError = $options->usageError();
        if ($usageError !== null) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport($options, $formatResult, $failThreshold->value, $usageError),
                $formatResult,
            );
        }

        $options = $options->withDefaultBaseline($projectRoot);
        $registry = RuleRegistry::defaults();
        $configLoader = new ConfigLoader($projectRoot, ConfigLoader::packageRoot());
        $configResult = $this->config($options, $registry, $formatResult, $failThreshold, $configLoader);
        if ($configResult instanceof AnalysisReport) {
            return AnalyseCommandSetupResult::reportError($configResult, $formatResult);
        }

        return AnalyseCommandSetupResult::ready(new AnalyseCommandSetup(
            projectRoot: $projectRoot,
            options: $options,
            format: $formatResult,
            failThreshold: $failThreshold,
            config: $configResult,
            configPath: $this->effectiveConfigPath($options, $configLoader),
            registry: $registry,
        ));
    }

    /**
     * Parse the requested output format.
     *
     * @return OutputFormat|string Parsed format, or a formatted usage error string.
     */
    private function format(mixed $value): OutputFormat|string
    {
        $rawValue = is_string($value) ? $value : OutputFormat::Text->value;
        $format = OutputFormat::fromInput($rawValue);

        return $format ?? sprintf(
            '<error>USAGE-ERROR Unsupported output format "%s". Use text, json, html, markdown, github, hotspot, or sarif.</error>',
            $rawValue,
        );
    }

    /**
     * Parse the requested failure threshold.
     *
     * @return FailThreshold|string Parsed threshold, or the unsupported raw value.
     */
    private function failThreshold(mixed $value): FailThreshold|string
    {
        $rawValue = is_string($value) ? $value : FailThreshold::Error->value;

        return FailThreshold::fromInput($rawValue) ?? $rawValue;
    }

    /**
     * Parse the optional mutation finding budget.
     *
     * @return int|false|null Non-negative budget, false for invalid input, or null when omitted.
     */
    private function mutationBudget(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : false;
    }

    /**
     * Load analysis configuration or convert configuration failures to a report.
     *
     * @return AnalysisConfig|AnalysisReport Loaded config or a formatted config error report.
     */
    private function config(
        AnalyseCommandOptions $options,
        RuleRegistry $registry,
        OutputFormat $format,
        FailThreshold $failThreshold,
        ConfigLoader $configLoader,
    ): AnalysisConfig|AnalysisReport {
        try {
            return $options->noConfig
                ? AnalysisConfig::fromRegistry($registry)
                : $configLoader->load($options->configPath, $registry);
        } catch (ConfigException $exception) {
            return $this->usageReport($options, $format, $failThreshold->value, $exception->getMessage(), 'config-error');
        }
    }

    /**
     * Resolve the config path that should be reported for this run.
     *
     * @return string|null Resolved path, explicit path, or null when config loading is disabled.
     */
    private function effectiveConfigPath(AnalyseCommandOptions $options, ConfigLoader $configLoader): ?string
    {
        if ($options->noConfig) {
            return null;
        }

        return $options->configPath ?? $configLoader->resolveConfigPath(null);
    }

    /**
     * Build a zero-finding report for CLI usage and configuration errors.
     *
     * @return AnalysisReport Report carrying the diagnostic and invalid exit code.
     */
    private function usageReport(
        AnalyseCommandOptions $options,
        OutputFormat $format,
        string $failOn,
        string $message,
        string $type = 'usage-error',
    ): AnalysisReport {
        return new AnalysisReport(
            toolVersion: Application::VERSION,
            requestedPaths: $options->paths,
            format: $format->value,
            failOn: $failOn,
            filesDiscovered: 0,
            filesParsed: 0,
            ignoredPaths: [],
            missingPaths: [],
            diagnostics: [new RunDiagnostic($type, $message)],
            findings: [],
            exitCode: Command::INVALID,
            configPath: $options->configPath,
        );
    }
}
