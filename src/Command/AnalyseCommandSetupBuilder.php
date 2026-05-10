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

final readonly class AnalyseCommandSetupBuilder
{
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
        $configResult = $this->config($projectRoot, $options, $registry, $formatResult, $failThreshold);
        if ($configResult instanceof AnalysisReport) {
            return AnalyseCommandSetupResult::reportError($configResult, $formatResult);
        }

        return AnalyseCommandSetupResult::ready(new AnalyseCommandSetup(
            projectRoot: $projectRoot,
            options: $options,
            format: $formatResult,
            failThreshold: $failThreshold,
            config: $configResult,
            registry: $registry,
        ));
    }

    private function format(mixed $value): OutputFormat|string
    {
        $rawValue = is_string($value) ? $value : OutputFormat::Text->value;
        $format = OutputFormat::fromInput($rawValue);

        return $format ?? sprintf(
            '<error>USAGE-ERROR Unsupported output format "%s". Use text, json, html, markdown, github, hotspot, or sarif.</error>',
            $rawValue,
        );
    }

    private function failThreshold(mixed $value): FailThreshold|string
    {
        $rawValue = is_string($value) ? $value : FailThreshold::Error->value;

        return FailThreshold::fromInput($rawValue) ?? $rawValue;
    }

    private function mutationBudget(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : false;
    }

    private function config(
        string $projectRoot,
        AnalyseCommandOptions $options,
        RuleRegistry $registry,
        OutputFormat $format,
        FailThreshold $failThreshold,
    ): AnalysisConfig|AnalysisReport {
        try {
            return $options->noConfig
                ? AnalysisConfig::fromRegistry($registry)
                : (new ConfigLoader($projectRoot))->load($options->configPath, $registry);
        } catch (ConfigException $exception) {
            return $this->usageReport($options, $format, $failThreshold->value, $exception->getMessage(), 'config-error');
        }
    }

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
