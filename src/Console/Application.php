<?php

declare(strict_types=1);

namespace GruffPhp\Console;

use GruffPhp\Command\AnalyseCommand;
use GruffPhp\Command\DashboardCommand;
use GruffPhp\Command\InitCommand;
use GruffPhp\Command\ListRulesCommand;
use GruffPhp\Command\ReportCommand;
use GruffPhp\Command\SummaryCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Registers the gruff-php CLI commands with Symfony Console.
 */
final class Application extends SymfonyApplication
{
    /**
     * Console application name displayed by Symfony.
     */
    public const NAME = 'gruff-php';

    /**
     * Version displayed by the CLI.
     */
    public const VERSION = '0.1.3';

    /**
     * Register the gruff-php CLI command surface with Symfony Console.
     */
    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->addCommands([
            new AnalyseCommand(),
            new DashboardCommand(),
            new InitCommand(),
            new ListRulesCommand(),
            new ReportCommand(),
            new SummaryCommand(),
        ]);
    }
}
