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
 * Executes a dashboard scan on the user's behalf and returns the HTML the iframe renders.
 *
 * Every time someone clicks "scan" in the `gruff-php dashboard` web UI, the request handler lands here:
 * it resolves the chosen project root, builds the matching `analyse` command, runs it as a subprocess,
 * and returns either the rendered report or an error panel. A small in-memory cache keyed by command and
 * a content fingerprint makes reopening an unchanged project instant, while diff and include-ignored
 * scans skip the cache so they always reflect the latest working tree.
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
     * Wires up the collaborators every dashboard scan leans on: the gruff-php binary to run, the state
     * factory that reads the submitted form, and the renderer that turns results or failures into a page.
     *
     * @param string                $gruffBinary - Absolute gruff-php binary path used for scan requests.
     * @param DashboardStateFactory $stateFactory - Factory used to resolve dashboard state.
     * @param DashboardPageRenderer $renderer - Renderer used for scan output and errors.
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
     * @param array<string, string>   $query - Submitted form values; empty on first load, which falls back to the launch defaults.
     *
     * @return string - Embeddable HTML: the rendered scan report on success, or an error panel when the root is bad or output is empty.
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
            // Diff and include-ignored scans are never cached, so null the markers and skip the store step below.
            $cacheKey    = null;
            $fingerprint = null;
        }

        $process = new Process($command, $scanRoot);
        $process->setTimeout($dashboardRequestContext->scanTimeout);
        $stderr   = '';
        $exitCode = Command::SUCCESS;

        // Run the analyse subprocess the user is waiting on, capturing its stderr and exit code.
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

        // Empty stdout means analyse crashed or printed nothing usable, so show an error panel with stderr rather than a blank iframe.
        if ($html === '') {
            return $renderer->errorHtml('The scan did not produce HTML output.', $stderr === '' ? 'No stderr output.' : $stderr, $exitCode, $durationMs);
        }

        // This was a cacheable scan and it produced output, so store the fresh result for the next identical view.
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
     * @param string                $scanRoot - Resolved project root the requested paths are taken relative to.
     * @param list<string>          $paths - Requested scan paths; each is walked into the fingerprint.
     * @param array<string, string> $state - Dashboard form state whose config and baseline choices also feed the fingerprint.
     * @phpstan-param array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string} $state
     *
     * @return string - Content hash over the scanned files plus the active config and baseline; a matching value means the cached report is safe to reuse.
     */
    private function cacheFingerprint(string $scanRoot, array $paths, array $state): string
    {
        $parts = [$scanRoot, $state['includeIgnored']];

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
     * Records one path's contribution to the fingerprint: its file metadata, every file beneath a
     * directory, or a "missing" marker when the path is not on disk yet.
     *
     * @param list<string> $parts - Fingerprint parts gathered so far; this call appends to it by reference.
     * @param string       $scanRoot - Project root the path is resolved against before hashing.
     * @param string       $path - Project-relative or absolute path to fingerprint; may not exist yet, which is recorded as a missing marker.
     *
     * @return void - Appends to $parts in place; there is no return value.
     */
    private function appendPathFingerprint(array &$parts, string $scanRoot, string $path): void
    {
        $absolutePath = PathHelper::resolveAgainst($scanRoot, $path);
        $realPath     = realpath($absolutePath);

        // The path is not on disk yet (such as a `gruff-baseline.json` the user hasn't generated), so record its absence; creating it later busts the cache.
        if (!is_string($realPath)) {
            $parts[] = 'missing:' . $absolutePath;

            return;
        }

        // A plain file is fingerprinted directly from its own metadata; there is nothing to recurse into.
        if (is_file($realPath)) {
            $parts[] = $this->fileFingerprint($realPath);

            return;
        }

        // A directory contributes every file beneath it, so walk it so any edit anywhere inside is noticed.
        if (is_dir($realPath)) {
            $this->appendDirectoryFingerprint($parts, $scanRoot, $realPath);
        }
    }

    /**
     * Walks a directory tree and folds every readable file's metadata into the fingerprint, skipping the
     * noise directories (`vendor`, `node_modules`, build output) that never change a scan's verdict.
     *
     * @param list<string> $parts - Fingerprint parts gathered so far; this call appends the directory's files by reference.
     * @param string       $scanRoot - Project root used to decide which nested directories are ignored.
     * @param string       $directory - Absolute directory whose files are recursively walked into the fingerprint.
     *
     * @return void - Appends to $parts in place; there is no return value.
     */
    private function appendDirectoryFingerprint(array &$parts, string $scanRoot, string $directory): void
    {
        $entries = [];

        // Build a recursive walk that prunes ignored directories up front, so it never descends into vendor or build noise.
        try {
            $recursiveIteratorIterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                    fn (SplFileInfo $file): bool => !$file->isDir() || !$this->isIgnoredDirectory($scanRoot, $file->getPathname()),
                ),
            );

            // Visit each entry the walk surfaces, turning real files into fingerprint metadata.
            foreach ($recursiveIteratorIterator as $file) {
                // Skip anything that is not an actual file (a directory node or a broken entry); only files carry hashable content.
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $entries[] = $this->fileFingerprint($file->getPathname());
            }
        } catch (UnexpectedValueException $exception) {
            // The directory became unreadable mid-walk, so record that fact instead of silently fingerprinting a partial tree.
            $entries[] = 'unreadable:' . $directory . ':' . $exception->getMessage();
        }

        sort($entries, SORT_STRING);
        array_push($parts, ...$entries);
    }

    /**
     * Decides whether a directory should be left out of the fingerprint walk, keeping churny folders like
     * `vendor`, `node_modules`, or `var/cache` from needlessly invalidating a cached scan.
     *
     * @param  string $scanRoot - Project root used to derive the directory's relative path for root matching.
     * @param  string $directory - Absolute directory being considered for the recursive walk.
     *
     * @return bool - True to skip this directory (its edits never change the verdict); false to walk it into the fingerprint.
     */
    private function isIgnoredDirectory(string $scanRoot, string $directory): bool
    {
        $name = basename($directory);

        // Any folder whose name is on the always-ignore list (vendor, node_modules, VCS dirs) is skipped wherever it sits in the tree.
        if (in_array($name, self::CACHE_IGNORED_DIRECTORIES, true)) {
            return true;
        }

        $relative = str_replace('\\', '/', ltrim(substr($directory, strlen($scanRoot)), '/'));

        // Otherwise only a specific root-anchored path (such as `var/cache`) is ignored; every other directory is tracked.
        return in_array($relative, self::CACHE_IGNORED_ROOTS, true);
    }

    /**
     * Distils one file into the compact signature the cache compares against (path, mtime, size, and a
     * content hash), so any real edit to it surfaces as a different fingerprint.
     *
     * @param  string $path - Absolute path to an existing file whose metadata identifies the cached version.
     *
     * @return string - A `file:path:mtime:size:hash` signature; an identical string means the file is byte-for-byte unchanged.
     */
    private function fileFingerprint(string $path): string
    {
        $hash = hash_file('sha256', $path);

        return sprintf('file:%s:%d:%d:%s', $path, filemtime($path) ?: 0, filesize($path) ?: 0, is_string($hash) ? $hash : '');
    }

    /**
     * Keeps the in-memory result cache from growing without bound, evicting the oldest scans once it is
     * full so a long-lived dashboard session does not leak memory as the user runs scan after scan.
     *
     * @param  string $cacheKey - Key about to be (re)written; dropped first so re-storing it counts as the newest entry.
     *
     * @return void - Mutates the cache in place; there is no return value.
     */
    private function evictCacheEntryIfNeeded(string $cacheKey): void
    {
        // Drop any existing entry for this key first, so re-storing it re-inserts at the end as the freshest scan.
        if (array_key_exists($cacheKey, $this->cache)) {
            unset($this->cache[$cacheKey]);
        }

        // Make room for the incoming entry by discarding the oldest scans until the cache is back under its limit.
        while (count($this->cache) >= self::MAX_CACHE_ENTRIES) {
            $oldestKey = array_key_first($this->cache);
            unset($this->cache[$oldestKey]);
        }
    }
}
