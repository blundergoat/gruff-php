<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigMigrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs `gruff-php migrate-config` to prepare a 0.5 project config for the current loader.
 *
 * Use `--dry-run` to inspect the changes after upgrading, or `--output` to write a separate file.
 * Moves the old command thresholds to `failOn`, removes the retired preview option, and preserves the input for reference.
 */
final class MigrateConfigCommand extends Command
{
    /**
     * Declares the command's name, description, and user-facing options.
     *
     * @return void - Nothing; the command definition is registered on this instance as a side effect.
     */
    protected function configure(): void
    {
        $this
            ->setName('migrate-config')
            ->setDescription('Rewrite a 0.5 config for the current schema, writing the result to a different file.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'The 0.5 config to read. It is never modified.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'New file for the migrated config. Required unless --dry-run.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would change and write nothing.');
    }

    /**
     * Reads the named config, reports migration changes, and writes the migrated text unless this is a dry run.
     *
     * @param InputInterface  $input  - Console input; the --config, --output, and --dry-run options are read.
     * @param OutputInterface $output - Console output; carries the change summary and any error.
     *
     * @return int - SUCCESS after writing or a dry run, INVALID for a missing or refused path, or FAILURE when writing fails.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPath = $input->getOption('config');

        // Without an input there is nothing to migrate, and guessing at the project config would be a different command.
        if (!is_string($inputPath) || $inputPath === '') {
            $output->writeln('<error>migrate-config needs --config <path> naming the 0.5 config to read.</error>');

            return Command::INVALID;
        }
        // A missing --config file gives the caller nothing to migrate.
        if (!is_file($inputPath)) {
            $output->writeln(sprintf('<error>config to migrate does not exist: %s</error>', $inputPath));

            return Command::INVALID;
        }

        $original = is_readable($inputPath) ? file_get_contents($inputPath) : false;
        // A permissions error or a file removed during startup must not be treated as a blank config.
        if ($original === false) {
            $output->writeln(sprintf('<error>Unable to read %s.</error>', $inputPath));

            return Command::INVALID;
        }

        try {
            $migration = ConfigMigrator::migrate($original);
        } catch (ConfigException $exception) {
            // A legacy file containing both threshold sections needs a decision from the caller before it can be written.
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::INVALID;
        }
        // If the file already uses current settings, keep the original text and tell the caller there were no changes.
        $output->writeln($migration['changes'] === []
            ? sprintf('%s is already current; no changes.', $inputPath)
            : implode("\n", ['Migration changes:', ...array_map(static fn(string $change): string => '  - ' . $change, $migration['changes'])]));

        // Dry-run mode shows the proposed changes without needing an output destination.
        if ($input->getOption('dry-run') === true) {
            $output->writeln('Dry run: nothing written.');

            return Command::SUCCESS;
        }

        return $this->writeMigrated($output, $inputPath, $migration['text'], $input->getOption('output'));
    }

    /**
     * Writes the migrated YAML to an unused destination after the caller reviews the change summary.
     *
     * @param OutputInterface $output     - Console output; carries the wrote-path notice or the refusal.
     * @param string          $inputPath  - The 0.5 config, which must survive untouched.
     * @param string          $migrated   - The rewritten configuration text.
     * @param mixed           $outputPath - The `--output` value as the console parsed it; anything but a non-empty string is refused.
     *
     * @return int - 0 after writing, 2 for a missing or occupied destination, or 1 if the new file could not be written.
     */
    private function writeMigrated(OutputInterface $output, string $inputPath, string $migrated, mixed $outputPath): int
    {
        // Require an explicit output path so the caller can decide where the separate config goes.
        if (!is_string($outputPath) || $outputPath === '') {
            $output->writeln('<error>migrate-config needs --output <path>, or --dry-run to print the changes.</error>');

            return Command::INVALID;
        }
        // Refuse existing destinations, including links that could point back to a file the caller wants to preserve.
        if (file_exists($outputPath) || is_link($outputPath)) {
            $output->writeln(sprintf('<error>--output must name a new file. %s already exists.</error>', $outputPath));

            return Command::INVALID;
        }

        $outputDirectory = dirname($outputPath);
        // A typo in the --output folder, such as config/ that does not exist, is reported plainly instead of as a PHP warning.
        if (!is_dir($outputDirectory)) {
            $output->writeln(sprintf('<error>Migration output directory does not exist: %s</error>', $outputDirectory));

            return Command::INVALID;
        }

        // A read-only output folder is refused before any file is opened.
        if (!is_writable($outputDirectory)) {
            $output->writeln(sprintf('<error>Migration output directory is not writable: %s</error>', $outputDirectory));

            return Command::INVALID;
        }

        // Exclusive creation also refuses a link or file created after the existence check.
        $destinationStream = fopen($outputPath, 'xb');
        // A destination may become occupied after validation or sit in a directory the caller cannot write to.
        if ($destinationStream === false) {
            $output->writeln(sprintf('<error>Unable to write %s.</error>', $outputPath));

            return Command::FAILURE;
        }

        $didWrite = fwrite($destinationStream, $migrated) === strlen($migrated) && fflush($destinationStream);
        $didClose = fclose($destinationStream);
        // Remove our partial output so a retry can safely use the same destination.
        if (!$didWrite || !$didClose) {
            unlink($outputPath);
            $output->writeln(sprintf('<error>Unable to finish writing %s.</error>', $outputPath));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Wrote %s; %s is unchanged.', $outputPath, $inputPath));

        return Command::SUCCESS;
    }
}
