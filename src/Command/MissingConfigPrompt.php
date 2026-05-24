<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\ConfigLoader;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
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
     * --no-config or --config, or when a project config already exists. The
     * prompt and any dispatched init output are routed to the error stream so
     * machine-readable stdout payloads (JSON, SARIF, HTML) stay parseable. The
     * dispatched init command is scoped to {@see $projectRoot} so callers that
     * address a non-CWD root (notably `dashboard --project ...`) write the new
     * config in the right directory.
     *
     * @param InputInterface          $input              Console input for the calling command.
     * @param OutputInterface         $output             Console output for the calling command.
     * @param SymfonyApplication|null $symfonyApplication Console application used to dispatch the init command.
     * @param string                  $projectRoot        Project root used to look for an existing config.
     * @param string|null             $explicitConfigPath Explicit --config path, when supplied.
     * @param bool                    $shouldSkipConfig   Whether the caller passed --no-config.
     * @return int|null Exit code when init was run and failed; null when the caller may continue.
     */
    public static function maybeOffer(
        InputInterface $input,
        OutputInterface $output,
        ?SymfonyApplication $symfonyApplication,
        string $projectRoot,
        ?string $explicitConfigPath,
        bool $shouldSkipConfig,
    ): ?int {
        if ($shouldSkipConfig || $explicitConfigPath !== null) {
            return null;
        }
        if (!$input->isInteractive()) {
            return null;
        }
        if (ConfigLoader::projectHasConfig($projectRoot)) {
            return null;
        }
        if (!$symfonyApplication instanceof SymfonyApplication) {
            return null;
        }

        $questionHelper = $symfonyApplication->getHelperSet()->get('question');
        if (!$questionHelper instanceof QuestionHelper) {
            return null;
        }

        $promptOutput = self::promptOutput($output);

        $accepted = (bool) $questionHelper->ask(
            $input,
            $promptOutput,
            new ConfirmationQuestion(self::PROMPT_TEXT, false),
        );
        if (!$accepted) {
            return null;
        }

        $initCommand = $symfonyApplication->find('init');
        $exitCode    = $initCommand->run(
            new ArrayInput([
                'command'        => 'init',
                '--project-root' => $projectRoot,
            ]),
            $promptOutput,
        );

        return $exitCode === Command::SUCCESS ? null : $exitCode;
    }

    /**
     * Pick the stream that should carry the prompt and dispatched init output.
     *
     * Routes interactive chatter to STDERR when the caller exposes a separate
     * error stream so JSON, SARIF, and HTML payloads written to STDOUT stay
     * uncorrupted.
     *
     * @param OutputInterface $output Console output supplied by the caller.
     * @return OutputInterface Error stream when available; otherwise the supplied output.
     */
    private static function promptOutput(OutputInterface $output): OutputInterface
    {
        if ($output instanceof ConsoleOutputInterface) {
            return $output->getErrorOutput();
        }

        return $output;
    }
}
