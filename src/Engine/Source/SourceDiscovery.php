<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

use GruffPhp\Support\PathHelper;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * Discovers supported PHP and text files, preferring Git listings and falling back to a filesystem walk.
 * Existing file operands bypass Git and built-in exclusions except VCS internals; configured ignores still apply.
 * Returns sorted files deduplicated by canonical path, missing inputs, and one ignore record per path.
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
     * Builds the scanner for one project root and wires up the shared ignore engine.
     *
     * @param string $projectRoot - Project root used to resolve requested paths.
     */
    public function __construct(private string $projectRoot)
    {
        $this->ignoreResolver = new PathIgnoreResolver($projectRoot);
    }

    /**
     * Resolves the user's requested paths into the concrete set of files to analyse, preferring Git's
     * view and falling back to a filesystem walk - the entry point for "what does this run scan?".
     *
     * @param list<string> $paths - Requested paths to discover; empty means the whole project.
     * @param bool $shouldIncludeIgnored - Bypass Git and built-in exclusions except VCS internals; config ignores still apply.
     * @param list<string> $configuredIgnorePatterns - Additional ignore patterns from config.
     *
     * @return SourceDiscoveryResult - discovered source files plus the missing inputs and ignored-path records the caller reports back.
     */
    public function discover(array $paths, bool $shouldIncludeIgnored = false, array $configuredIgnorePatterns = []): SourceDiscoveryResult
    {

        $requestedPaths = $paths === [] ? ['.'] : $paths;

        // Unless the user asked to include ignored files, try Git's own view first - it respects .gitignore for us.
        if (!$shouldIncludeIgnored) {
            $gitResult = $this->discoverGitVisible($requestedPaths, $configuredIgnorePatterns);
            // Git's tracked/unignored view is authoritative when available, so skip the filesystem walk.
            if ($gitResult instanceof SourceDiscoveryResult) {
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
     * Resolves one requested path for the filesystem fallback, recording supported files, missing inputs,
     * or exclusions when applicable.
     *
     * @param string                    $path - Requested path to resolve against the project root.
     * @param bool $shouldIncludeIgnored - Bypass built-in exclusions except VCS internals; config ignores still apply.
     * @param list<string>              $configuredIgnorePatterns - Additional ignore patterns from config.
     * @param array<string, SourceFile> $files - Discovered files keyed by canonical path; updated in place.
     * @param list<string>              $missingPaths - Requested paths that do not exist; appended in place.
     * @param list<IgnoredPath>         $ignoredDetails - Ignored-path records; appended in place.
     *
     * @return void - Updates discovered files, missing paths, and ignore records in place.
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

            return;
        }

        $displayPath    = $this->displayPath($absolutePath);
        $isExplicitFile = is_file($absolutePath);
        $decision       = $this->ignoreResolver->decide(
            $displayPath,
            $absolutePath,
            $configuredIgnorePatterns,
            $shouldIncludeIgnored,
            $isExplicitFile,
        );

        if ($decision->ignored) {
            $ignoredDetails[] = IgnoredPath::from($displayPath, $decision);

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
     * Lazily yields the analysable files under a directory, pruning ignored nodes and their whole
     * subtrees so an ignored folder is never descended into.
     *
     * @param string            $directory - Existing directory whose tree is recursively scanned.
     * @param bool $shouldIncludeIgnored - Bypass built-in exclusions except VCS internals; config ignores still apply.
     * @param list<string>      $configuredIgnorePatterns - Additional ignore patterns from config.
     * @param list<IgnoredPath> $ignoredDetails - Ignore records discovered while walking; appended in place.
     *
     * @return iterable<SplFileInfo> - Supported regular files outside pruned ignore subtrees.
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
                $decision    = $this->ignoreResolver->decide($displayPath, $path, $configuredIgnorePatterns, $shouldIncludeIgnored, false, $isDir);


                if (!$decision->ignored) {

                    return true;
                }

                // Configured ignores are recorded for files and directories alike; built-in default
                // ignores are surfaced for directories, and for a minified file only when gruff-php would scan it.
                if ($decision->source === PathIgnoreResolver::SOURCE_CONFIG || $isDir || $this->isListedMinifiedFile($decision, $path)) {
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
     * Resolves a requested path against the project root after normalizing separators.
     *
     * @param string $path - Relative or absolute path as typed by the user.
     *
     * @return string - Normalized absolute input, or the root joined to relative input; empty input selects the root.
     */
    private function absolutePath(string $path): string
    {
        return PathHelper::resolveAgainst($this->projectRoot, $path);
    }

    /**
     * Uses realpath() when available, otherwise normalizes separators and collapses dot segments textually.
     *
     * @param string $path - Absolute path to canonicalise; need not exist on disk.
     *
     * @return string - Resolved path when realpath() succeeds; normalized, dot-collapsed input otherwise.
     */
    private function canonicalPath(string $path): string
    {
        return PathHelper::canonical($path);
    }

    /**
     * Renders a path for user-facing output relative to the project root; the root itself renders as ".".
     *
     * @param string $path - Absolute path to render for display.
     *
     * @return string - Root-relative path ("." for the root), or the canonical absolute path for an input outside the root.
     */
    private function displayPath(string $path): string
    {
        // A path outside the project root has no relative form, so fall back to its canonical absolute path.
        return PathHelper::relativeToRoot($path, $this->projectRoot) ?? PathHelper::canonical($path);
    }

    /**
     * Reports whether a minified ignore record names a supported source file and belongs in the ignored-path report.
     *
     * @param IgnoreDecision $decision - The ignore verdict for the walked file.
     * @param string         $path - Absolute path of the walked file.
     *
     * @return bool - True when the verdict is the minified class and the file is a type gruff-php scans.
     */
    private function isListedMinifiedFile(IgnoreDecision $decision, string $path): bool
    {
        return $decision->pattern === PathIgnoreResolver::MINIFIED_FILE_PATTERN && $this->sourceType($path) !== null;
    }

    /**
     * Classifies a path from its extension and basename so discovery keeps only names mapped to PHP or text scanning.
     *
     * @param string $path - Path whose extension and basename decide the source type.
     *
     * @return string|null - SourceFile::TYPE_PHP or SourceFile::TYPE_TEXT for supported files; null for unsupported files.
     */
    private function sourceType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));


        if ($extension === self::PHP_EXTENSION) {
            return SourceFile::TYPE_PHP;
        }


        if (
            in_array($extension, self::TEXT_EXTENSIONS, true)
            || in_array(basename($path), self::TEXT_FILENAMES, true)
            || $this->isEnvLikeFile($path)
        ) {
            return SourceFile::TYPE_TEXT;
        }

        return null;
    }

    /**
     * Reports whether a path uses the .env basename or an .env. prefix.
     *
     * @param string $path - Path whose basename is tested against env-file naming.
     *
     * @return bool - True for the .env basename or an .env. prefix; false otherwise.
     */
    private function isEnvLikeFile(string $path): bool
    {
        $basename = basename($path);

        return $basename === '.env' || str_starts_with($basename, '.env.');
    }

    /**
     * Discovers Git-visible files plus allowed explicit file operands, or returns null so the caller can
     * fall back to a filesystem walk when Git discovery is unavailable or cannot represent the request.
     *
     * @param list<string> $requestedPaths - Requested paths; '.' selects the root and an empty list selects nothing.
     * @param list<string> $configuredIgnorePatterns - Project config ignore patterns applied after Git visibility.
     *
     * @return SourceDiscoveryResult|null - Git-based discovery result, or null to request a filesystem fallback.
     */
    private function discoverGitVisible(array $requestedPaths, array $configuredIgnorePatterns): ?SourceDiscoveryResult
    {
        // Outside a Git worktree there is no tracked view, so the caller falls back to the filesystem walk.
        if (!$this->isGitWorkTree()) {
            return null;
        }

        $request = $this->buildGitDiscoveryRequest($requestedPaths, $configuredIgnorePatterns);
        // A path that cannot be expressed as a pathspec (outside the root) forces the filesystem fallback.
        if ($request === null) {
            return null;
        }

        // No requested path remains for Git, so report the accumulated missing and ignored inputs without invoking it.
        if ($request['pathspecs'] === []) {
            return $this->emptyGitDiscoveryResult($request['missingPaths'], $request['ignoredDetails']);
        }

        $visiblePaths = $this->gitVisiblePathspecs($request['pathspecs']);
        // A failed git ls-files invocation is non-fatal here; the caller retries via the filesystem walk.
        if ($visiblePaths === null) {
            return null;
        }

        $explicitFilePathspecs = array_values(array_map(
            static fn(array $requestedPath): string => $requestedPath['pathspec'],
            array_filter(
                $request['requestedExistingPaths'],
                static fn(array $requestedPath): bool => $requestedPath['isFile'],
            ),
        ));
        $visiblePaths = array_values(array_unique(array_merge($visiblePaths, $explicitFilePathspecs)));
        sort($visiblePaths, SORT_STRING);

        $ignoredDetails = array_merge(
            $request['ignoredDetails'],
            $this->ignoredRequestedGitPaths($request['requestedExistingPaths'], $visiblePaths),
        );
        $sourceResult   = $this->sourceFilesFromGitVisiblePaths($visiblePaths, $configuredIgnorePatterns, $explicitFilePathspecs);
        $files          = $sourceResult['files'];
        $ignoredDetails = array_merge($ignoredDetails, $sourceResult['ignoredDetails']);
        $missingPaths   = $request['missingPaths'];

        ksort($files, SORT_STRING);
        sort($missingPaths, SORT_STRING);
        $ignoredDetails = $this->finalizeIgnored($ignoredDetails);

        return new SourceDiscoveryResult(array_values($files), $missingPaths, $this->pathsFromDetails($ignoredDetails), $ignoredDetails);
    }

    /**
     * Resolves existing, allowed requested paths into Git pathspecs and records missing or ignored inputs.
     * Returns null if an existing, non-ignored request resolves outside the project root.
     *
     * @param list<string> $requestedPaths - Requested paths; an empty list produces empty request lists.
     * @param list<string> $configuredIgnorePatterns - Project config ignore patterns used to preclassify requested paths.
     *
     * @return array|null - Git query inputs and prior exclusions, or null for an allowed path outside the root.
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
        /** @var list<array{absolutePath: string, pathspec: string, isFile: bool}> $requestedExistingPaths - Requests checked after Git output. */
        $requestedExistingPaths = [];


        foreach ($requestedPaths as $path) {
            $absolutePath = $this->absolutePath($path);


            if (!file_exists($absolutePath)) {
                $missingPaths[] = $path;
                continue;
            }

            $displayPath    = $this->displayPath($absolutePath);
            $isExplicitFile = is_file($absolutePath);
            $decision       = $this->ignoreResolver->decide(
                $displayPath,
                $absolutePath,
                $configuredIgnorePatterns,
                false,
                $isExplicitFile,
            );

            if ($decision->ignored) {
                $ignoredDetails[] = IgnoredPath::from($displayPath, $decision);
                continue;
            }

            $pathspec = $this->gitPathspec($absolutePath);
            // A request reaching outside the project root cannot use Git discovery at all; abandon it wholesale.
            if ($pathspec === null) {
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
     * Builds a file-less result that still reports every requested path already found missing or ignored
     * before Git file listing was needed.
     *
     * @param list<string>      $missingPaths - Requested paths that were already known to be absent.
     * @param list<IgnoredPath> $ignoredDetails - Ignored requested paths collected before Git listing.
     *
     * @return SourceDiscoveryResult - No source files, with sorted missing paths and deduplicated ignore records.
     */
    private function emptyGitDiscoveryResult(array $missingPaths, array $ignoredDetails): SourceDiscoveryResult
    {
        sort($missingPaths, SORT_STRING);
        $ignoredDetails = $this->finalizeIgnored($ignoredDetails);

        return new SourceDiscoveryResult([], $missingPaths, $this->pathsFromDetails($ignoredDetails), $ignoredDetails);
    }

    /**
     * Records Git ignore rules for requested paths that have no matching visible file.
     *
     * @param list<array{absolutePath: string, pathspec: string, isFile: bool}> $requestedExistingPaths - Existing operands for visibility checks.
     *
     * @param list<string> $visiblePaths - Git-listed paths plus explicitly requested files.
     *
     * @return list<IgnoredPath> - One record per unmatched request with a Git ignore rule; empty when none qualify.
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
            }
        }

        return $ignoredDetails;
    }

    /**
     * Turns Git-listed paths and explicit file operands into source files and ignore records.
     *
     * @param list<string> $visiblePaths - Git-listed paths plus explicit file operands.
     * @param list<string> $configuredIgnorePatterns - Project config ignore patterns applied before creating SourceFile objects.
     * @param list<string> $explicitFilePathspecs - Existing file operands that bypass Git and built-in exclusions except VCS internals.
     *
     * @return array{files: array<string, SourceFile>, ignoredDetails: list<IgnoredPath>} - Accepted files by canonical path and recorded exclusions.
     */
    private function sourceFilesFromGitVisiblePaths(
        array $visiblePaths,
        array $configuredIgnorePatterns,
        array $explicitFilePathspecs,
    ): array
    {
        $files          = [];
        $ignoredDetails = [];


        foreach ($visiblePaths as $displayPath) {
            $this->appendGitVisibleSourceFile(
                $displayPath,
                $configuredIgnorePatterns,
                in_array($displayPath, $explicitFilePathspecs, true),
                $files,
                $ignoredDetails,
            );
        }

        return [
            'files'          => $files,
            'ignoredDetails' => $ignoredDetails,
        ];
    }

    /**
     * Adds a source file or an ignore record after applying config and built-in exclusions.
     * Explicit file operands bypass built-in exclusions except VCS internals.
     *
     * @param string $displayPath - Root-relative Git-listed path or explicit file operand to classify.
     * @param list<string>              $configuredIgnorePatterns - Additional ignore patterns from config.
     * @param bool                      $isExplicitFile - Whether this path was supplied as an existing file operand.
     * @param array<string, SourceFile> $files - Accepted files keyed by canonical path; updated in place.
     * @param list<IgnoredPath>         $ignoredDetails - Ignore records for paths held back; appended in place.
     *
     * @return void - Updates the file map or ignore records in place when the path qualifies.
     */
    private function appendGitVisibleSourceFile(
        string $displayPath,
        array  $configuredIgnorePatterns,
        bool   $isExplicitFile,
        array  &$files,
        array  &$ignoredDetails,
    ): void {
        $absolutePath = $this->projectRoot . '/' . $displayPath;

        // Git can list a path that is no longer a regular file (deleted or a directory); skip it.
        if (!is_file($absolutePath)) {
            return;
        }

        $relativeDisplayPath = $this->displayPath($absolutePath);

        $decision = $this->ignoreResolver->decide(
            $relativeDisplayPath,
            $absolutePath,
            $configuredIgnorePatterns,
            false,
            $isExplicitFile,
            false,
        );
        // A minified file gruff-php would not scan anyway, such as jquery.min.js, stays unsupported rather than ignored.
        if ($decision->ignored && $decision->pattern === PathIgnoreResolver::MINIFIED_FILE_PATTERN && $this->sourceType($absolutePath) === null) {
            return;
        }

        if ($decision->ignored) {
            $ignoredDisplayPath = $decision->source === PathIgnoreResolver::SOURCE_CONFIG
                ? $this->configuredIgnoredDisplayPath($absolutePath, $configuredIgnorePatterns)
                : $relativeDisplayPath;
            $ignoredDetails[] = new IgnoredPath(
                $ignoredDisplayPath,
                (string) $decision->source,
                (string) $decision->pattern,
            );

            return;
        }

        $type = $this->sourceType($absolutePath);

        if ($type === null) {

            return;
        }

        $canonicalPath         = $this->canonicalPath($absolutePath);
        $files[$canonicalPath] = new SourceFile($canonicalPath, $this->displayPath($absolutePath), $type);
    }

    /**
     * Reports whether the project root sits inside a Git worktree, which gates all Git-based discovery.
     *
     * @return bool - True when git rev-parse succeeds and reports that the project root is inside a worktree.
     */
    private function isGitWorkTree(): bool
    {
        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $this->projectRoot);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === 'true';
    }

    /**
     * Lists tracked and unignored untracked paths for the requested Git pathspecs.
     * Returns null after an unsuccessful Git exit so the caller can use filesystem discovery.
     *
     * @param list<string> $pathspecs - Git pathspecs to pass after `--`; empty input is handled by the caller.
     *
     * @return list<string>|null - Sorted, unique Git-listed paths, or null when git ls-files exits unsuccessfully.
     */
    private function gitVisiblePathspecs(array $pathspecs): ?array
    {
        $command = array_merge(
            ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--'],
            array_values(array_unique($pathspecs)),
        );
        $process = new Process($command, $this->projectRoot);
        $process->run();

        // A failed `git ls-files` returns null so the caller falls back rather than reporting zero files.
        if (!$process->isSuccessful()) {

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
     * Expresses an existing path as a Git pathspec relative to the project root, or null when it sits
     * outside the root and Git discovery must be dropped.
     *
     * @param string $absolutePath - Existing absolute path to express relative to the project root.
     *
     * @return string|null - Root-relative path, '.' for the root, or null when the canonical path lies outside it.
     */
    private function gitPathspec(string $absolutePath): ?string
    {
        $root          = rtrim($this->canonicalPath($this->projectRoot), '/');
        $canonicalPath = $this->canonicalPath($absolutePath);


        if ($canonicalPath === $root) {
            return '.';
        }


        if (str_starts_with($canonicalPath, $root . '/')) {
            return substr($canonicalPath, strlen($root) + 1);
        }

        return null;
    }

    /**
     * Reports whether a requested pathspec matches an entry in the supplied visible-path set.
     *
     * @param string       $pathspec - Requested pathspec to look for among the visible paths.
     * @param list<string> $visiblePaths - Git-listed paths plus explicitly requested files.
     * @param bool         $isFile - True when the request was a file, so only an exact match counts; directories also match by prefix.
     *
     * @return bool - Whether any visible path matches; root requests match any entry, and file requests require equality.
     */
    private function hasVisibleFileForPathspec(string $pathspec, array $visiblePaths, bool $isFile): bool
    {
        $normalizedPathspec = trim($pathspec, '/');

        // A root-level request matches as long as the supplied visible-path set is nonempty.
        if ($normalizedPathspec === '' || $normalizedPathspec === '.') {
            return $visiblePaths !== [];
        }


        foreach ($visiblePaths as $visiblePath) {

            if ($isFile && $visiblePath === $normalizedPathspec) {
                return true;
            }


            if (!$isFile && ($visiblePath === $normalizedPathspec || str_starts_with($visiblePath, $normalizedPathspec . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uses a matching literal dir/** prefix to report an ignored directory instead of every file beneath it.
     *
     * @param string       $path - Absolute path of the ignored file to present compactly.
     * @param list<string> $patterns - Configured patterns checked for a literal directory prefix ending in /**.
     *
     * @return string - First matching dir/** base, or the ignored file's display path when no literal base matches.
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
                // A shared directory display path lets later deduplication collapse files covered by the same recursive directory pattern.
                return $base;
            }
        }

        return $displayPath;
    }

    /**
     * Reduces the ignored records to one entry per path, sorted, so reports stay stable run to run.
     *
     * @param list<IgnoredPath> $ignoredDetails - Ignored-path records that may contain duplicate paths from different discovery stages.
     *
     * @return list<IgnoredPath> - one record per path in stable path order, so repeated runs and snapshots stay deterministic.
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
     * Extracts just the path strings from the enriched ignore records, for the legacy plain-list field.
     *
     * @param list<IgnoredPath> $ignoredDetails - Enriched ignored-path records whose path strings feed the legacy plain list.
     *
     * @return list<string> - just the path strings extracted from the detail records, for the legacy plain-list field alongside the richer records.
     */
    private function pathsFromDetails(array $ignoredDetails): array
    {
        return array_map(static fn(IgnoredPath $ignoredPath): string => $ignoredPath->path, $ignoredDetails);
    }
}
