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
     * @param string $projectRoot Project root used to resolve requested paths.
     */
    public function __construct(private string $projectRoot)
    {
        $this->ignoreResolver = new PathIgnoreResolver($projectRoot);
    }

    /**
     * @param list<string> $paths                    Requested paths to discover.
     * @param bool         $shouldIncludeIgnored     Whether built-in ignored paths should still be included.
     * @param list<string> $configuredIgnorePatterns Additional ignore patterns from config.
     * @return SourceDiscoveryResult Files, missing inputs, and ignored paths.
     */
    public function discover(array $paths, bool $shouldIncludeIgnored = false, array $configuredIgnorePatterns = []): SourceDiscoveryResult
    {
        $requestedPaths = $paths === [] ? ['.'] : $paths;

        if (!$shouldIncludeIgnored) {
            $gitResult = $this->discoverGitVisible($requestedPaths, $configuredIgnorePatterns);
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
     * Resolve one requested path into discovered files, missing inputs, or ignore records.
     *
     * @param string       $path                     Requested path to resolve against the project root.
     * @param bool         $shouldIncludeIgnored     Whether built-in ignored paths should still be included.
     * @param list<string> $configuredIgnorePatterns Additional ignore patterns from config.
     * @param array<string, SourceFile> $files       Discovered files keyed by canonical path; appended in place.
     * @param list<string> $missingPaths             Requested paths that do not exist; appended in place.
     * @param list<IgnoredPath> $ignoredDetails      Ignored-path records; appended in place.
     * @return void
     */
    private function collectPath(
        string $path,
        bool $shouldIncludeIgnored,
        array $configuredIgnorePatterns,
        array &$files,
        array &$missingPaths,
        array &$ignoredDetails,
    ): void {
        $absolutePath = $this->absolutePath($path);

        if (!file_exists($absolutePath)) {
            $missingPaths[] = $path;
            return;
        }

        $displayPath = $this->displayPath($absolutePath);
        $decision    = $this->ignoreResolver->decide($displayPath, $absolutePath, $configuredIgnorePatterns, $shouldIncludeIgnored);
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
     * Yield source files below a directory while applying ignore patterns.
     *
     * @param list<IgnoredPath> $ignoredDetails
     * @param list<string>      $configuredIgnorePatterns
     * @return iterable<SplFileInfo>
     */
    private function walkDirectory(
        string $directory,
        bool $shouldIncludeIgnored,
        array $configuredIgnorePatterns,
        array &$ignoredDetails,
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
                    return true;
                }

                // Configured ignores are recorded for files and directories alike;
                // built-in default/generated ignores are only surfaced for directories.
                if ($decision->source === PathIgnoreResolver::SOURCE_CONFIG || $isDir) {
                    $ignoredDetails[] = IgnoredPath::from($displayPath, $decision);
                }

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
     * @return string
     */
    private function absolutePath(string $path): string
    {
        return PathHelper::resolveAgainst($this->projectRoot, $path);
    }

    /**
     * Canonicalise a path via realpath(), falling back to the original string when the file does not exist.
     *
     * @return string
     */
    private function canonicalPath(string $path): string
    {
        return PathHelper::canonical($path);
    }

    /**
     * Format a path for user-facing output relative to the project root; the root itself renders as ".".
     *
     * @return string
     */
    private function displayPath(string $path): string
    {
        return PathHelper::relativeToRoot($path, $this->projectRoot) ?? PathHelper::canonical($path);
    }

    /**
     * Classify the file as PHP, text-config, or unsupported (null) based on extension and env-like naming.
     *
     * @return string|null One of SourceFile::TYPE_PHP / TYPE_TEXT, or null when unsupported.
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
     * Detect whether the file's basename is `.env` or `.env.*`.
     *
     * @return bool
     */
    private function isEnvLikeFile(string $path): bool
    {
        $basename = basename($path);

        return $basename === '.env' || str_starts_with($basename, '.env.');
    }

    /**
     * Discover files through Git's tracked plus unignored-untracked view of the worktree.
     *
     * @param list<string> $requestedPaths
     * @param list<string> $configuredIgnorePatterns
     * @return SourceDiscoveryResult|null Discovery result, or null when Git discovery cannot be used.
     */
    private function discoverGitVisible(array $requestedPaths, array $configuredIgnorePatterns): ?SourceDiscoveryResult
    {
        if (!$this->isGitWorkTree()) {
            return null;
        }

        $request = $this->buildGitDiscoveryRequest($requestedPaths, $configuredIgnorePatterns);
        if ($request === null) {
            return null;
        }

        if ($request['pathspecs'] === []) {
            return $this->emptyGitDiscoveryResult($request['missingPaths'], $request['ignoredDetails']);
        }

        $visiblePaths = $this->gitVisiblePathspecs($request['pathspecs']);
        if ($visiblePaths === null) {
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
     * @param list<string> $requestedPaths
     * @param list<string> $configuredIgnorePatterns
     * @return array{missingPaths: list<string>, ignoredDetails: list<IgnoredPath>, pathspecs: list<string>, requestedExistingPaths: list<array{absolutePath: string, pathspec: string, isFile: bool}>}|null
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
                return null;
            }

            $pathspecs[]              = $pathspec;
            $requestedExistingPaths[] = [
                'absolutePath' => $absolutePath,
                'pathspec' => $pathspec,
                'isFile' => is_file($absolutePath),
            ];
        }

        return [
            'missingPaths' => $missingPaths,
            'ignoredDetails' => $ignoredDetails,
            'pathspecs' => $pathspecs,
            'requestedExistingPaths' => $requestedExistingPaths,
        ];
    }

    /**
     * @param list<string>      $missingPaths
     * @param list<IgnoredPath> $ignoredDetails
     * @return SourceDiscoveryResult Empty Git discovery result.
     */
    private function emptyGitDiscoveryResult(array $missingPaths, array $ignoredDetails): SourceDiscoveryResult
    {
        sort($missingPaths, SORT_STRING);
        $ignoredDetails = $this->finalizeIgnored($ignoredDetails);

        return new SourceDiscoveryResult([], $missingPaths, $this->pathsFromDetails($ignoredDetails), $ignoredDetails);
    }

    /**
     * @param list<array{absolutePath: string, pathspec: string, isFile: bool}> $requestedExistingPaths
     * @param list<string>                                                      $visiblePaths
     * @return list<IgnoredPath> Existing requested paths skipped by Git or generated-file protection.
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
     * @param list<string> $visiblePaths
     * @param list<string> $configuredIgnorePatterns
     * @return array{files: array<string, SourceFile>, ignoredDetails: list<IgnoredPath>}
     */
    private function sourceFilesFromGitVisiblePaths(array $visiblePaths, array $configuredIgnorePatterns): array
    {
        $files          = [];
        $ignoredDetails = [];

        foreach ($visiblePaths as $displayPath) {
            $this->appendGitVisibleSourceFile($displayPath, $configuredIgnorePatterns, $files, $ignoredDetails);
        }

        return [
            'files' => $files,
            'ignoredDetails' => $ignoredDetails,
        ];
    }

    /**
     * Append git visible source file details to report output.
     *
     * @param list<string>              $configuredIgnorePatterns
     * @param array<string, SourceFile> $files
     * @param list<IgnoredPath>         $ignoredDetails
     * @return void
     */
    private function appendGitVisibleSourceFile(
        string $displayPath,
        array $configuredIgnorePatterns,
        array &$files,
        array &$ignoredDetails,
    ): void {
        $absolutePath = $this->projectRoot . '/' . $displayPath;

        if (!is_file($absolutePath)) {
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
            return;
        }

        $defaultDirectory = $this->ignoreResolver->matchedDefaultDirectory($relativeDisplayPath);
        if ($defaultDirectory !== null) {
            $ignoredDetails[] = new IgnoredPath($relativeDisplayPath, PathIgnoreResolver::SOURCE_DEFAULT, $defaultDirectory);
            return;
        }

        $generatedFilename = $this->ignoreResolver->matchedGeneratedFilename($absolutePath);
        if ($generatedFilename !== null) {
            $ignoredDetails[] = new IgnoredPath($relativeDisplayPath, PathIgnoreResolver::SOURCE_GENERATED, $generatedFilename);
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
     * @return bool True when the project root can use Git worktree commands.
     */
    private function isGitWorkTree(): bool
    {
        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $this->projectRoot);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === 'true';
    }

    /**
     * @param list<string> $pathspecs
     * @return list<string>|null Git-visible display paths, or null when Git listing fails.
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
            return null;
        }

        $paths = array_values(array_filter(
            explode("\0", $process->getOutput()),
            static fn (string $path): bool => $path !== '',
        ));
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Convert an existing project path into a Git pathspec relative to the project root.
     *
     * @return string|null Git pathspec, or null when the path sits outside the project root.
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
     * @param list<string> $visiblePaths
     * @return bool True when a requested pathspec produced at least one visible file.
     */
    private function hasVisibleFileForPathspec(string $pathspec, array $visiblePaths, bool $isFile): bool
    {
        $normalizedPathspec = trim($pathspec, '/');

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
     * Return a compact ignored path for configured glob patterns.
     *
     * @param list<string> $patterns
     * @return string Ignored display path.
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
                return $base;
            }
        }

        return $displayPath;
    }

    /**
     * Reduce ignored details to one entry per path, sorted for stable reporting.
     *
     * @param list<IgnoredPath> $ignoredDetails
     * @return list<IgnoredPath> Deduplicated, path-sorted ignored details.
     */
    private function finalizeIgnored(array $ignoredDetails): array
    {
        $byPath = [];
        foreach ($ignoredDetails as $ignoredPath) {
            $byPath[$ignoredPath->path] ??= $ignoredPath;
        }

        $deduped = array_values($byPath);
        usort($deduped, static fn (IgnoredPath $left, IgnoredPath $right): int => strcmp($left->path, $right->path));

        return $deduped;
    }

    /**
     * Project the ignored-path display strings from the enriched details.
     *
     * @param list<IgnoredPath> $ignoredDetails
     * @return list<string> Ignored display paths in detail order.
     */
    private function pathsFromDetails(array $ignoredDetails): array
    {
        return array_map(static fn (IgnoredPath $ignoredPath): string => $ignoredPath->path, $ignoredDetails);
    }
}
