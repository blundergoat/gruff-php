<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\ConfigLoader;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Offers to run `gruff-php init` when no project config is present.
 */
final readonly class MissingConfigPrompt
{
    /**
     * Prompt text shown to interactive users when no config is discovered.
     */
    public const PROMPT_TEXT = 'No .gruff-php.yaml found in this directory. Run `gruff-php init` for config and baseline guidance? [y/N] ';

    /**
     * Decide whether to offer the init prompt and run it when accepted.
     *
     * Skips silently when stdin is non-interactive, when the caller passed
     * --no-config or --config, or when a project config already exists.
     *
     * @param InputInterface          $input              Console input for the calling command.
     * @param OutputInterface         $output             Console output for the calling command.
     * @param SymfonyApplication|null $application        Console application used to dispatch the init command.
     * @param string                  $projectRoot        Project root used to look for an existing config.
     * @param string|null             $explicitConfigPath Explicit --config path, when supplied.
     * @param bool                    $noConfig           Whether the caller passed --no-config.
     * @return int|null Exit code when init was run and failed; null when the caller may continue.
     */
    public static function maybeOffer(
        InputInterface $input,
        OutputInterface $output,
        ?SymfonyApplication $application,
        string $projectRoot,
        ?string $explicitConfigPath,
        bool $noConfig,
    ): ?int {
        if ($noConfig || $explicitConfigPath !== null) {
            return null;
        }
        if (!$input->isInteractive()) {
            return null;
        }
        if (self::projectConfigExists($projectRoot)) {
            return null;
        }
        if (!$application instanceof SymfonyApplication) {
            return null;
        }

        $questionHelper = $application->getHelperSet()->get('question');
        if (!$questionHelper instanceof QuestionHelper) {
            return null;
        }

        $accepted = (bool) $questionHelper->ask(
            $input,
            $output,
            new ConfirmationQuestion(self::PROMPT_TEXT, false),
        );
        if (!$accepted) {
            return null;
        }

        $initCommand = $application->find('init');
        $exitCode    = $initCommand->run(new ArrayInput(['command' => 'init']), $output);

        return $exitCode === Command::SUCCESS ? null : $exitCode;
    }

    /**
     * Check whether the project root already holds a discoverable config file.
     *
     * @param string $projectRoot Project root used for config discovery.
     * @return bool True when a project config file already exists.
     */
    private static function projectConfigExists(string $projectRoot): bool
    {
        $root = rtrim($projectRoot, '/');

        foreach ([ConfigLoader::DEFAULT_CONFIG_FILE, ConfigLoader::LEGACY_DEFAULT_CONFIG_FILE] as $candidate) {
            if (is_file($root . '/' . $candidate)) {
                return true;
            }
        }

        return false;
    }
}
