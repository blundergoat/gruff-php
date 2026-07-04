<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\IgnoreDecision;
use GruffPhp\Engine\Source\PathIgnoreResolver;
use GruffPhp\Support\PathHelper;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backs the `gruff-php check-ignore` command - the quick "would gruff skip this file?" probe.
 *
 * Reach for this when a scan seems to miss a file, or before writing an ignore pattern, to see
 * whether a path is excluded and which rule excludes it. It resolves config and consults the same
 * ignore engine as `analyse`, then reports a verdict for each path without analysing anything, so
 * the answer always matches what a real scan would do.
 *
 * Exit codes mirror `git check-ignore`: 0 when at least one path is ignored, 1 when none are, and
 * 2 on a usage or configuration error - so a script can branch on the exit code alone.
 */
final class CheckIgnoreCommand extends Command
{
    /**
     * Declares the `check-ignore` command's paths argument and its `--format`, `--config`, and
     * `--no-config` flags - everything the user can type after `gruff-php check-ignore`.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('check-ignore')
            ->setDescription('Report whether gruff would ignore each path, with the matching source and pattern.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Paths to test against gruff ignore rules.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', default: 'text')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff YAML config file (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff-php.yaml file for this run.');
    }

    /**
     * Runs the whole command when a user types `gruff-php check-ignore <paths>`: validate the flags,
     * resolve the ignore verdict for each path, then render the report as text or JSON.
     *
     * @param InputInterface  $input - Parsed invocation supplying the paths argument and format/config options.
     * @param OutputInterface $output - Console stream that receives the rendered report and any error lines.
     *
     * @return int - 0 when any path is ignored, 1 when none are, 2 on a usage or config error.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        // Reject anything but text or json before doing any work - say the user typed `--format=xml`.
        // A usage error here (exit 2) reads differently from the "nothing ignored" result (exit 1),
        // so a script can tell a broken invocation apart from a clean miss.
        if (!is_string($format) || !in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>USAGE-ERROR Unsupported check-ignore format. Use text or json.</error>');

            return Command::INVALID;
        }

        $configPath = $input->getOption('config');
        $configPath = is_string($configPath) && $configPath !== '' ? $configPath : null;
        $noConfig   = (bool) $input->getOption('no-config');
        // Both `--no-config` and `--config <file>` were given, which ask for opposite config
        // resolution; refuse rather than silently obey one and drop the other flag the user typed.
        if ($noConfig && $configPath !== null) {
            $output->writeln('<error>USAGE-ERROR --no-config cannot be combined with --config.</error>');

            return Command::INVALID;
        }

        /** @var list<string> $paths The command definition declares a required variadic paths argument. */
        $paths = $input->getArgument('paths');

        $projectRoot = getcwd();
        // `getcwd()` returns false when the launch directory was deleted or is unreadable; ignore
        // resolution is anchored at that root, so without it we fail as a usage error, not a guess.
        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine the current working directory.</error>');

            return Command::INVALID;
        }

        $patterns = $this->ignorePatterns($projectRoot, $configPath, $noConfig, $output);
        // A null pattern list means config loading failed and `ignorePatterns()` already printed the
        // reason; stop with the usage exit code so a later "nothing ignored" result can't mask it.
        if ($patterns === null) {
            return Command::INVALID;
        }

        $resolver = new PathIgnoreResolver($projectRoot);
        $results  = [];
        $anyIgnored = false;

        // Classify each path the user listed, keeping a row per path and remembering whether any one
        // of them was ignored - that flag becomes the command's exit code.
        foreach ($paths as $path) {
            $decision = $this->decideForPath($resolver, $projectRoot, $patterns, $path);
            $anyIgnored = $anyIgnored || $decision->ignored;
            $results[]  = [
                'path' => $path,
                'ignored' => $decision->ignored,
                'source' => $decision->source,
                'pattern' => $decision->pattern,
            ];
        }

        // Render the collected decisions in the chosen format; only JSON encoding can fail here.
        try {
            $rendered = $this->render($results, $format, $output->isVerbose());
        } catch (JsonException $exception) {
            $output->writeln(sprintf('<error>JSON-ERROR %s</error>', $exception->getMessage()));

            return Command::INVALID;
        }

        // A null render means there was nothing to show (text mode with no ignored paths); write only
        // when the report has content, keeping stray blank output off the terminal.
        if ($rendered !== null) {
            $output->write($rendered, false, OutputInterface::OUTPUT_RAW);
        }

        // Mirror `git check-ignore`: a match is the success signal (exit 0), no match is the
        // failure signal (exit 1), so callers can branch on the exit code alone.
        return $anyIgnored ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Loads the ignore patterns exactly as `analyse` would, so check-ignore's verdict always matches
     * a real scan. Called once per run before any path is classified.
     *
     * @param string          $projectRoot - Absolute project root that anchors config discovery and relative patterns.
     * @param string|null     $configPath - Explicit config file to load, or null to auto-discover the default file.
     * @param bool            $noConfig - When true, skip file discovery entirely and use only built-in defaults.
     * @param OutputInterface $output - Stream that receives the config error message before this returns null.
     *
     * @return list<string>|null - Configured ignore patterns; null when the config could not be loaded, which aborts the run with a usage error.
     */
    private function ignorePatterns(string $projectRoot, ?string $configPath, bool $noConfig, OutputInterface $output): ?array
    {
        $registry = RuleRegistry::defaults();

        // `--no-config` means "pretend there's no config file": build the patterns from registry
        // defaults alone, so the verdict matches `analyse --no-config` on the same project.
        if ($noConfig) {
            return AnalysisConfig::fromRegistry($registry)->ignoredPathPatterns();
        }

        try {
            $configLoader = new ConfigLoader($projectRoot, ConfigLoader::packageRoot());

            // Load through the same `ConfigLoader` that `analyse` uses, so both commands agree on
            // which paths a given config excludes.
            return $configLoader->load($configPath, $registry)->ignoredPathPatterns();
        } catch (ConfigException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            // Return null rather than an empty list on a broken config: an empty list would read as
            // "nothing is ignored" and quietly swallow the error the user needs to see.
            return null;
        }
    }

    /**
     * Decides whether one path is ignored, checking gruff's own rules first and falling back to
     * `.gitignore` only when they don't already exclude it. Called once per path in the report.
     *
     * @param PathIgnoreResolver $resolver - Engine that evaluates config/built-in patterns and the Git fallback.
     * @param string             $projectRoot - Absolute root the path is made relative to before matching.
     * @param list<string>       $patterns - Configured paths.ignore glob patterns.
     * @param string             $path - Caller-supplied path (absolute or relative) to classify.
     *
     * @return IgnoreDecision - The path's ignore verdict, carrying the matched source and pattern when it is ignored.
     */
    private function decideForPath(PathIgnoreResolver $resolver, string $projectRoot, array $patterns, string $path): IgnoreDecision
    {
        $absolutePath = PathHelper::resolveAgainst($projectRoot, $path);
        $displayPath  = PathHelper::relativeToRoot($absolutePath, $projectRoot) ?? PathHelper::normalizeSeparators($path);

        $decision = $resolver->decide($displayPath, $absolutePath, $patterns, false);
        // gruff's own config or built-in rules already exclude the path; keep their verdict so the
        // reported source and pattern are the gruff ones, and skip the slower Git lookup entirely.
        if ($decision->ignored) {
            return $decision;
        }

        $gitRule = $resolver->gitIgnoreRule($displayPath);
        // gruff wouldn't exclude it, but `.gitignore` does; report it as ignored and attribute the
        // match to Git so the user sees why a scan would skip a file gruff's own config keeps.
        if ($gitRule !== null) {
            return IgnoreDecision::ignored(PathIgnoreResolver::SOURCE_GITIGNORE, $gitRule);
        }

        // Nothing - gruff config, built-in, or Git - matched, so report the path as not ignored.
        return $decision;
    }

    /**
     * Turns the collected decisions into the text a person reads (only the ignored paths, like
     * `git check-ignore`) or the JSON a script consumes (every path). The command's final step.
     *
     * @param list<array{path: string, ignored: bool, source: string|null, pattern: string|null}> $results - One entry per requested path, in request order, each carrying its ignore decision.
     * @param string $format - Already-validated output format, either `json` or `text`.
     * @param bool   $isVerbose - When true, text output appends the matching source and pattern per path.
     *
     * @return string|null - Text or JSON to print; null when text mode has no ignored paths to list, so the caller prints nothing.
     * @throws JsonException When JSON mode cannot encode the result payload.
     */
    private function render(array $results, string $format, bool $isVerbose): ?string
    {
        // JSON mode is for machine consumers, so it reports every path, ignored or not; pretty-print
        // with unescaped slashes so the paths stay readable if a human does look.
        if ($format === 'json') {
            return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        }

        $lines = [];
        // Text mode shows only the paths that are ignored, one per line, like `git check-ignore`.
        foreach ($results as $result) {
            // This path isn't ignored, so it never appears in text output; skip straight past it.
            if (!$result['ignored']) {
                continue;
            }

            $lines[] = $isVerbose
                ? sprintf("%s\t%s:%s", $result['path'], (string) $result['source'], (string) $result['pattern'])
                : $result['path'];
        }

        // No path was ignored, so there is nothing to list; hand back null and let the caller stay
        // silent rather than print a lone trailing newline.
        return $lines === [] ? null : implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
