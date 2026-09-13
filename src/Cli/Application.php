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
 * The gruff-php CLI entry point - wires every subcommand into Symfony Console.
 *
 * This is what `bin/gruff-php` boots: it registers the commands a user can type (`analyse`,
 * `summary`, `report`, `dashboard`, `init`, `hook`, `list-rules`, `check-ignore`) and stamps the
 * tool name and version shown in `--help` and on every report header.
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
    public const VERSION = '0.5.2';

    /**
     * Registers the full command surface so every `gruff-php <command>` a user types is wired up.
     */
    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        // Every subcommand a user can run; each entry becomes one `gruff-php <name>` invocation.
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
     * Runs the requested command, turning any command-line the CLI cannot accept into exit 2.
     *
     * Symfony reports an unknown flag or a stray operand with exit 1, which a CI gate reads as "findings were found".
     * FAMILY-CONTRACT.md section 7 reserves 1 for a completed run whose findings reached the gate, so a run that never
     * happened has to be distinguishable from one that did.
     *
     * @param InputInterface  $input  - The parsed command line.
     * @param OutputInterface $output - Destination Symfony renders the error to.
     *
     * @return int - The command's own exit code; a usage error never reaches here and surfaces as exit 2 instead.
     * @throws ConsoleRuntimeException When the command line names an unknown option, an unknown command, or an unexpected operand.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ConsoleException $usageError) {
            // The message is Symfony's and stays verbatim; only the exit code changes, so the user reads the same text.
            throw new ConsoleRuntimeException($usageError->getMessage(), 2, $usageError);
        }
    }
}
