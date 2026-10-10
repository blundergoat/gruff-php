<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Config\ConfigMigrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backs `gruff-php migrate-config` - the one-way trip that carries a 0.5 config onto the 0.6 schema.
 *
 * Reach for this after upgrading, when `analyse` refuses a configuration written for 0.5: the per-command exit gate
 * moved from `minimumSeverity:` to `failOn:`, and `allowlists.secretPreviews` was removed. The migration is always
 * out of place - the file named by `--config` is only ever read - so a user who regrets it still has the
 * configuration they started with, which is what FAMILY-CONTRACT.md section 8's migration rule requires.
 */
final class MigrateConfigCommand extends Command
{
    /**
     * Declares the command's name, description, and the three options a user can pass.
     *
     * @return void - Nothing; the command definition is registered on this instance as a side effect.
     */
    protected function configure(): void
    {
        $this
            ->setName('migrate-config')
            ->setDescription('Rewrite a 0.5 config for the current schema, writing the result to a different file.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'The 0.5 config to read. It is never modified.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Where to write the migrated config. Required unless --dry-run.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would change and write nothing.');
    }

    /**
     * Reads the named config, rewrites it, and puts the result where the user asked.
     *
     * @param InputInterface  $input  - Console input; the `--config`, `--output`, and `--dry-run` options are read.
     * @param OutputInterface $output - Console output; carries the change summary and any error.
     *
     * @return int - Symfony exit code; SUCCESS after writing or a dry run, INVALID on a missing or refused path.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPath = $input->getOption('config');

        // Without an input there is nothing to migrate, and guessing at the project config would be a different command.
        if (!is_string($inputPath) || $inputPath === '') {
            $output->writeln('<error>migrate-config needs --config <path> naming the 0.5 config to read.</error>');

            return Command::INVALID;
        }
        if (!is_file($inputPath)) {
            $output->writeln(sprintf('<error>config to migrate does not exist: %s</error>', $inputPath));

            return Command::INVALID;
        }

        $original  = (string) file_get_contents($inputPath);
        $migration = ConfigMigrator::migrate($original);
        $output->writeln($migration['changes'] === []
            ? sprintf('%s is already current; no changes.', $inputPath)
            : implode("\n", ['Migration changes:', ...array_map(static fn(string $change): string => '  - ' . $change, $migration['changes'])]));

        if ($input->getOption('dry-run') === true) {
            $output->writeln('Dry run: nothing written.');

            return Command::SUCCESS;
        }

        return $this->writeMigrated($output, $inputPath, $migration['text'], $input->getOption('output'));
    }

    /**
     * Writes the migrated text to the destination the user named, refusing to overwrite the input.
     *
     * @param OutputInterface $output     - Console output; carries the wrote-path notice or the refusal.
     * @param string          $inputPath  - The 0.5 config, which must survive untouched.
     * @param string          $migrated   - The rewritten configuration text.
     * @param mixed           $outputPath - The `--output` value as the console parsed it; anything but a non-empty string is refused.
     *
     * @return int - Symfony exit code; SUCCESS after writing, INVALID when no usable destination was named.
     */
    private function writeMigrated(OutputInterface $output, string $inputPath, string $migrated, mixed $outputPath): int
    {
        // Without a destination there is nowhere to put the result, and writing over the input is the one thing
        // migration must never do; refusing is better than choosing a path the user did not name.
        if (!is_string($outputPath) || $outputPath === '') {
            $output->writeln('<error>migrate-config needs --output <path>, or --dry-run to print the changes.</error>');

            return Command::INVALID;
        }
        if (realpath($outputPath) !== false && realpath($outputPath) === realpath($inputPath)) {
            $output->writeln(sprintf('<error>--output must name a different file from --config; %s is the copy you may want back.</error>', $inputPath));

            return Command::INVALID;
        }

        // A destination gruff cannot write is reported rather than swallowed, so the user does not believe it migrated.
        if (file_put_contents($outputPath, $migrated) === false) {
            $output->writeln(sprintf('<error>Unable to write %s.</error>', $outputPath));

            return Command::INVALID;
        }

        $output->writeln(sprintf('Wrote %s; %s is unchanged.', $outputPath, $inputPath));

        return Command::SUCCESS;
    }
}
