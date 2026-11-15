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
 * Reports whether `gruff-php check-ignore` operands match configuration, built-in, or Git ignore rules.
 * Uses the current directory and resolved configuration without discovering or analysing source files.
 *
 * Existing files receive the explicit-file exception used by `analyse`; a non-ignored verdict does not guarantee discovery.
 * Exit codes are 0 when any operand is ignored, 1 when none are, and 2 for handled usage, config, working-directory, or JSON errors.
 */
final class CheckIgnoreCommand extends Command
{
    /**
     * Registers the `check-ignore` command, its required paths argument, and output/configuration options.
     *
     * @return void - Populates the command definition used to parse invocations.
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
     * Runs check-ignore by validating the flags, resolving each path's ignore verdict, and rendering
     * the report as text or JSON.
     *
     * @param InputInterface  $input - Parsed invocation supplying the paths argument and format/config options.
     * @param OutputInterface $output - Console stream that receives the rendered report and any error lines.
     *
     * @return int - 0 if any path is ignored, 1 if none are, or 2 for handled option, config, working-directory, or JSON errors.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        // A usage error here (exit 2) reads differently from the "nothing ignored" result (exit 1),
        // so a script can tell a broken invocation apart from a clean miss.
        if (!is_string($format) || !in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>USAGE-ERROR Unsupported check-ignore format. Use text or json.</error>');

            return Command::INVALID;
        }

        $configPath = $input->getOption('config');
        $configPath = is_string($configPath) && $configPath !== '' ? $configPath : null;
        $noConfig   = (bool) $input->getOption('no-config');
        // Both --no-config and --config <file> were given, which request opposite config resolution;
        // refuse rather than silently obeying one and dropping the other flag.
        if ($noConfig && $configPath !== null) {
            $output->writeln('<error>USAGE-ERROR --no-config cannot be combined with --config.</error>');

            return Command::INVALID;
        }

        /** @var list<string> $paths - Required variadic operands supplied by the command definition. */
        $paths = $input->getArgument('paths');

        $projectRoot = getcwd();
        // Without a working directory, relative operands and config discovery have no project root.

        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine the current working directory.</error>');

            return Command::INVALID;
        }

        $patterns = $this->ignorePatterns($projectRoot, $configPath, $noConfig, $output);
        // A null pattern list means config loading failed and ignorePatterns() already printed the
        // reason; stop with the usage exit code so a later "nothing ignored" result cannot mask it.
        if ($patterns === null) {
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



        return $anyIgnored ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Resolves configured ignore patterns, reporting configuration failures to the output stream.
     *
     * @param string          $projectRoot - Absolute project root that anchors config discovery and relative patterns.
     * @param string|null     $configPath - Explicit config file to load, or null to auto-discover the default file.
     * @param bool $noConfig - When true, use registry defaults without loading a configuration file.
     * @param OutputInterface $output - Stream that receives the config error message before this returns null.
     *
     * @return list<string>|null - Configured ignore patterns; null when the config could not be loaded, which aborts the run with a usage error.
     */
    private function ignorePatterns(string $projectRoot, ?string $configPath, bool $noConfig, OutputInterface $output): ?array
    {
        $registry = RuleRegistry::defaults();



        if ($noConfig) {
            return AnalysisConfig::fromRegistry($registry)->ignoredPathPatterns();
        }

        try {
            $configLoader = new ConfigLoader($projectRoot, ConfigLoader::packageRoot(), shouldResolveFromLaunchDir: true);



            return $configLoader->load($configPath, $registry)->ignoredPathPatterns();
        } catch (ConfigException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            // Return null rather than an empty list on a broken config: an empty list would read as
            // "nothing is ignored" and quietly swallow the error the user needs to see.
            return null;
        }
    }

    /**
     * Checks configuration and built-in exclusions before using Git as a fallback for non-file operands.
     * Existing files bypass optional default and Git exclusions; configured patterns and VCS-directory exclusions still apply.
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

        $isExplicitFile = is_file($absolutePath);
        $decision = $resolver->decide($displayPath, $absolutePath, $patterns, false, $isExplicitFile);
        // gruff's own config or built-in rules already exclude the path; keep their verdict so the
        // reported source and pattern are the gruff ones, and skip the Git lookup entirely.
        if ($decision->ignored) {
            return $decision;
        }

        // An existing file operand is the same explicit request analyse receives, so Git/default
        // exclusions cannot turn this check into a different answer.
        if ($isExplicitFile) {
            return $decision;
        }

        $gitRule = $resolver->gitIgnoreRule($displayPath);
        // Gruff would keep the path but .gitignore excludes it; attribute the match to Git so the user
        // sees why a scan would skip a file that Gruff's own config keeps.
        if ($gitRule !== null) {
            return IgnoreDecision::ignored(PathIgnoreResolver::SOURCE_GITIGNORE, $gitRule);
        }


        return $decision;
    }

    /**
     * Turns the collected decisions into the text a person reads (only the ignored paths, like
     * git check-ignore) or the JSON a script consumes (every path).
     *
     * @param list<array{path: string, ignored: bool, source: string|null, pattern: string|null}> $results - Decisions in request order.
     * @param string $format - Already-validated output format, either json or text.
     * @param bool   $isVerbose - When true, text output appends the matching source and pattern per path.
     *
     * @return string|null - Text or JSON to print; null when text mode has no ignored paths to list, so the caller prints nothing.
     * @throws JsonException - When JSON encoding rejects the result payload.
     */
    private function render(array $results, string $format, bool $isVerbose): ?string
    {


        if ($format === 'json') {
            return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
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

        // No path was ignored, so there is nothing to list; hand back null and let the caller stay
        // silent rather than print a lone trailing newline.
        return $lines === [] ? null : implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
