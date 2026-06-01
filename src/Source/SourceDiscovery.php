<?php

declare(strict_types=1);

namespace GruffPhp\Source;

use GruffPhp\Support\PathHelper;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * Discovers project files for analysis while applying built-in and configured ignores.
 */
final readonly class SourceDiscovery
{
    /**
     * File extension treated as PHP source.
     */
    private const PHP_EXTENSION = 'php';

    /** @var list<string> */
    private const TEXT_EXTENSIONS = [
        'conf',
        'config',
        'env',
        'ini',
        'json',
        'md',
        'neon',
        'sh',
        'toml',
        'xml',
        'yaml',
        'yml',
    ];

    /** @var list<string> */
    private const TEXT_FILENAMES = [
        '.editorconfig',
        '.gitattributes',
        '.gitignore',
    ];

    /**
     * Shared ignore engine used for every exclusion decision.
     */
    private PathIgnoreResolver $ignoreResolver;

    /**
     * Build the source-discovery scanner for the given project root.
     *
     * @param string $projectRoot - Project root used to resolve requested paths.
     */
    public function __construct(private string $projectRoot)
    {
        $this->ignoreResolver = new PathIgnoreResolver($projectRoot);
    }

    /**
     * @param list<string> $paths - Requested paths to discover.
     * @param bool         $shouldIncludeIgnored - Whether built-in ignored paths should still be included.
     * @param list<string> $configuredIgnorePatterns - Additional ignore patterns from config.
     *
     * @return SourceDiscoveryResult - discovered source files plus the missing inputs and ignored-path records the caller reports back
     */
    public function discover(array $paths, bool $shouldIncludeIgnored = false, array $configuredIgnorePatterns = []): SourceDiscoveryResult
    {
        $requestedPaths = $paths === [] ? ['.'] : $paths;

        if (!$shouldIncludeIgnored) {
            $gitResult = $this->discoverGitVisible($requestedPaths, $configuredIgnorePatterns);
            if ($gitResult instanceof SourceDiscoveryResult) {
                // Git's tracked/unignored view is authoritative when available, so skip the filesystem walk.
                return $gitResult;
            }
        }

        $files          = [];
        $missingPaths   = [];
        $ignoredDetails = [];

        foreach ($requestedPaths as $path) {
            $this->collectPath(
                path:                     $path,
                shouldIncludeIgnored:     $shouldIncludeIgnored,
                configuredIgnorePatterns: $configuredIgnorePatterns,
                files:                    $files,
                missingPaths:             $missingPaths,
                ignoredDetails:           $ignoredDetails,
            );
        }

        ksort($files, SORT_STRING);
        sort($missingPaths, SORT_STRING);
        $ignoredDetails = $this->finalizeIgnored($ignoredDetails);

        return new SourceDiscoveryResult(array_values($files), $missingPaths, $this->pathsFromDetails($ignoredDetails), $ignoredDetails);
    }

    /**
     * Resolve one requested path into discovered files, missing inputs, or ignore records.
     *
     * @param string                    $path - Requested path to resolve against the project root.
     * @param bool                      $shouldIncludeIgnored - Whether built-in ignored paths should still be included.
     * @param list<string>              $configuredIgnorePatterns - Additional ignore patterns from config.
     * @param array<string, SourceFile> $files - Discovered files keyed by canonical path; appended in place.
     * @param list<string>              $missingPaths - Requested paths that do not exist; appended in place.
     * @param list<IgnoredPath>         $ignoredDetails - Ignored-path records; appended in place.
     *
     * @return void
     */
    private function collectPath(
        string $path,
        bool   $shouldIncludeIgnored,
        array  $configuredIgnorePatterns,
        array  &$files,
        array  &$missingPaths,
        array  &$ignoredDetails,
    ): void {
        $absolutePath = $this->absolutePath($path);

        if (!file_exists($absolutePath)) {
            $missingPaths[] = $path;

            // A non-existent input is reported as missing rather than silently dropped from discovery.
            return;
        }

        $displayPath = $this->displayPath($absolutePath);
        $decision    = $this->ignoreResolver->decide($displayPath, $absolutePath, $configuredIgnorePatterns, $shouldIncludeIgnored);
        if ($decision->ignored) {
            $ignoredDetails[] = IgnoredPath::from($displayPath, $decision);

            // An ignored input contributes an ignore record but never a discovered file.
            return;
        }

        if (is_file($absolutePath)) {
            $type = $this->sourceType($absolutePath);
            if ($type !== null) {
                $files[$this->canonicalPath($absolutePath)] = new SourceFile(
                    $this->canonicalPath($absolutePath),
                    $this->displayPath($absolutePath),
                    $type,
                );
            }

            // A single requested file needs no directory walk; stop once it is classified.
            return;
        }

        if (is_dir($absolutePath)) {
            foreach ($this->walkDirectory($absolutePath, $shouldIncludeIgnored, $configuredIgnorePatterns, $ignoredDetails) as $file) {
                $canonicalPath = $this->canonicalPath($file->getPathname());
                $type          = $this->sourceType($canonicalPath);

                if ($type !== null) {
                    $files[$canonicalPath] = new SourceFile($canonicalPath, $this->displayPath($canonicalPath), $type);
                }
            }
        }
    }

    /**
     * Yield source files below a directory while applying ignore patterns.
     *
     * @param string            $directory - Existing directory whose tree is recursively scanned.
     * @param bool              $shouldIncludeIgnored - When true, default/generated ignores are bypassed so those files surface too.
     * @param list<string>      $configuredIgnorePatterns - Additional ignore patterns from config.
     * @param list<IgnoredPath> $ignoredDetails - Ignore records discovered while walking; appended in place.
     *
     * @return iterable<SplFileInfo> - lazily yielded regular files under the directory that classify as source; ignored nodes and their subtrees are
     *                               pruned
     */
    private function walkDirectory(
        string $directory,
        bool   $shouldIncludeIgnored,
        array  $configuredIgnorePatterns,
        array  &$ignoredDetails,
    ): iterable {
        $recursiveDirectoryIterator      = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $recursiveCallbackFilterIterator = new RecursiveCallbackFilterIterator(
            $recursiveDirectoryIterator,
            function (SplFileInfo $file, mixed $key, RecursiveIterator $recursiveIterator) use ($shouldIncludeIgnored, $configuredIgnorePatterns, &$ignoredDetails): bool {
                $path        = $file->getPathname();
                $isDir       = $file->isDir();
                $displayPath = $this->displayPath($path);
                $decision    = $this->ignoreResolver->decide($displayPath, $path, $configuredIgnorePatterns, $shouldIncludeIgnored);

                if (!$decision->ignored) {
                    // Keeping the node lets the iterator descend into it and yield its files.
                    return true;
                }

                // Configured ignores are recorded for files and directories alike;
                // built-in default/generated ignores are only surfaced for directories.
                if ($decision->source === PathIgnoreResolver::SOURCE_CONFIG || $isDir) {
                    $ignoredDetails[] = IgnoredPath::from($displayPath, $decision);
                }

                // Pruning the node here also prunes its whole subtree from the walk.
                return false;
            },
        );

        $recursiveIteratorIterator = new RecursiveIteratorIterator($recursiveCallbackFilterIterator, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($recursiveIteratorIterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isFile() && $this->sourceType($file->getPathname()) !== null) {
                yield $file;
            }
        }
    }

    /**
     * Resolve a user-supplied path against the project root, returning an absolute filesystem path.
     *
     * @param string $path - Relative or absolute path as typed by the user.
     *
     * @return string - absolute filesystem path; relative inputs are anchored to the project root, absolute inputs returned unchanged
     */
    private function absolutePath(string $path): string
    {
        return PathHelper::resolveAgainst($this->projectRoot, $path);
    }

    /**
     * Canonicalise a path via realpath(), falling back to the original string when the file does not exist.
     *
     * @param string $path - Absolute path to canonicalise; need not exist on disk.
     *
     * @return string - symlink-resolved canonical path when the file exists, otherwise the input string verbatim
     */
    private function canonicalPath(string $path): string
    {
        return PathHelper::canonical($path);
    }

    /**
     * Format a path for user-facing output relative to the project root; the root itself renders as ".".
     *
     * @param string $path - Absolute path to render for display.
     *
     * @return string - project-root-relative path for inputs inside the root (the root itself as "."), or the canonical absolute path when outside it
     */
    private function displayPath(string $path): string
    {
        return PathHelper::relativeToRoot($path, $this->projectRoot) ?? PathHelper::canonical($path);
    }

    /**
     * Classify the file as PHP, text-config, or unsupported (null) based on extension and env-like naming.
     *
     * @param string $path - Path whose extension and basename decide the source type.
     *
     * @return string|null - SourceFile::TYPE_PHP for .php, TYPE_TEXT for recognised config/dotfiles, or null when the file is unsupported and
     *                     excluded from discovery
     */
    private function sourceType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === self::PHP_EXTENSION) {
            // A .php extension routes the file to the AST-based analysis path.
            return SourceFile::TYPE_PHP;
        }

        if (
            in_array($extension, self::TEXT_EXTENSIONS, true)
            || in_array(basename($path), self::TEXT_FILENAMES, true)
            || $this->isEnvLikeFile($path)
        ) {
            // Recognised config/dotfile extensions route to the text-based secret/content scanners.
            return SourceFile::TYPE_TEXT;
        }

        // Unrecognised files are excluded from discovery rather than scanned blindly.
        return null;
    }

    /**
     * Detect whether the file's basename is `.env` or `.env.*`.
     *
     * @param string $path - Path whose basename is tested against env-file naming.
     *
     * @return bool - true when the basename is `.env` or a `.env.*` variant that the text scanners treat as secret-bearing, false otherwise
     */
    private function isEnvLikeFile(string $path): bool
    {
        $basename = basename($path);

        return $basename === '.env' || str_starts_with($basename, '.env.');
    }

    /**
     * Discover files through Git's tracked plus unignored-untracked view of the worktree.
     *
     * @param list<string> $requestedPaths - User-requested paths, or empty to discover the whole project.
     * @param list<string> $configuredIgnorePatterns - Project config ignore patterns applied after Git visibility.
     *
     * @return SourceDiscoveryResult|null - the Git-derived discovery result, or null when Git discovery is unavailable so the caller falls back to
     *                                    the filesystem walk
     */
    private function discoverGitVisible(array $requestedPaths, array $configuredIgnorePatterns): ?SourceDiscoveryResult
    {
        if (!$this->isGitWorkTree()) {
            // Outside a Git worktree there is no tracked view, so the caller falls back to the filesystem walk.
            return null;
        }

        $request = $this->buildGitDiscoveryRequest($requestedPaths, $configuredIgnorePatterns);
        if ($request === null) {
            // A path that cannot be expressed as a pathspec (outside the root) forces the filesystem fallback.
            return null;
        }

        if ($request['pathspecs'] === []) {
            // Every requested path was missing or ignored, so report that without invoking Git.
            return $this->emptyGitDiscoveryResult($request['missingPaths'], $request['ignoredDetails']);
        }

        $visiblePaths = $this->gitVisiblePathspecs($request['pathspecs']);
        if ($visiblePaths === null) {
            // A failed `git ls-files` invocation is non-fatal here; the caller retries via the filesystem walk.
            return null;
        }

        $ignoredDetails = array_merge(
            $request['ignoredDetails'],
            $this->ignoredRequestedGitPaths($request['requestedExistingPaths'], $visiblePaths),
        );
        $sourceResult   = $this->sourceFilesFromGitVisiblePaths($visiblePaths, $configuredIgnorePatterns);
        $files          = $sourceResult['files'];
        $ignoredDetails = array_merge($ignoredDetails, $sourceResult['ignoredDetails']);
        $missingPaths   = $request['missingPaths'];

        ksort($files, SORT_STRING);
        sort($missingPaths, SORT_STRING);
        $ignoredDetails = $this->finalizeIgnored($ignoredDetails);

        return new SourceDiscoveryResult(array_values($files), $missingPaths, $this->pathsFromDetails($ignoredDetails), $ignoredDetails);
    }

    /**
     * Build git discovery request for the source discovery.
     *
     * @param list<string> $requestedPaths - User-requested paths, or empty to build a root-wide Git pathspec.
     * @param list<string> $configuredIgnorePatterns - Project config ignore patterns used to preclassify requested paths.
     *
     * @return array|null - pre-resolved Git query inputs (pathspecs to list plus missing/ignored
     *                      records found so far), or null when any request reaches outside the
     *                      project root and Git discovery must be abandoned
     * @phpstan-return array{
     *     missingPaths: list<string>,
     *     ignoredDetails: list<IgnoredPath>,
     *     pathspecs: list<string>,
     *     requestedExistingPaths: list<array{absolutePath: string, pathspec: string, isFile: bool}>
     * }|null
     */
    private function buildGitDiscoveryRequest(array $requestedPaths, array $configuredIgnorePatterns): ?array
    {
        $missingPaths   = [];
        $ignoredDetails = [];
        $pathspecs      = [];
        /** @var list<array{absolutePath: string, pathspec: string, isFile: bool}> $requestedExistingPaths Existing request metadata checked after Git visibility is known. */
        $requestedExistingPaths = [];

        foreach ($requestedPaths as $path) {
            $absolutePath = $this->absolutePath($path);

            if (!file_exists($absolutePath)) {
                $missingPaths[] = $path;
                continue;
            }

            $displayPath = $this->displayPath($absolutePath);
            $decision    = $this->ignoreResolver->decide($displayPath, $absolutePath, $configuredIgnorePatterns, false);
            if ($decision->ignored) {
                $ignoredDetails[] = IgnoredPath::from($displayPath, $decision);
                continue;
            }

            $pathspec = $this->gitPathspec($absolutePath);
            if ($pathspec === null) {
                // A request reaching outside the project root cannot use Git discovery at all; abandon it wholesale.
                return null;
            }

            $pathspecs[]              = $pathspec;
            $requestedExistingPaths[] = [
                'absolutePath' => $absolutePath,
                'pathspec'     => $pathspec,
                'isFile'       => is_file($absolutePath),
            ];
        }

        return [
            'missingPaths'           => $missingPaths,
            'ignoredDetails'         => $ignoredDetails,
            'pathspecs'              => $pathspecs,
            'requestedExistingPaths' => $requestedExistingPaths,
        ];
    }

    /**
     * @param list<string>      $missingPaths - Requested paths that were already known to be absent.
     * @param list<IgnoredPath> $ignoredDetails - Ignored requested paths collected before Git listing.
     *
     * @return SourceDiscoveryResult - a file-less result that still reports the missing and ignored inputs, so the user sees why nothing was analysed
     */
    private function emptyGitDiscoveryResult(array $missingPaths, array $ignoredDetails): SourceDiscoveryResult
    {
        sort($missingPaths, SORT_STRING);
        $ignoredDetails = $this->finalizeIgnored($ignoredDetails);

        return new SourceDiscoveryResult([], $missingPaths, $this->pathsFromDetails($ignoredDetails), $ignoredDetails);
    }

    /**
     * @param list<array{absolutePath: string, pathspec: string, isFile: bool}> $requestedExistingPaths - Existing requested paths expressed as Git pathspecs.
     * @param list<string>                                                      $visiblePaths - Root-relative paths returned by `git ls-files`.
     *
     * @return list<IgnoredPath> - one record per explicitly-requested existing path that Git's view or generated-file protection withheld,
     *                           explaining each omission
     */
    private function ignoredRequestedGitPaths(array $requestedExistingPaths, array $visiblePaths): array
    {
        $ignoredDetails = [];

        foreach ($requestedExistingPaths as $requestedPath) {
            if ($this->hasVisibleFileForPathspec($requestedPath['pathspec'], $visiblePaths, $requestedPath['isFile'])) {
                continue;
            }

            $displayPath = $this->displayPath($requestedPath['absolutePath']);
            $gitRule     = $this->ignoreResolver->gitIgnoreRule($requestedPath['pathspec']);
            if ($gitRule !== null) {
                $ignoredDetails[] = new IgnoredPath($displayPath, PathIgnoreResolver::SOURCE_GITIGNORE, $gitRule);
                continue;
            }

            $generatedFilename = $this->ignoreResolver->matchedGeneratedFilename($requestedPath['absolutePath']);
            if ($generatedFilename !== null) {
                $ignoredDetails[] = new IgnoredPath($displayPath, PathIgnoreResolver::SOURCE_GENERATED, $generatedFilename);
            }
        }

        return $ignoredDetails;
    }

    /**
     * Build source file objects from paths reported by git.
     *
     * @param list<string> $visiblePaths - Root-relative paths returned by `git ls-files`.
     * @param list<string> $configuredIgnorePatterns - Project config ignore patterns applied before creating SourceFile objects.
     *
     * @return array{files: array<string, SourceFile>, ignoredDetails: list<IgnoredPath>} - the Git-visible set split into accepted source files
     *                      keyed by canonical path and the records for entries held back by config/default/generated ignores
     */
    private function sourceFilesFromGitVisiblePaths(array $visiblePaths, array $configuredIgnorePatterns): array
    {
        $files          = [];
        $ignoredDetails = [];

        foreach ($visiblePaths as $displayPath) {
            $this->appendGitVisibleSourceFile($displayPath, $configuredIgnorePatterns, $files, $ignoredDetails);
        }

        return [
            'files'          => $files,
            'ignoredDetails' => $ignoredDetails,
        ];
    }

    /**
     * Append git visible source file details to report output.
     *
     * @param string                    $displayPath - Root-relative path emitted by `git ls-files` to classify.
     * @param list<string>              $configuredIgnorePatterns - Additional ignore patterns from config.
     * @param array<string, SourceFile> $files - Accepted files keyed by canonical path; appended in place.
     * @param list<IgnoredPath>         $ignoredDetails - Ignore records for paths held back; appended in place.
     *
     * @return void
     */
    private function appendGitVisibleSourceFile(
        string $displayPath,
        array  $configuredIgnorePatterns,
        array  &$files,
        array  &$ignoredDetails,
    ): void {
        $absolutePath = $this->projectRoot . '/' . $displayPath;

        if (!is_file($absolutePath)) {
            // Git can list a path that is no longer a regular file (deleted or a directory); skip it.
            return;
        }

        $relativeDisplayPath = $this->displayPath($absolutePath);

        $configuredPattern = $this->ignoreResolver->matchedConfiguredPattern($relativeDisplayPath, $configuredIgnorePatterns);
        if ($configuredPattern !== null) {
            $ignoredDetails[] = new IgnoredPath(
                $this->configuredIgnoredDisplayPath($absolutePath, $configuredIgnorePatterns),
                PathIgnoreResolver::SOURCE_CONFIG,
                $configuredPattern,
            );

            // Config ignores override Git visibility: record the ignore and never add the file.
            return;
        }

        $defaultDirectory = $this->ignoreResolver->matchedDefaultDirectory($relativeDisplayPath);
        if ($defaultDirectory !== null) {
            $ignoredDetails[] = new IgnoredPath($relativeDisplayPath, PathIgnoreResolver::SOURCE_DEFAULT, $defaultDirectory);

            // Built-in default-directory ignores (vendor, node_modules, ...) likewise win over Git visibility.
            return;
        }

        $generatedFilename = $this->ignoreResolver->matchedGeneratedFilename($absolutePath);
        if ($generatedFilename !== null) {
            $ignoredDetails[] = new IgnoredPath($relativeDisplayPath, PathIgnoreResolver::SOURCE_GENERATED, $generatedFilename);

            // Tracked generated files (lockfiles, etc.) are recorded as ignored rather than analysed.
            return;
        }

        $type = $this->sourceType($absolutePath);
        if ($type === null) {
            // An unsupported extension is neither a source file nor an ignore worth reporting; drop it silently.
            return;
        }

        $canonicalPath         = $this->canonicalPath($absolutePath);
        $files[$canonicalPath] = new SourceFile($canonicalPath, $this->displayPath($absolutePath), $type);
    }

    /**
     * @return bool - true only when `git rev-parse` ran successfully and confirmed the project root is inside a worktree, gating all Git-based
     *              discovery
     */
    private function isGitWorkTree(): bool
    {
        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $this->projectRoot);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === 'true';
    }

    /**
     * @param list<string> $pathspecs - Git pathspecs to pass after `--`; empty input is handled by the caller.
     *
     * @return list<string>|null - deduplicated, sorted root-relative paths Git treats as tracked or unignored-untracked, or null when `git ls-files`
     *                           fails so the caller retries via the filesystem walk
     */
    private function gitVisiblePathspecs(array $pathspecs): ?array
    {
        $command = array_merge(
            ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--'],
            array_values(array_unique($pathspecs)),
        );
        $process = new Process($command, $this->projectRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            // Signal failure with null so the caller can fall back to the filesystem walk rather than report zero files.
            return null;
        }

        $paths = array_values(array_filter(
                                  explode("\0", $process->getOutput()),
                                  static fn(string $path): bool => $path !== '',
                              ));
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Convert an existing project path into a Git pathspec relative to the project root.
     *
     * @param string $absolutePath - Existing absolute path to express relative to the project root.
     *
     * @return string|null - the path expressed relative to the worktree ("." for the root itself), or null when it sits outside the project root and
     *                     Git discovery must be dropped
     */
    private function gitPathspec(string $absolutePath): ?string
    {
        $root          = rtrim($this->canonicalPath($this->projectRoot), '/');
        $canonicalPath = $this->canonicalPath($absolutePath);

        if ($canonicalPath === $root) {
            // The root maps to "." so Git scans the whole worktree.
            return '.';
        }

        if (str_starts_with($canonicalPath, $root . '/')) {
            // Strip the root prefix to yield a path Git interprets relative to the worktree.
            return substr($canonicalPath, strlen($root) + 1);
        }

        // Paths outside the worktree have no pathspec, signalling the caller to drop Git discovery.
        return null;
    }

    /**
     * @param string       $pathspec - Requested pathspec to look for among the visible paths.
     * @param list<string> $visiblePaths - Root-relative paths Git reported as visible.
     * @param bool         $isFile - True when the request was a file, so only an exact match counts; directories also match by prefix.
     *
     * @return bool - true when the pathspec matched a visible path (an exact match for a file request, the directory or anything beneath it
     *              otherwise), false when the input was withheld
     */
    private function hasVisibleFileForPathspec(string $pathspec, array $visiblePaths, bool $isFile): bool
    {
        $normalizedPathspec = trim($pathspec, '/');

        if ($normalizedPathspec === '' || $normalizedPathspec === '.') {
            // A whole-root request is satisfied by any visible path at all.
            return $visiblePaths !== [];
        }

        foreach ($visiblePaths as $visiblePath) {
            if ($isFile && $visiblePath === $normalizedPathspec) {
                // A file request matches only its own exact path.
                return true;
            }

            if (!$isFile && ($visiblePath === $normalizedPathspec || str_starts_with($visiblePath, $normalizedPathspec . '/'))) {
                // A directory request matches the directory itself or anything beneath it.
                return true;
            }
        }

        // No visible path matched, so the requested input was withheld by Git or ignore rules.
        return false;
    }

    /**
     * Return a compact ignored path for configured glob patterns.
     *
     * @param string       $path - Absolute path of the ignored file to present compactly.
     * @param list<string> $patterns - Configured ignore globs whose `/**` directory form collapses the report.
     *
     * @return string - the directory base when a `dir/**` glob covers the file (collapsing the report to one entry), otherwise the file's own
     *                root-relative display path
     */
    private function configuredIgnoredDisplayPath(string $path, array $patterns): string
    {
        $displayPath = str_replace('\\', '/', $this->displayPath($path));

        foreach ($patterns as $pattern) {
            $normalizedPattern = trim(str_replace('\\', '/', $pattern), '/');
            if (!str_ends_with($normalizedPattern, '/**')) {
                continue;
            }

            $base = substr($normalizedPattern, 0, -3);
            if ($displayPath === $base || str_starts_with($displayPath, $base . '/')) {
                // Report the directory base once instead of every file under a `dir/**` ignore.
                return $base;
            }
        }

        return $displayPath;
    }

    /**
     * Reduce ignored details to one entry per path, sorted for stable reporting.
     *
     * @param list<IgnoredPath> $ignoredDetails - Ignored-path records that may contain duplicate paths from different discovery stages.
     *
     * @return list<IgnoredPath> - one record per path in stable path order, so repeated runs and snapshots stay deterministic
     */
    private function finalizeIgnored(array $ignoredDetails): array
    {
        $byPath = [];
        foreach ($ignoredDetails as $ignoredPath) {
            $byPath[$ignoredPath->path] ??= $ignoredPath;
        }

        $deduped = array_values($byPath);
        usort($deduped, static fn(IgnoredPath $left, IgnoredPath $right): int => strcmp($left->path, $right->path));

        return $deduped;
    }

    /**
     * Project the ignored-path display strings from the enriched details.
     *
     * @param list<IgnoredPath> $ignoredDetails - Enriched ignored-path records whose path strings feed the legacy plain list.
     *
     * @return list<string> - just the path strings extracted from the detail records, for the legacy plain-list field alongside the richer records
     */
    private function pathsFromDetails(array $ignoredDetails): array
    {
        return array_map(static fn(IgnoredPath $ignoredPath): string => $ignoredPath->path, $ignoredDetails);
    }
}
