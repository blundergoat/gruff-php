<?php

declare(strict_types=1);

namespace GruffPhp\Cli;

use GruffPhp\Cli\Command\AnalyseCommand;
use GruffPhp\Cli\Command\CheckIgnoreCommand;
use GruffPhp\Cli\Dashboard\DashboardCommand;
use GruffPhp\Cli\Command\HookCommand;
use GruffPhp\Cli\Command\InitCommand;
use GruffPhp\Cli\Command\ListRulesCommand;
use GruffPhp\Cli\Command\ReportCommand;
use GruffPhp\Cli\Command\SummaryCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * The gruff-php CLI entry point — wires every subcommand into Symfony Console.
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
    public const VERSION = '0.5.0';

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
            new ListRulesCommand(),
            new ReportCommand(),
            new SummaryCommand(),
        ]);
    }
}
