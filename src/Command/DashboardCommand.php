<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Serves the local browser dashboard for interactive analysis.
 */
final class DashboardCommand extends Command
{
    /**
     * Default dashboard bind host for local-only access.
     */
    private const DEFAULT_HOST = '127.0.0.1';

    /**
     * Default dashboard port used when no port option is supplied.
     */
    private const DEFAULT_PORT = 8765;

    /**
     * Configure the dashboard command arguments and options.
     *
     * @return void
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
     * Validate dashboard options and start the local dashboard server.
     *
     * @param InputInterface  $input  Parsed dashboard arguments and options: paths, host, port, and scan flags.
     * @param OutputInterface $output Destination for validation error messages shown before the server starts.
     * @return int Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd();

        if ($cwd === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            // The process has no resolvable working directory, so path resolution is impossible; fail before binding.
            return Command::FAILURE;
        }

        $dashboardStateFactory = new DashboardStateFactory();
        $projectRoot           = $dashboardStateFactory->initialProjectRoot($input, $cwd);

        if ($projectRoot === null) {
            $output->writeln('<error>Initial --project/--project-root must resolve to an existing directory.</error>');

            // A non-existent project root is operator misconfiguration, not a runtime fault, so reject as invalid.
            return Command::INVALID;
        }

        $port = $this->port($input, $output);

        if ($port === false) {
            // port() already reported the reason; false signals an invalid --port value, so reject as invalid usage.
            return Command::INVALID;
        }

        $scanTimeout = $this->scanTimeout($input, $output);

        if ($scanTimeout === false) {
            // scanTimeout() already reported the reason; false signals an invalid --scan-timeout, so reject as invalid.
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
        if ($promptExitCode !== null) {
            // A non-null code means the missing-config prompt resolved the run itself, so honour its exit code.
            return $promptExitCode;
        }

        $host                    = $dashboardStateFactory->optionalStringOption($input, 'host') ?? self::DEFAULT_HOST;
        $dashboardRequestContext = new DashboardRequestContext($input, $cwd, $projectRoot, $scanTimeout, $host, $port);
        $dashboardServer         = new DashboardServer($dashboardStateFactory, $this->gruffBinary());

        // Hand back the server's own exit code; it blocks serving requests until the dashboard is stopped.
        return $dashboardServer->serve($output, $host, $port, $dashboardRequestContext);
    }

    /**
     * Parse and validate the dashboard port option.
     *
     * @param InputInterface  $input  Source of the raw --port option, expected to be a digit string.
     * @param OutputInterface $output Destination for the validation error shown when the port is out of range.
     * @return int|false Valid port, or false when input is invalid.
     */
    private function port(InputInterface $input, OutputInterface $output): int|false
    {
        $rawPort = $input->getOption('port');

        if (!is_string($rawPort) || !ctype_digit($rawPort)) {
            $output->writeln('<error>--port must be an integer from 1 to 65535.</error>');

            // A non-digit option cannot be a TCP port, so signal invalid input rather than coercing to 0.
            return false;
        }

        $port = (int) $rawPort;

        if ($port < 1 || $port > 65535) {
            $output->writeln('<error>--port must be an integer from 1 to 65535.</error>');

            // Ports outside 1-65535 are unbindable, so reject them instead of letting the bind fail later.
            return false;
        }

        // Hand back the validated port, guaranteed to be within the bindable 1-65535 range.
        return $port;
    }

    /**
     * Parse and validate the dashboard scan timeout option.
     *
     * @param InputInterface  $input  Source of the raw --scan-timeout option, expected to be a non-negative integer.
     * @param OutputInterface $output Destination for the validation error shown when the timeout is invalid.
     * @return float|false|null Timeout seconds, null for disabled timeout, or false for invalid input.
     */
    private function scanTimeout(InputInterface $input, OutputInterface $output): float|false|null
    {
        $rawTimeout = $input->getOption('scan-timeout');

        if (!is_string($rawTimeout) || !ctype_digit($rawTimeout)) {
            $output->writeln('<error>--scan-timeout must be a non-negative integer.</error>');

            // A non-digit option cannot be a timeout, so signal invalid input distinctly from the 0 disable value.
            return false;
        }

        $timeout = (int) $rawTimeout;

        // Treat 0 as the documented "no timeout" sentinel (null); any other value becomes a per-scan second budget.
        return $timeout === 0 ? null : (float) $timeout;
    }

    /**
     * Return the package-local gruff-php executable path.
     *
     * @return string Absolute gruff-php binary path.
     */
    private function gruffBinary(): string
    {
        // Resolve the binary relative to this file so child scans use this package's gruff-php, not one on PATH.
        return dirname(__DIR__, 2) . '/bin/gruff-php';
    }
}
