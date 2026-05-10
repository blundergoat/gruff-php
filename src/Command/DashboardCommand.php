<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DashboardCommand extends Command
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 8765;

    protected function configure(): void
    {
        $this
            ->setName('dashboard')
            ->setDescription('Serve the local gruff dashboard.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Initial files or directories to analyse.')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Initial project root for scans.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host for the dashboard server.', self::DEFAULT_HOST)
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port for the dashboard server.', (string) self::DEFAULT_PORT)
            ->addOption('scan-timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to allow each refresh scan. Mutation runs are not timed out. Use 0 to disable.', '120')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the scan: advisory, warning, error, or none.', 'none')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Initial gruff JSON config path.')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff.json file for dashboard scans.')
            ->addOption(
                'baseline',
                null,
                InputOption::VALUE_OPTIONAL,
                'Initial gruff baseline JSON path. Defaults to "gruff-baseline.json" at the project root when present.',
            )
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Skip auto-applying the default baseline file for dashboard scans.')
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Include files under default ignored directories.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd();

        if ($cwd === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        $stateFactory = new DashboardStateFactory();
        $projectRoot = $stateFactory->initialProjectRoot($input, $cwd);

        if ($projectRoot === null) {
            $output->writeln('<error>Initial --project must resolve to an existing directory.</error>');

            return Command::INVALID;
        }

        $port = $this->port($input, $output);

        if ($port === false) {
            return Command::INVALID;
        }

        $scanTimeout = $this->scanTimeout($input, $output);

        if ($scanTimeout === false) {
            return Command::INVALID;
        }

        $host = $stateFactory->optionalStringOption($input, 'host') ?? self::DEFAULT_HOST;
        $context = new DashboardRequestContext($input, $cwd, $projectRoot, $scanTimeout);
        $server = new DashboardServer($stateFactory, $this->gruffBinary());

        return $server->serve($output, $host, $port, $context);
    }

    private function port(InputInterface $input, OutputInterface $output): int|false
    {
        $rawPort = $input->getOption('port');

        if (!is_string($rawPort) || !ctype_digit($rawPort)) {
            $output->writeln('<error>--port must be an integer from 1 to 65535.</error>');

            return false;
        }

        $port = (int) $rawPort;

        if ($port < 1 || $port > 65535) {
            $output->writeln('<error>--port must be an integer from 1 to 65535.</error>');

            return false;
        }

        return $port;
    }

    private function scanTimeout(InputInterface $input, OutputInterface $output): float|false|null
    {
        $rawTimeout = $input->getOption('scan-timeout');

        if (!is_string($rawTimeout) || !ctype_digit($rawTimeout)) {
            $output->writeln('<error>--scan-timeout must be a non-negative integer.</error>');

            return false;
        }

        $timeout = (int) $rawTimeout;

        return $timeout === 0 ? null : (float) $timeout;
    }

    private function gruffBinary(): string
    {
        return dirname(__DIR__, 2) . '/bin/gruff';
    }
}
