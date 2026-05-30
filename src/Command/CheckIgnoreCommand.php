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
     * @return int 0 when any path is ignored, 1 when none are, 2 on error.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!is_string($format) || !in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>USAGE-ERROR Unsupported check-ignore format. Use text or json.</error>');

            return Command::INVALID;
        }

        $configPath = $input->getOption('config');
        $configPath = is_string($configPath) && $configPath !== '' ? $configPath : null;
        $noConfig   = (bool) $input->getOption('no-config');
        if ($noConfig && $configPath !== null) {
            $output->writeln('<error>USAGE-ERROR --no-config cannot be combined with --config.</error>');

            return Command::INVALID;
        }

        /** @var list<string> $paths The command definition declares a required variadic paths argument. */
        $paths = $input->getArgument('paths');

        $projectRoot = getcwd();
        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine the current working directory.</error>');

            return Command::INVALID;
        }

        $patterns = $this->ignorePatterns($projectRoot, $configPath, $noConfig, $output);
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

        $rendered = $this->render($results, $format, $output->isVerbose());
        if ($rendered !== null) {
            $output->write($rendered, false, OutputInterface::OUTPUT_RAW);
        }

        return $anyIgnored ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Resolve the configured ignore patterns, mirroring analyse config resolution.
     *
     * @return list<string>|null Configured patterns, or null when config loading failed.
     */
    private function ignorePatterns(string $projectRoot, ?string $configPath, bool $noConfig, OutputInterface $output): ?array
    {
        $registry = RuleRegistry::defaults();

        if ($noConfig) {
            return AnalysisConfig::fromRegistry($registry)->ignoredPathPatterns();
        }

        try {
            $configLoader = new ConfigLoader($projectRoot, ConfigLoader::packageRoot());

            return $configLoader->load($configPath, $registry)->ignoredPathPatterns();
        } catch (ConfigException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return null;
        }
    }

    /**
     * Decide whether a single path is ignored, consulting Git only when the
     * configured and built-in rules do not already exclude it.
     *
     * @param list<string> $patterns Configured paths.ignore glob patterns.
     * @return IgnoreDecision Ignore decision for the path.
     */
    private function decideForPath(PathIgnoreResolver $resolver, string $projectRoot, array $patterns, string $path): IgnoreDecision
    {
        $absolutePath = PathHelper::resolveAgainst($projectRoot, $path);
        $displayPath  = PathHelper::relativeToRoot($absolutePath, $projectRoot) ?? PathHelper::normalizeSeparators($path);

        $decision = $resolver->decide($displayPath, $absolutePath, $patterns, false);
        if ($decision->ignored) {
            return $decision;
        }

        $gitRule = $resolver->gitIgnoreRule($displayPath);
        if ($gitRule !== null) {
            return IgnoreDecision::ignored(PathIgnoreResolver::SOURCE_GITIGNORE, $gitRule);
        }

        return $decision;
    }

    /**
     * Render the per-path results as text (ignored paths only) or JSON (all paths).
     *
     * @param list<array{path: string, ignored: bool, source: string|null, pattern: string|null}> $results
     * @return string|null Rendered output, or null when there is nothing to print.
     */
    private function render(array $results, string $format, bool $isVerbose): ?string
    {
        if ($format === 'json') {
            try {
                return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            } catch (JsonException) {
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

        return $lines === [] ? null : implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
