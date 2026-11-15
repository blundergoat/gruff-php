<?php

declare(strict_types=1);

namespace GruffPhp\Cli;

use GruffPhp\Cli\Command\AnalyseCommand;
use GruffPhp\Cli\Command\CheckIgnoreCommand;
use GruffPhp\Cli\Dashboard\DashboardCommand;
use GruffPhp\Cli\Command\HookCommand;
use GruffPhp\Cli\Command\InitCommand;
use GruffPhp\Cli\Command\MigrateConfigCommand;
use GruffPhp\Cli\Command\ListRulesCommand;
use GruffPhp\Cli\Command\ReportCommand;
use GruffPhp\Cli\Command\SummaryCommand;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registers the gruff-php command set with Symfony Console.
 * Supplies the name and version used by the console application.
 * Rewraps escaping Symfony Console exceptions with code 2 while retaining their messages.
 */
final class Application extends SymfonyApplication
{
    /**
     * Console application name shown by Symfony and printed on report headers.
     */
    public const NAME = 'gruff-php';

    /**
     * Version shown by `--version` and stamped onto every report the user sees.
     */
    public const VERSION = '0.6.0';

    /**
     * Registers the Gruff commands available through the gruff-php entry point.
     */
    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->addCommands([
            new AnalyseCommand(),
            new CheckIgnoreCommand(),
            new DashboardCommand(),
            new HookCommand(),
            new InitCommand(),
            new MigrateConfigCommand(),
            new ListRulesCommand(),
            new ReportCommand(),
            new SummaryCommand(),
        ]);
    }

    /**
     * Runs the requested command and rethrows Symfony Console exceptions with code 2.
     * Preserves each exception's message and cause for the caller.
     *
     * @param InputInterface $input - Command line passed to Symfony for command selection and execution.
     * @param OutputInterface $output - Destination passed to Symfony for command output.
     *
     * @return int - Exit code returned by Symfony when no Console exception escapes.
     * @throws ConsoleRuntimeException - When a Console exception escapes command selection or execution.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ConsoleException $usageError) {
            // Symfony's message stays verbatim while the wrapper sets exit code 2 and retains the original exception as its cause.
            throw new ConsoleRuntimeException($usageError->getMessage(), 2, $usageError);
        }
    }
}
