<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use GruffPhp\Cli\Command\AnalyseCommandOptions;
use GruffPhp\Cli\Command\MissingConfigPrompt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Starts the local browser UI for `gruff-php dashboard`.
 *
 * Validates the project directory, port, timeout, and scan budget before opening the server.
 * The initial options populate the dashboard for the first browser-driven scan.
 */
final class DashboardCommand extends Command
{
    /**
     * Default dashboard bind host for local-only access.
     */
    private const DEFAULT_HOST = '127.0.0.1';

    /**
     * Default dashboard port used when the user supplies no --port option.
     */
    private const DEFAULT_PORT = 8765;

    /**
     * Registers the paths argument, dashboard-specific options, and help text for gruff-php dashboard.
     *
     * @return void - Configures the dashboard arguments, options, and help text.
     */
    protected function configure(): void
    {
        $this
            ->setName('dashboard')
            ->setDescription('Serve the local gruff-php dashboard.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Initial files or directories to analyse.')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Initial project root for scans.')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Alias for --project.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host for the dashboard server.', default: self::DEFAULT_HOST)
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port for the dashboard server.', default: (string) self::DEFAULT_PORT)
            ->addOption('scan-timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to allow each refresh scan. Use 0 to disable.', default: '120')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the scan: advisory, warning, error, or none.', default: 'none')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Initial gruff YAML config path (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff-php.yaml file for dashboard scans.')
            ->addOption('deep-scan-budget', null, InputOption::VALUE_REQUIRED, 'Bound structural analysis as <lines>:<bytes>, or disable it with off.')
            ->addOption('diff', null, InputOption::VALUE_NONE, 'Start the dashboard in diff-only scan mode.')
            ->addOption(
                'baseline',
                null,
                InputOption::VALUE_OPTIONAL,
                'Initial gruff baseline JSON path. Defaults to "gruff-baseline.json" at the project root when present.',
            )
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Skip auto-applying the default baseline file for dashboard scans.')
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Scan ignored files by using filesystem traversal instead of Git/default ignores.');
    }

    /**
     * Validates the launch flags and starts the dashboard server when the user runs `gruff-php dashboard`.
     *
     * @param InputInterface  $input  - Parsed dashboard arguments and options: paths, host, port, and scan flags.
     * @param OutputInterface $output - Destination for validation error messages shown before the server starts.
     *
     * @return int - Validation failure status, or the dashboard server's exit status after startup.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd();

        // No resolvable working directory means paths can't be resolved, so fail before binding a server.
        if ($cwd === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        $dashboardStateFactory = new DashboardStateFactory();
        $projectRoot           = $dashboardStateFactory->initialProjectRoot($input, $cwd);


        // The dashboard needs a real project directory to open its initial scan.
        if ($projectRoot === null) {
            $output->writeln('<error>Initial --project/--project-root must resolve to an existing directory.</error>');

            return Command::INVALID;
        }

        $port = $this->port($input, $output);


        // The port parser has already told the caller how to choose a valid browser endpoint.
        if ($port === false) {
            return Command::INVALID;
        }

        $scanTimeout = $this->scanTimeout($input, $output);


        // Reject an invalid timeout before the browser server starts.
        if ($scanTimeout === false) {
            return Command::INVALID;
        }

        $budget         = $input->getOption('deep-scan-budget');
        $budgetOverride = AnalyseCommandOptions::parseDeepScanBudgetOverride(is_string($budget) ? $budget : null);
        // Reject an invalid scan budget before offering to create a config or starting the server.
        if (is_string($budgetOverride)) {
            $output->writeln('<error>' . $budgetOverride . '</error>');

            return Command::INVALID;
        }

        $promptExitCode = MissingConfigPrompt::maybeOffer(
            input:              $input,
            output:             $output,
            symfonyApplication: $this->getApplication(),
            projectRoot:        $projectRoot,
            explicitConfigPath: $dashboardStateFactory->optionalStringOption($input, 'config'),
            shouldSkipConfig:   (bool) $input->getOption('no-config'),
        );

        // Finish the config prompt before opening the dashboard. A failed prompt already carries its exit code.
        if ($promptExitCode !== null) {
            return $promptExitCode;
        }

        $host                    = $dashboardStateFactory->optionalStringOption($input, 'host') ?? self::DEFAULT_HOST;
        $dashboardRequestContext = new DashboardRequestContext($input, $cwd, $projectRoot, $scanTimeout, $host, $port);
        $dashboardServer         = new DashboardServer($dashboardStateFactory, $this->gruffBinary());

        return $dashboardServer->serve($output, $host, $port, $dashboardRequestContext);
    }

    /**
     * Validates that `--port` is a decimal integer in the range 1-65535, reporting invalid input.
     *
     * @param InputInterface  $input  - Source of the raw --port option, expected to be a digit string.
     * @param OutputInterface $output - Destination for errors about the option's syntax or range.
     *
     * @return int|false - Port number in range, or false after reporting invalid input; availability is not checked.
     */
    private function port(InputInterface $input, OutputInterface $output): int|false
    {
        $rawPort = $input->getOption('port');


        // Accept only decimal port numbers so the browser address matches the caller's request.
        if (!is_string($rawPort) || !ctype_digit($rawPort)) {
            $output->writeln('<error>--port must be an integer from 1 to 65535.</error>');

            return false;
        }

        $port = (int) $rawPort;


        // Reject ports outside the TCP range before asking the server to bind.
        if ($port < 1 || $port > 65535) {
            $output->writeln('<error>--port must be an integer from 1 to 65535.</error>');

            return false;
        }

        return $port;
    }

    /**
     * Validates `--scan-timeout`: 0 disables the per-scan limit, other values become a second budget.
     *
     * @param InputInterface  $input  - Source of the raw --scan-timeout option, expected to be a non-negative integer.
     * @param OutputInterface $output - Destination for the validation error shown when the timeout is invalid.
     *
     * @return float|false|null - Timeout seconds; null when the user disabled it with 0, false when the value was invalid.
     */
    private function scanTimeout(InputInterface $input, OutputInterface $output): float|false|null
    {
        $rawTimeout = $input->getOption('scan-timeout');


        // A negative or nonnumeric timeout has no valid scan meaning and is rejected before starting the server.
        if (!is_string($rawTimeout) || !ctype_digit($rawTimeout)) {
            $output->writeln('<error>--scan-timeout must be a non-negative integer.</error>');

            return false;
        }

        $timeout = (int) $rawTimeout;

        // The user passed 0, the documented "no timeout" sentinel (null); any other value is a per-scan second budget.
        return $timeout === 0 ? null : (float) $timeout;
    }

    /**
     * Resolves this package's own gruff-php binary, so dashboard scans run the same build as the server.
     *
     * @return string - Absolute gruff-php binary path.
     */
    private function gruffBinary(): string
    {

        return dirname(__DIR__, 3) . '/bin/gruff-php';
    }
}
