<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\ConfigLoader;
use GruffPhp\Support\PathHelper;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/**
 * Runs dashboard scans and converts scan output into HTML.
 */
final class DashboardScanRunner
{
    /**
     * Maximum number of dashboard scan results retained in memory.
     */
    private const MAX_CACHE_ENTRIES = 12;

    /**
     * Default ignored directory names skipped by dashboard cache invalidation.
     */
    private const CACHE_IGNORED_DIRECTORIES = [
        '.fleet',
        '.git',
        '.hg',
        '.idea',
        '.phpunit.cache',
        '.svn',
        '.vscode',
        'build',
        'cache',
        'coverage',
        'dist',
        'generated',
        'node_modules',
        'tmp',
        'vendor',
    ];

    /**
     * Default ignored project-relative directory roots skipped by dashboard cache invalidation.
     */
    private const CACHE_IGNORED_ROOTS = [
        '.goat-flow/logs',
        '.goat-flow/scratchpad',
        '.goat-flow/tasks',
        'var/cache',
    ];

    /**
     * @var array<string, array{fingerprint: string, html: string, exitCode: int}>
     */
    private array $cache = [];

    /**
     * Capture collaborators used to execute dashboard scans and render results.
     *
     * @param string                $gruffBinary  Absolute gruff-php binary path used for scan requests.
     * @param DashboardStateFactory $stateFactory Factory used to resolve dashboard state.
     * @param DashboardPageRenderer $renderer     Renderer used for scan output and errors.
     */
    public function __construct(
        private readonly string $gruffBinary,
        private readonly DashboardStateFactory $stateFactory,
        private readonly DashboardPageRenderer $renderer,
    ) {
    }

    /**
     * Run a dashboard scan request and return HTML for the iframe.
     *
     * @param DashboardRequestContext $dashboardRequestContext Dashboard request context.
     * @param array<string, string>   $query   Request query values from the dashboard form.
     * @return string Dashboard HTML for either scan results or an error panel.
     */
    public function scanHtml(DashboardRequestContext $dashboardRequestContext, array $query): string
    {
        $state                       = $this->stateFactory->state($dashboardRequestContext->input, $dashboardRequestContext->projectRoot, $query);
        $renderer                    = $this->renderer;
        $dashboardScanCommandBuilder = new DashboardScanCommandBuilder($this->gruffBinary);
        $scanRoot                    = $this->stateFactory->resolveProjectRoot($state['project'], $dashboardRequestContext->launchRoot);

        if ($scanRoot === null) {
            return $renderer->errorHtml(
                'Project root is not an existing directory.',
                sprintf('Project: %s', $state['project']),
                Command::INVALID,
                0,
            );
        }

        $paths     = $dashboardScanCommandBuilder->parsePaths($state['paths']);
        $command   = $dashboardScanCommandBuilder->analyseCommand($paths, $state);
        $startedAt = microtime(true);

        if ($state['scanScope'] !== 'diff' && $state['includeIgnored'] !== '1') {
            $cacheKey     = hash('sha256', $scanRoot . "\0" . implode("\0", $command));
            $fingerprint  = $this->cacheFingerprint($scanRoot, $paths, $state);
            $cachedResult = $this->cache[$cacheKey] ?? null;

            if ($cachedResult !== null && $cachedResult['fingerprint'] === $fingerprint) {
                return $renderer->injectDashboardMetadata(
                    html:        $cachedResult['html'],
                    projectRoot: $scanRoot,
                    command:     $command,
                    exitCode:    $cachedResult['exitCode'],
                    durationMs:  (int) round((microtime(true) - $startedAt) * 1000),
                );
            }
        } else {
            $cacheKey    = null;
            $fingerprint = null;
        }

        $process = new Process($command, $scanRoot);
        $process->setTimeout($dashboardRequestContext->scanTimeout);
        $stderr   = '';
        $exitCode = Command::SUCCESS;

        try {
            $process->run();
            $stderr   = $process->getErrorOutput();
            $exitCode = $process->getExitCode() ?? Command::FAILURE;
        } catch (ProcessTimedOutException $exception) {
            $stderr   = $exception->getMessage();
            $exitCode = Command::FAILURE;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $html       = $process->getOutput();

        if ($html === '') {
            return $renderer->errorHtml('The scan did not produce HTML output.', $stderr === '' ? 'No stderr output.' : $stderr, $exitCode, $durationMs);
        }

        if (isset($cacheKey, $fingerprint)) {
            $this->evictCacheEntryIfNeeded($cacheKey);
            $this->cache[$cacheKey] = [
                'fingerprint' => $fingerprint,
                'html' => $html,
                'exitCode' => $exitCode,
            ];
        }

        return $renderer->injectDashboardMetadata(html: $html, projectRoot: $scanRoot, command: $command, exitCode: $exitCode, durationMs: $durationMs);
    }

    /**
     * Build an invalidation fingerprint for the requested scan inputs.
     *
     * @param         list<string>                                                                                                                                                                                        $paths Requested scan paths.
     * @param         array<string, string>                                                                                                                                                                               $state Dashboard query state.
     * @phpstan-param array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string} $state
     * @return string Fingerprint covering source paths plus config and baseline inputs.
     */
    private function cacheFingerprint(string $scanRoot, array $paths, array $state): string
    {
        $parts = [$scanRoot, $state['includeIgnored']];

        foreach ($paths as $path) {
            $this->appendPathFingerprint($parts, $scanRoot, $path);
        }

        if ($state['noConfig'] !== '1') {
            $this->appendPathFingerprint($parts, $scanRoot, $state['config'] === '' ? ConfigLoader::DEFAULT_CONFIG_FILE : $state['config']);
        }

        if ($state['noBaseline'] !== '1') {
            $this->appendPathFingerprint($parts, $scanRoot, $state['baseline'] === '' ? 'gruff-baseline.json' : $state['baseline']);
        }

        sort($parts, SORT_STRING);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * Add a file, directory, or missing-path marker to a cache fingerprint.
     *
     * @param list<string> $parts Fingerprint parts collected so far.
     * @return void
     */
    private function appendPathFingerprint(array &$parts, string $scanRoot, string $path): void
    {
        $absolutePath = PathHelper::resolveAgainst($scanRoot, $path);
        $realPath     = realpath($absolutePath);

        if (!is_string($realPath)) {
            $parts[] = 'missing:' . $absolutePath;

            return;
        }

        if (is_file($realPath)) {
            $parts[] = $this->fileFingerprint($realPath);

            return;
        }

        if (is_dir($realPath)) {
            $this->appendDirectoryFingerprint($parts, $scanRoot, $realPath);
        }
    }

    /**
     * Add recursive file metadata for a directory to a cache fingerprint.
     *
     * @param list<string> $parts Fingerprint parts collected so far.
     * @return void
     */
    private function appendDirectoryFingerprint(array &$parts, string $scanRoot, string $directory): void
    {
        $entries = [];

        try {
            $recursiveIteratorIterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                    fn (SplFileInfo $file): bool => !$file->isDir() || !$this->isIgnoredDirectory($scanRoot, $file->getPathname()),
                ),
            );

            foreach ($recursiveIteratorIterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $entries[] = $this->fileFingerprint($file->getPathname());
            }
        } catch (UnexpectedValueException $exception) {
            $entries[] = 'unreadable:' . $directory . ':' . $exception->getMessage();
        }

        sort($entries, SORT_STRING);
        array_push($parts, ...$entries);
    }

    /**
     * Check whether a directory is outside the dashboard cache invalidation surface.
     *
     * @return bool True when the directory should not invalidate cached scans.
     */
    private function isIgnoredDirectory(string $scanRoot, string $directory): bool
    {
        $name = basename($directory);

        if (in_array($name, self::CACHE_IGNORED_DIRECTORIES, true)) {
            return true;
        }

        $relative = str_replace('\\', '/', ltrim(substr($directory, strlen($scanRoot)), '/'));

        return in_array($relative, self::CACHE_IGNORED_ROOTS, true);
    }

    /**
     * Return file metadata used for dashboard cache invalidation.
     *
     * @return string File path, modification time, and size.
     */
    private function fileFingerprint(string $path): string
    {
        $hash = hash_file('sha256', $path);

        return sprintf('file:%s:%d:%d:%s', $path, filemtime($path) ?: 0, filesize($path) ?: 0, is_string($hash) ? $hash : '');
    }

    /**
     * Keep the in-process dashboard result cache bounded.
     *
     * @return void
     */
    private function evictCacheEntryIfNeeded(string $cacheKey): void
    {
        if (array_key_exists($cacheKey, $this->cache)) {
            unset($this->cache[$cacheKey]);
        }

        while (count($this->cache) >= self::MAX_CACHE_ENTRIES) {
            $oldestKey = array_key_first($this->cache);
            if (!is_string($oldestKey)) {
                return;
            }

            unset($this->cache[$oldestKey]);
        }
    }
}
