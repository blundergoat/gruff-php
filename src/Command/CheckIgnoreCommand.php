<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\IgnoreDecision;
use GruffPhp\Source\PathIgnoreResolver;
use GruffPhp\Support\PathHelper;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports whether gruff would ignore each path, using the same config resolution
 * and ignore engine as `analyse`, without performing any analysis.
 *
 * Exit codes mirror `git check-ignore`: 0 when at least one path is ignored,
 * 1 when none are, and 2 on a usage or configuration error.
 */
final class CheckIgnoreCommand extends Command
{
    /**
     * Register check-ignore CLI arguments and options.
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
     * Resolve each path's ignore decision and render it as text or JSON.
     *
     * @param InputInterface  $input - Parsed invocation supplying the paths argument and format/config options.
     * @param OutputInterface $output - Console stream that receives the rendered report and any error lines.
     *
     * @return int - 0 when any path is ignored, 1 when none are, 2 on error.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!is_string($format) || !in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>USAGE-ERROR Unsupported check-ignore format. Use text or json.</error>');

            // Surface an unknown --format as a usage error (exit 2) so scripts can tell it apart
            // from the "nothing ignored" result (exit 1) and fix the invocation.
            return Command::INVALID;
        }

        $configPath = $input->getOption('config');
        $configPath = is_string($configPath) && $configPath !== '' ? $configPath : null;
        $noConfig   = (bool) $input->getOption('no-config');
        if ($noConfig && $configPath !== null) {
            $output->writeln('<error>USAGE-ERROR --no-config cannot be combined with --config.</error>');

            // --no-config and --config request contradictory config resolution; refuse rather than
            // silently pick one, so the caller corrects the ambiguous invocation.
            return Command::INVALID;
        }

        /** @var list<string> $paths The command definition declares a required variadic paths argument. */
        $paths = $input->getArgument('paths');

        $projectRoot = getcwd();
        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine the current working directory.</error>');

            // Ignore resolution is anchored at the project root; without a cwd there is no root to
            // resolve relative paths against, so fail as a usage error instead of guessing.
            return Command::INVALID;
        }

        $patterns = $this->ignorePatterns($projectRoot, $configPath, $noConfig, $output);
        if ($patterns === null) {
            // ignorePatterns() already reported the config error; propagate the usage exit code so
            // the failure is not masked by a later "nothing ignored" result.
            return Command::INVALID;
        }

        $resolver = new PathIgnoreResolver($projectRoot);
        $results  = [];
        $anyIgnored = false;

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

        $rendered = $this->render($results, $format, $output->isVerbose());
        if ($rendered !== null) {
            $output->write($rendered, false, OutputInterface::OUTPUT_RAW);
        }

        // Mirror `git check-ignore`: a match is the success signal (exit 0), no match is the
        // failure signal (exit 1), so callers can branch on the exit code alone.
        return $anyIgnored ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Resolve the configured ignore patterns, mirroring analyse config resolution.
     *
     * @param string          $projectRoot - Absolute project root that anchors config discovery and relative patterns.
     * @param string|null     $configPath - Explicit config file to load, or null to auto-discover the default file.
     * @param bool            $noConfig - When true, skip file discovery entirely and use only built-in defaults.
     * @param OutputInterface $output - Stream that receives the config error message before this returns null.
     *
     * @return list<string>|null - Configured patterns, or null when config loading failed.
     */
    private function ignorePatterns(string $projectRoot, ?string $configPath, bool $noConfig, OutputInterface $output): ?array
    {
        $registry = RuleRegistry::defaults();

        if ($noConfig) {
            // --no-config means "ignore on-disk config": resolve patterns from registry defaults
            // alone so the result is identical to analyse running with the same flag.
            return AnalysisConfig::fromRegistry($registry)->ignoredPathPatterns();
        }

        try {
            $configLoader = new ConfigLoader($projectRoot, ConfigLoader::packageRoot());

            // Load through the same ConfigLoader analyse uses, so check-ignore and analyse agree on
            // which paths are excluded for a given config.
            return $configLoader->load($configPath, $registry)->ignoredPathPatterns();
        } catch (ConfigException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            // Signal config failure with null (not an empty pattern list): an empty list would read
            // as "nothing is ignored" and hide the error from the caller.
            return null;
        }
    }

    /**
     * Decide whether a single path is ignored, consulting Git only when the
     * configured and built-in rules do not already exclude it.
     *
     * @param PathIgnoreResolver $resolver - Engine that evaluates config/built-in patterns and the Git fallback.
     * @param string             $projectRoot - Absolute root the path is made relative to before matching.
     * @param list<string>       $patterns - Configured paths.ignore glob patterns.
     * @param string             $path - Caller-supplied path (absolute or relative) to classify.
     *
     * @return IgnoreDecision - Ignore decision for the path.
     */
    private function decideForPath(PathIgnoreResolver $resolver, string $projectRoot, array $patterns, string $path): IgnoreDecision
    {
        $absolutePath = PathHelper::resolveAgainst($projectRoot, $path);
        $displayPath  = PathHelper::relativeToRoot($absolutePath, $projectRoot) ?? PathHelper::normalizeSeparators($path);

        $decision = $resolver->decide($displayPath, $absolutePath, $patterns, false);
        if ($decision->ignored) {
            // Config/built-in rules already exclude the path; return their decision so its reported
            // source and pattern win and the slower Git lookup is skipped.
            return $decision;
        }

        $gitRule = $resolver->gitIgnoreRule($displayPath);
        if ($gitRule !== null) {
            // Not excluded by gruff config, but .gitignore covers it: report the Git match so the
            // path is treated as ignored with .gitignore as the attributed source.
            return IgnoreDecision::ignored(PathIgnoreResolver::SOURCE_GITIGNORE, $gitRule);
        }

        // No config, built-in, or Git rule matched, so hand back the original not-ignored decision.
        return $decision;
    }

    /**
     * Render the per-path results as text (ignored paths only) or JSON (all paths).
     *
     * @param list<array{path: string, ignored: bool, source: string|null, pattern: string|null}> $results - One entry per requested path, in request order, each carrying its ignore decision.
     * @param string $format - Already-validated output format, either `json` or `text`.
     * @param bool   $isVerbose - When true, text output appends the matching source and pattern per path.
     *
     * @return string|null - Rendered output, or null when there is nothing to print.
     */
    private function render(array $results, string $format, bool $isVerbose): ?string
    {
        if ($format === 'json') {
            try {
                // JSON mode reports every path (ignored or not) so machine consumers get the full
                // decision set; pretty-print with unescaped slashes to keep paths human-readable.
                return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            } catch (JsonException) {
                // Encoding failed, so emit nothing rather than partial JSON; the SUCCESS/FAILURE exit
                // code still conveys whether any path matched.
                return null;
            }
        }

        $lines = [];
        foreach ($results as $result) {
            if (!$result['ignored']) {
                continue;
            }

            $lines[] = $isVerbose
                ? sprintf("%s\t%s:%s", $result['path'], (string) $result['source'], (string) $result['pattern'])
                : $result['path'];
        }

        // Text mode lists only ignored paths (like `git check-ignore`); return null for an empty set
        // so the caller writes nothing instead of a stray trailing newline.
        return $lines === [] ? null : implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
