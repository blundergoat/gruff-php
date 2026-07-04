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
 * Offers to run `gruff-php init` when a user starts a scan in a project that has no config yet.
 *
 * First-run helper wired into the top of every scan command: when someone types `gruff-php analyse`
 * (or `summary`, `dashboard`, …) in a project with no `.gruff-php.yaml`, this asks whether to
 * scaffold one and dispatches `init` on a yes. It stays silent for non-interactive, machine-readable,
 * or already-configured runs, so it never blocks CI or corrupts JSON/SARIF output.
 */
final readonly class MissingConfigPrompt
{
    /**
     * Prompt text shown to interactive users when no config is discovered.
     */
    public const PROMPT_TEXT = 'No .gruff-php.yaml or .gruff.yaml found in your project root. Run `gruff-php init` for config and baseline guidance? [y/N] ';

    /**
     * Decides whether to show the first-run "run `gruff-php init`?" offer and dispatches `init` when
     * the user accepts. Called at the top of a scan command in a project that may lack a config; the
     * guards below skip the prompt whenever asking would be wrong.
     *
     * @param InputInterface          $input - Console input for the calling command.
     * @param OutputInterface         $output - Console output for the calling command.
     * @param SymfonyApplication|null $symfonyApplication - Console application used to dispatch init; null means init can't be offered, so the prompt is skipped.
     * @param string                  $projectRoot - Project root used to look for an existing config.
     * @param string|null             $explicitConfigPath - Explicit --config path; null when the user gave none (only then is the init offer considered).
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
        // Caller already chose a config path (or opted out), so offering init would override their intent.
        if ($shouldSkipConfig || $explicitConfigPath !== null) {
            return null;
        }
        // The run feeds a parser or artifact store, so never mix an interactive offer into it.
        if ($isMachineReadableFormat) {
            return null;
        }
        // The caller opted out of interaction (-n/--quiet), so never block a piped or CI run on a prompt.
        if (!$input->isInteractive()) {
            return null;
        }
        // Symfony's interactive flag stays true for piped stdin without -n; with no TTY and no explicit
        // stream nobody can answer, so skip instead of blocking in the question helper (or consuming
        // piped data such as a file list starting with "y" as consent).
        if (!self::hasAnswerableInput($input, $stdinTtyProbe)) {
            return null;
        }
        // A project config already exists; init has nothing to scaffold here.
        if (ConfigLoader::hasProjectConfig($projectRoot)) {
            return null;
        }
        // Without the application we cannot locate and dispatch the init command, so stay silent.
        if (!$symfonyApplication instanceof SymfonyApplication) {
            return null;
        }

        $questionHelper = $symfonyApplication->getHelperSet()->get('question');
        // No question helper registered means we cannot ask, so skip rather than guess consent.
        if (!$questionHelper instanceof QuestionHelper) {
            return null;
        }

        $promptOutput = self::promptOutput($output);

        $accepted = (bool) $questionHelper->ask(
            $input,
            $promptOutput,
            new ConfirmationQuestion(self::PROMPT_TEXT, false),
        );
        // User declined the offer, so let the original command continue without scaffolding config.
        if (!$accepted) {
            return null;
        }

        // Scope init to the resolved project root so a non-CWD run (like `dashboard --project ...`)
        // scaffolds the config in the user's target directory, not the current working directory.
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
     * Reports whether a y/N answer can actually be read, so the offer is only shown when someone (or
     * a test's own stream) is actually there to respond to it.
     *
     * @param InputInterface          $input - Console input for the calling command.
     * @param (callable(): bool)|null $stdinTtyProbe - Test seam overriding the real-STDIN TTY probe; null probes STDIN itself.
     *
     * @return bool - True when the prompt has an input source that can answer it.
     */
    private static function hasAnswerableInput(InputInterface $input, ?callable $stdinTtyProbe): bool
    {
        // An explicitly set stream is an intentional answer source (QuestionHelper reads it), so honour it.
        if ($input instanceof StreamableInputInterface && $input->getStream() !== null) {
            return true;
        }

        // A test supplied its own probe, so consult it instead of the process's real STDIN.
        if ($stdinTtyProbe !== null) {
            return $stdinTtyProbe();
        }

        return self::isStdinTty();
    }

    /**
     * Probes whether the process's real STDIN is a terminal - the fallback check for whether a live
     * user is present to answer the prompt.
     *
     * @return bool - True when STDIN exists and is a TTY.
     */
    private static function isStdinTty(): bool
    {
        // Non-CLI SAPIs expose no STDIN constant; treat that as non-interactive rather than guessing.
        if (!defined('STDIN')) {
            return false;
        }

        $stdin = constant('STDIN');
        // A closed or detached descriptor cannot answer a prompt, and probing it would raise a warning.
        if (!is_resource($stdin)) {
            return false;
        }

        return stream_isatty($stdin);
    }

    /**
     * Picks the stream that carries the prompt and dispatched init output, keeping interactive chatter
     * on STDERR so machine-readable STDOUT payloads (JSON, SARIF, HTML) stay uncorrupted.
     *
     * @param OutputInterface $output - Console output supplied by the caller.
     *
     * @return OutputInterface - Error stream when available; otherwise the supplied output.
     */
    private static function promptOutput(OutputInterface $output): OutputInterface
    {
        // Prefer STDERR so prompt and init chatter never corrupt machine-readable STDOUT payloads.
        if ($output instanceof ConsoleOutputInterface) {
            return $output->getErrorOutput();
        }

        // No separate error stream available, so fall back to the caller's single output stream.
        return $output;
    }
}
