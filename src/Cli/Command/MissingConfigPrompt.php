<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Config\ConfigLoader;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
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
    public const PROMPT_TEXT = 'No .gruff-php.yaml or .gruff.yaml found in your project root. Run `gruff-php init` for config and baseline guidance? [y/N] ';

    /**
     * Decide whether to offer the init prompt and run it when accepted.
     *
     * Skips silently when stdin is non-interactive, when the active output
     * format is machine-readable, when the caller passed --no-config or
     * --config, or when a project config already exists. The prompt and any
     * dispatched init output are routed to the error stream so machine-readable
     * stdout payloads (JSON, SARIF, HTML) stay parseable. The dispatched init
     * command is scoped to {@see $projectRoot} so callers that address a
     * non-CWD root (notably `dashboard --project ...`) write the new config in
     * the right directory.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param InputInterface          $input - Console input for the calling command.
     * @param OutputInterface         $output - Console output for the calling command.
     * @param SymfonyApplication|null $symfonyApplication - Console application used to dispatch the init command.
     * @param string                  $projectRoot - Project root used to look for an existing config.
     * @param string|null             $explicitConfigPath - Explicit --config path, when supplied.
     * @param bool                    $shouldSkipConfig - Whether the caller passed --no-config.
     * @param bool                    $isMachineReadableFormat - Whether the caller renders a machine-parsed payload, which suppresses the prompt.
     * @param (callable(): bool)|null $stdinTtyProbe - Test seam overriding the real-STDIN TTY probe; null probes STDIN itself.
     *
     * @return int|null - Exit code when init was run and failed; null when the caller may continue.
     */
    public static function maybeOffer(
        InputInterface $input,
        OutputInterface $output,
        ?SymfonyApplication $symfonyApplication,
        string $projectRoot,
        ?string $explicitConfigPath,
        bool $shouldSkipConfig,
        bool $isMachineReadableFormat = false,
        ?callable $stdinTtyProbe = null,
    ): ?int {
        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
        if ($shouldSkipConfig || $explicitConfigPath !== null) {
            // Caller already chose a config path (or opted out), so offering init would override their intent.
            return null;
        }
        // User view: choose the terminal output branch for this case.
        if ($isMachineReadableFormat) {
            // The run feeds a parser or artifact store, so never mix an interactive offer into it.
            return null;
        }
        // User view: choose the terminal output branch for this case.
        if (!$input->isInteractive()) {
            // The caller opted out of interaction (-n/--quiet), so never block a piped or CI run on a prompt.
            return null;
        }
        // User view: choose the terminal output branch for this case.
        if (!self::hasAnswerableInput($input, $stdinTtyProbe)) {
            // Symfony's interactive flag stays true for piped stdin without -n; with no TTY and no explicit
            // stream nobody can answer, so skip instead of blocking in the question helper (or consuming
            // piped data such as a file list starting with "y" as consent).
            return null;
        }
        // User view: choose the terminal output branch for this case.
        if (ConfigLoader::hasProjectConfig($projectRoot)) {
            // A project config already exists; init has nothing to scaffold here.
            return null;
        }
        // User view: choose the terminal output branch for this case.
        if (!$symfonyApplication instanceof SymfonyApplication) {
            // Without the application we cannot locate and dispatch the init command, so stay silent.
            return null;
        }

        $questionHelper = $symfonyApplication->getHelperSet()->get('question');
        // User view: choose the terminal output branch for this case.
        if (!$questionHelper instanceof QuestionHelper) {
            // No question helper registered means we cannot ask, so skip rather than guess consent.
            return null;
        }

        $promptOutput = self::promptOutput($output);

        $accepted = (bool) $questionHelper->ask(
            $input,
            $promptOutput,
            new ConfirmationQuestion(self::PROMPT_TEXT, false),
        );
        // User view: choose the terminal output branch for this case.
        if (!$accepted) {
            // User declined the offer, so let the original command continue without scaffolding config.
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

        // Surface a failed init as the caller's exit code; on success return null so the caller proceeds.
        return $exitCode === Command::SUCCESS ? null : $exitCode;
    }

    /**
     * Decide whether a y/N answer can actually be read for this input.
     *
     * An explicitly set stream (tests, embedders) counts as answerable —
     * mirroring QuestionHelper's own stream resolution. Only when no stream
     * was set is the process's real STDIN probed for a terminal. Accepted
     * edge: genuinely interactive users without a TTY stdin (ssh -T, MSYS
     * mintty) lose the init offer; the machine-readable-format skip is the
     * primary guard precisely because this probe cannot see them.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param InputInterface          $input - Console input for the calling command.
     * @param (callable(): bool)|null $stdinTtyProbe - Test seam overriding the real-STDIN TTY probe; null probes STDIN itself.
     *
     * @return bool - True when the prompt has an input source that can answer it.
     */
    private static function hasAnswerableInput(InputInterface $input, ?callable $stdinTtyProbe): bool
    {
        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
        if ($input instanceof StreamableInputInterface && $input->getStream() !== null) {
            // An explicitly set stream is an intentional answer source (QuestionHelper reads it), so honour it.
            return true;
        }

        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
        if ($stdinTtyProbe !== null) {
            // A test supplied its own probe, so consult it instead of the process's real STDIN.
            return $stdinTtyProbe();
        }

        return self::isStdinTty();
    }

    /**
     * Probe whether the process's real STDIN is attached to a terminal.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @return bool - True when STDIN exists and is a TTY.
     */
    private static function isStdinTty(): bool
    {
        // User view: choose the terminal output branch for this case.
        if (!defined('STDIN')) {
            // Non-CLI SAPIs expose no STDIN constant; treat that as non-interactive rather than guessing.
            return false;
        }

        $stdin = constant('STDIN');
        // User view: choose the terminal output branch for this case.
        if (!is_resource($stdin)) {
            // A closed or detached descriptor cannot answer a prompt, and probing it would raise a warning.
            return false;
        }

        return stream_isatty($stdin);
    }

    /**
     * Pick the stream that should carry the prompt and dispatched init output.
     *
     * Routes interactive chatter to STDERR when the caller exposes a separate
     * error stream so JSON, SARIF, and HTML payloads written to STDOUT stay
     * uncorrupted.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param OutputInterface $output - Console output supplied by the caller.
     *
     * @return OutputInterface - Error stream when available; otherwise the supplied output.
     */
    private static function promptOutput(OutputInterface $output): OutputInterface
    {
        // User view: choose the terminal output branch for this case.
        if ($output instanceof ConsoleOutputInterface) {
            // Prefer STDERR so prompt and init chatter never corrupt machine-readable STDOUT payloads.
            return $output->getErrorOutput();
        }

        // No separate error stream available, so fall back to the caller's single output stream.
        return $output;
    }
}
