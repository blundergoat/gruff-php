<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use GruffPhp\Cli\Command\MissingConfigPrompt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backs the `gruff-php dashboard` command - the interactive browser UI for analysis.
 *
 * Validates the user's launch flags (project root, host, port, scan timeout, config and baseline
 * options), then starts a local HTTP server they drive from a browser: pick paths and options in a
 * form, hit scan, read the rendered report. The interactive alternative to one-shot `analyse`.
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
     * Registers the dashboard command's flags and help - everything the user types after `gruff-php dashboard`.
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
     * Validates the launch flags and starts the dashboard server when the user runs `gruff-php dashboard`.
     *
     * @param InputInterface  $input  - Parsed dashboard arguments and options: paths, host, port, and scan flags.
     * @param OutputInterface $output - Destination for validation error messages shown before the server starts.
     *
     * @return int - Symfony command exit code.
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

        // The user pointed --project at something that isn't a real directory: reject as misconfiguration.
        if ($projectRoot === null) {
            $output->writeln('<error>Initial --project/--project-root must resolve to an existing directory.</error>');

            return Command::INVALID;
        }

        $port = $this->port($input, $output);

        // --port wasn't a valid 1-65535 number (port() already said why): stop as invalid usage.
        if ($port === false) {
            return Command::INVALID;
        }

        $scanTimeout = $this->scanTimeout($input, $output);

        // --scan-timeout wasn't a non-negative integer (scanTimeout() already said why): stop as invalid usage.
        if ($scanTimeout === false) {
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
        // The first-run "create a config?" prompt handled the user itself, so return whatever it decided.
        if ($promptExitCode !== null) {
            return $promptExitCode;
        }

        $host                    = $dashboardStateFactory->optionalStringOption($input, 'host') ?? self::DEFAULT_HOST;
        $dashboardRequestContext = new DashboardRequestContext($input, $cwd, $projectRoot, $scanTimeout, $host, $port);
        $dashboardServer         = new DashboardServer($dashboardStateFactory, $this->gruffBinary());

        return $dashboardServer->serve($output, $host, $port, $dashboardRequestContext);
    }

    /**
     * Validates the `--port` option, returning false (after a message) when it isn't a bindable port.
     *
     * @param InputInterface  $input  - Source of the raw --port option, expected to be a digit string.
     * @param OutputInterface $output - Destination for the validation error shown when the port is out of range.
     *
     * @return int|false - Valid port; false when `--port` wasn't a bindable 1-65535 integer (the reason is already printed).
     */
    private function port(InputInterface $input, OutputInterface $output): int|false
    {
        $rawPort = $input->getOption('port');

        // A non-digit --port (e.g. `--port=abc`) can't be a TCP port, so reject it rather than coerce it to 0.
        if (!is_string($rawPort) || !ctype_digit($rawPort)) {
            $output->writeln('<error>--port must be an integer from 1 to 65535.</error>');

            return false;
        }

        $port = (int) $rawPort;

        // Ports outside 1-65535 can't be bound, so reject now instead of letting the bind fail later.
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

        // A non-digit --scan-timeout can't be a timeout, so flag it invalid, distinct from the 0 "disable" value.
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
        // Resolve the binary relative to this file so child scans use this package's gruff-php, not one on PATH.
        return dirname(__DIR__, 3) . '/bin/gruff-php';
    }
}
