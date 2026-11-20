<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use GruffPhp\Engine\Config\ConfigLoader;
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
 * Runs dashboard scan requests and returns report HTML or an error panel.
 * The selected project and form state determine the analyse subprocess and its working directory.
 * Caches results by command and fingerprint; diff and include-ignored requests bypass the cache.
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
     * @var array<string, array{fingerprint: string, html: string, exitCode: int}> - Reports keyed by project and scan command.
     */
    private array $cache = [];

    /**
     * Wires up the collaborators every dashboard scan leans on: the gruff-php binary to run, the state
     * factory that reads the submitted form, and the renderer that turns results or failures into a page.
     *
     * @param string                $gruffBinary  - Absolute gruff-php binary path used for scan requests.
     * @param DashboardStateFactory $stateFactory - Factory used to resolve dashboard state.
     * @param DashboardPageRenderer $renderer     - Renderer used for scan output and errors.
     */
    public function __construct(
        private readonly string $gruffBinary,
        private readonly DashboardStateFactory $stateFactory,
        private readonly DashboardPageRenderer $renderer,
    ) {
    }

    /**
     * Handles one browser scan request end to end: resolve the project, run (or reuse a cached) analyse,
     * and hand back the HTML the dashboard iframe shows. Every "scan" click in the UI lands here.
     *
     * @param DashboardRequestContext $dashboardRequestContext - Per-run dashboard context: project root, launch dir, and scan timeout.
     * @param array<string, string> $query - Submitted form values; empty input uses the launch defaults.
     *
     * @return string - Scan HTML with dashboard metadata when output is nonempty; an error panel for an invalid root or empty output.
     */
    public function scanHtml(DashboardRequestContext $dashboardRequestContext, array $query): string
    {
        $state                       = $this->stateFactory->state($dashboardRequestContext->input, $dashboardRequestContext->projectRoot, $query);
        $renderer                    = $this->renderer;
        $dashboardScanCommandBuilder = new DashboardScanCommandBuilder($this->gruffBinary);
        $scanRoot                    = $this->stateFactory->resolveProjectRoot($state['project'], $dashboardRequestContext->launchRoot);

        // The chosen project path is not a real directory (e.g. a moved or deleted folder), so show the bad path instead of scanning a guess.
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

        // Only full, ignore-respecting scans are cacheable; a diff or include-ignored run stays volatile enough to always re-run.
        if ($state['scanScope'] !== 'diff' && $state['includeIgnored'] !== '1') {
            $cacheKey     = hash('sha256', $scanRoot . "\0" . implode("\0", $command));
            $fingerprint  = $this->cacheFingerprint($scanRoot, $paths, $state);
            $cachedResult = $this->cache[$cacheKey] ?? null;

            // The cached run is still valid (its source fingerprint is unchanged), so reuse that HTML and only refresh the timing banner.
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
            // The scan overran its configured timeout, so end it as a failure with the timeout message instead of hanging the page.
            $stderr   = $exception->getMessage();
            $exitCode = Command::FAILURE;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $html       = $process->getOutput();

        // With no report body to render, show stderr in an error panel instead of an empty iframe.
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
     * Fingerprints everything a scan's result depends on, so the cache can tell an unchanged project from
     * one that needs re-analysing before it hands the user a stale report.
     *
     * @param         string                                                                                                                                                                                                                      $scanRoot - Resolved project root the requested paths are taken relative to.
     * @param         list<string>                                                                                                                                                                                                                $paths    - Requested scan paths; each is walked into the fingerprint.
     * @param         array<string, string>                                                                                                                                                                                                       $state    - Dashboard form state whose config and baseline choices also feed the fingerprint.
     * @phpstan-param array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, deepScanBudget: string, includeIgnored: string, reportInteractive: string} $state
     *
     * @return string - Content hash over the scanned files plus the active config and baseline; a matching value means the cached report is safe to reuse.
     */
    private function cacheFingerprint(string $scanRoot, array $paths, array $state): string
    {
        $parts = [$scanRoot, $state['includeIgnored'], $state['deepScanBudget']];

        // Fold every requested source path into the fingerprint so editing any scanned file busts the cache.
        foreach ($paths as $path) {
            $this->appendPathFingerprint($parts, $scanRoot, $path);
        }

        // Unless config is switched off, the active `.gruff-php.yaml` shapes the findings, so a config edit must re-run the scan.
        if ($state['noConfig'] !== '1') {
            $this->appendPathFingerprint($parts, $scanRoot, $state['config'] === '' ? ConfigLoader::DEFAULT_CONFIG_FILE : $state['config']);
        }

        // The baseline decides which findings are hidden, so editing it must invalidate the cached report too.
        if ($state['noBaseline'] !== '1') {
            $this->appendPathFingerprint($parts, $scanRoot, $state['baseline'] === '' ? 'gruff-baseline.json' : $state['baseline']);
        }

        sort($parts, SORT_STRING);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * Records a file signature, eligible files below a directory, or a marker when realpath cannot resolve the path.
     *
     * @param list<string> $parts    - Fingerprint parts gathered so far; this call appends to it by reference.
     * @param string       $scanRoot - Project root the path is resolved against before hashing.
     * @param string $path - Project-relative or absolute path; unresolved paths contribute a missing marker.
     *
     * @return void - Appends to $parts in place; there is no return value.
     */
    private function appendPathFingerprint(array &$parts, string $scanRoot, string $path): void
    {
        $absolutePath = PathHelper::resolveAgainst($scanRoot, $path);
        $realPath     = realpath($absolutePath);

        // Record unresolved paths so a later successful resolution changes the cache fingerprint.
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
     * Collects file signatures below a directory, pruning nested directories on the cache-ignore lists.
     * A traversal failure adds an unreadable marker alongside any entries already collected.
     *
     * @param list<string> $parts     - Fingerprint parts gathered so far; this call appends the directory's files by reference.
     * @param string       $scanRoot  - Project root used to decide which nested directories are ignored.
     * @param string       $directory - Absolute directory whose files are recursively walked into the fingerprint.
     *
     * @return void - Appends to $parts in place; there is no return value.
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
            // If opening or traversing the directory fails, add an error marker alongside any entries already collected.
            $entries[] = 'unreadable:' . $directory . ':' . $exception->getMessage();
        }

        sort($entries, SORT_STRING);
        array_push($parts, ...$entries);
    }

    /**
     * Checks a directory against the cache's ignored names and project-relative roots.
     *
     * @param string $scanRoot  - Project root used to derive the directory's relative path for root matching.
     * @param string $directory - Absolute directory being considered for the recursive walk.
     *
     * @return bool - True for a listed basename or project-relative root; false otherwise.
     */
    private function isIgnoredDirectory(string $scanRoot, string $directory): bool
    {
        $name = basename($directory);

        // Any folder whose name is on the always-ignore list (vendor, node_modules, VCS dirs) is skipped wherever it sits in the tree.
        if (in_array($name, self::CACHE_IGNORED_DIRECTORIES, true)) {
            return true;
        }

        $relative = str_replace('\\', '/', ltrim(substr($directory, strlen($scanRoot)), '/'));

        // Otherwise only a specific root-anchored path (such as var/cache) is ignored; every other directory is tracked.
        return in_array($relative, self::CACHE_IGNORED_ROOTS, true);
    }

    /**
     * Combines a file's path, modification time, size, and SHA-256 digest for cache invalidation.
     *
     * @param string $path - Absolute path to an existing file whose metadata identifies the cached version.
     *
     * @return string - A file:path:mtime:size:hash signature; failed metadata reads use zero and a failed hash uses an empty string.
     */
    private function fileFingerprint(string $path): string
    {
        $hash = hash_file('sha256', $path);

        return sprintf('file:%s:%d:%d:%s', $path, filemtime($path) ?: 0, filesize($path) ?: 0, is_string($hash) ? $hash : '');
    }

    /**
     * Bounds the in-memory result cache by evicting the oldest scan before an insertion at capacity.
     *
     * @param string $cacheKey - Key about to be (re)written; dropped first so re-storing it counts as the newest entry.
     *
     * @return void - Mutates the cache in place; there is no return value.
     */
    private function evictCacheEntryIfNeeded(string $cacheKey): void
    {
        // Drop any existing entry for this key first, so re-storing it re-inserts at the end as the freshest scan.
        if (array_key_exists($cacheKey, $this->cache)) {
            unset($this->cache[$cacheKey]);
        }


        while (count($this->cache) >= self::MAX_CACHE_ENTRIES) {
            $oldestKey = array_key_first($this->cache);
            unset($this->cache[$oldestKey]);
        }
    }
}
