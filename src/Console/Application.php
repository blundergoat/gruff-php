<?php

declare(strict_types=1);

namespace GruffPhp\Console;

use GruffPhp\Command\AnalyseCommand;
use GruffPhp\Command\DashboardCommand;
use GruffPhp\Command\ListRulesCommand;
use GruffPhp\Command\ReportCommand;
use GruffPhp\Command\SummaryCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Registers the gruff CLI commands with Symfony Console.
 */
final class Application extends SymfonyApplication
{
    /**
     * Development version displayed by the CLI.
     */
    public const VERSION = '0.1.0-dev';

    /**
     * Register the gruff CLI command surface with Symfony Console.
     */
    public function __construct()
    {
        parent::__construct('gruff', self::VERSION);

        $this->add(new AnalyseCommand());
        $this->add(new DashboardCommand());
        $this->add(new ListRulesCommand());
        $this->add(new ReportCommand());
        $this->add(new SummaryCommand());
    }
}
