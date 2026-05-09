<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Console\Application;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Source\SourceDiscovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AnalyseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('analyse')
            ->setDescription('Run gruff analysis.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyse.')
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Include files under default ignored directories.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = getcwd();

        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $includeIgnored = (bool) $input->getOption('include-ignored');
        $discovery = new SourceDiscovery($projectRoot);
        $discoveryResult = $discovery->discover($paths, $includeIgnored);
        $parser = new PhpFileParser();
        $parseErrorCount = 0;

        $output->writeln(sprintf('gruff %s', Application::VERSION));
        $output->writeln(sprintf('Discovered %d PHP file(s).', count($discoveryResult->files)));

        foreach ($discoveryResult->ignoredPaths as $ignoredPath) {
            $output->writeln(sprintf('IGNORED %s', $ignoredPath));
        }

        foreach ($discoveryResult->missingPaths as $missingPath) {
            $output->writeln(sprintf('MISSING %s', $missingPath));
        }

        foreach ($discoveryResult->files as $file) {
            $unit = $parser->parse($file);

            if ($unit->hasParseErrors()) {
                $parseErrorCount += count($unit->diagnostics);

                foreach ($unit->diagnostics as $diagnostic) {
                    $output->writeln(sprintf(
                        'PARSE-ERROR %s:%d %s',
                        $file->displayPath,
                        $diagnostic->line,
                        $diagnostic->message,
                    ));
                }

                continue;
            }

            $output->writeln(sprintf(
                'OK %s (%d line(s), %d statement(s), %d token(s))',
                $file->displayPath,
                $unit->lineCount(),
                count($unit->statements),
                count($unit->tokens),
            ));
        }

        if ($parseErrorCount > 0 || $discoveryResult->hasInputErrors()) {
            $output->writeln(sprintf(
                'Completed with %d parse error(s) and %d missing path(s).',
                $parseErrorCount,
                count($discoveryResult->missingPaths),
            ));

            return Command::FAILURE;
        }

        $output->writeln('Completed without parse errors.');

        return Command::SUCCESS;
    }
}
