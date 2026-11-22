<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Source\SourceDiscovery;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs scans submitted from the dashboard and returns an HTML report or an error panel.
 * The selected project and form values decide what the `analyse` subprocess scans.
 * Full scans that honour ignore rules can reuse a report when the tracked inputs match.
 * Diff and include-ignored requests always run a new scan.
 *
 * @phpstan-type ScanFormState array{
 *     project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string,
 *     noBaseline: string, noConfig: string, deepScanBudget: string, includeIgnored: string, reportInteractive: string
 * }
 */
final class DashboardScanRunner
{
    /**
     * Maximum number of dashboard scan results retained in memory.
     */
    private const MAX_CACHE_ENTRIES = 12;

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
     * @param array<string, string>   $query                   - Submitted form values; empty input uses the launch defaults.
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
            $fingerprint  = $this->scanInputFingerprint($scanRoot, $paths, $state);
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
            // When a large project exceeds the chosen timeout, show the failure details rather than waiting for the scan indefinitely.
            $stderr   = $exception->getMessage();
            $exitCode = Command::FAILURE;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $html       = $process->getOutput();

        // With no report body to render, show stderr in an error panel instead of an empty iframe.
        if ($html === '') {
            return $renderer->errorHtml('The scan did not produce HTML output.', $stderr === '' ? 'No stderr output.' : $stderr, $exitCode, $durationMs);
        }


        // Fresh responses are reusable only when the dashboard could fingerprint the requested scan.
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
     * Fingerprints discovered files, the active config, and the baseline before reusing a dashboard report.
     *
     * @param         string                $scanRoot - Selected project root for relative paths.
     * @param         list<string>          $paths    - Selected scan targets. An empty list uses default discovery.
     * @param         array<string, string> $state    - Submitted form state for config and baseline choices.
     * @phpstan-param ScanFormState         $state    - Normalized dashboard form values.
     *
     * @return string - Content hash used to decide whether the dashboard can reuse a report.
     */
    private function scanInputFingerprint(string $scanRoot, array $paths, array $state): string
    {
        $discovery = (new SourceDiscovery($scanRoot))->discover($paths);
        // Source discovery is a superset of the configured scan. It also captures Git and built-in ignore changes.
        $fingerprintInputs = [$scanRoot, $state['deepScanBudget'], serialize($discovery->missingPaths), serialize($discovery->ignoredPathDetails)];
        // Watch every discoverable file so a browser refresh can see edits in the same scope as analysis.
        foreach ($discovery->files as $file) {
            $fingerprintInputs[] = $this->fileFingerprint($file->absolutePath);
        }

        // A scan requested with --no-config cannot change when a config file is edited.
        if ($state['noConfig'] !== '1') {
            $loader = new ConfigLoader($scanRoot, ConfigLoader::packageRoot());
            try {
                $configPath = $loader->resolveConfigPath($state['config']);
                // Include the selected config so the next dashboard refresh sees a setting change.
                if ($configPath !== null) {
                    $fingerprintInputs[] = $this->fileFingerprint($configPath);
                }
            } catch (ConfigException) {
                // A selected config may have been moved. Let the child report the missing file and use a different cache key when it returns.
                $fingerprintInputs[] = 'unresolved-config';
            }
        }

        // Check either the requested baseline or the default one so accepted findings refresh correctly.
        if ($state['noBaseline'] !== '1') {
            $baseline            = PathHelper::resolveAgainst($scanRoot, $state['baseline'] === '' ? 'gruff-baseline.json' : $state['baseline']);
            $fingerprintInputs[] = is_file($baseline) ? $this->fileFingerprint($baseline) : 'missing:' . $baseline;
        }

        sort($fingerprintInputs, SORT_STRING);

        return hash('sha256', implode("\n", $fingerprintInputs));
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
