<?php

declare(strict_types=1);

namespace GruffPhp\Source;

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

    /** @var list<string> */
    private const IGNORED_DIRECTORIES = [
        '.fleet',
        '.git',
        '.goat-flow/logs',
        '.goat-flow/scratchpad',
        '.goat-flow/tasks',
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
        'var/cache',
        'vendor',
    ];

    /** @var list<string> */
    private const IGNORED_FILENAMES = [
        'bun.lockb',
        'composer.lock',
        'npm-shrinkwrap.json',
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
    ];

    /**
     * Build the source-discovery scanner for the given project root.
     *
     * @param string $projectRoot Project root used to resolve requested paths.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * @param list<string> $paths                    Requested paths to discover.
     * @param bool         $includeIgnored           Whether built-in ignored paths should still be included.
     * @param list<string> $configuredIgnorePatterns Additional ignore patterns from config.
     * @return SourceDiscoveryResult Files, missing inputs, and ignored paths.
     */
    public function discover(array $paths, bool $includeIgnored = false, array $configuredIgnorePatterns = []): SourceDiscoveryResult
    {
        $requestedPaths = $paths === [] ? ['.'] : $paths;

        if (!$includeIgnored) {
            $gitResult = $this->discoverGitVisible($requestedPaths, $configuredIgnorePatterns);
            if ($gitResult instanceof SourceDiscoveryResult) {
                return $gitResult;
            }
        }

        $files          = [];
        $missingPaths   = [];
        $ignoredPaths   = [];

        foreach ($requestedPaths as $path) {
            $absolutePath = $this->absolutePath($path);

            if (!file_exists($absolutePath)) {
                $missingPaths[] = $path;
                continue;
            }

            if ($this->isConfiguredIgnoredPath($absolutePath, $configuredIgnorePatterns)) {
                $ignoredPaths[] = $this->displayPath($absolutePath);
                continue;
            }

            if (!$includeIgnored && $this->isDefaultIgnoredPath($absolutePath)) {
                $ignoredPaths[] = $this->displayPath($absolutePath);
                continue;
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

                continue;
            }

            if (is_dir($absolutePath)) {
                foreach ($this->walkDirectory($absolutePath, $includeIgnored, $configuredIgnorePatterns, $ignoredPaths) as $file) {
                    $canonicalPath = $this->canonicalPath($file->getPathname());
                    $type          = $this->sourceType($canonicalPath);

                    if ($type !== null) {
                        $files[$canonicalPath] = new SourceFile($canonicalPath, $this->displayPath($canonicalPath), $type);
                    }
                }
            }
        }

        ksort($files, SORT_STRING);
        sort($missingPaths, SORT_STRING);
        sort($ignoredPaths, SORT_STRING);

        return new SourceDiscoveryResult(array_values($files), $missingPaths, array_values(array_unique($ignoredPaths)));
    }

    /**
     * @param list<string> $ignoredPaths
     * @param list<string> $configuredIgnorePatterns
     * @return iterable<SplFileInfo>
     */
    private function walkDirectory(
        string $directory,
        bool $includeIgnored,
        array $configuredIgnorePatterns,
        array &$ignoredPaths,
    ): iterable {
        $inner  = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $inner,
            function (SplFileInfo $file, mixed $key, RecursiveIterator $iterator) use ($includeIgnored, $configuredIgnorePatterns, &$ignoredPaths): bool {
                $path  = $file->getPathname();
                $isDir = $file->isDir();

                if ($this->isConfiguredIgnoredPath($path, $configuredIgnorePatterns)) {
                    $ignoredPaths[] = $this->displayPath($path);
                    return false;
                }

                if (!$includeIgnored && $this->isDefaultIgnoredPath($path)) {
                    if ($isDir) {
                        $ignoredPaths[] = $this->displayPath($path);
                    }
                    return false;
                }

                return true;
            },
        );

        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $file) {
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
        if ($path === '') {
            return $this->projectRoot;
        }

        if ($path[0] === '/') {
            return $path;
        }

        return $this->projectRoot . '/' . $path;
    }

    /**
     * Canonicalise a path via realpath(), falling back to the original string when the file does not exist.
     *
     * @return string
     */
    private function canonicalPath(string $path): string
    {
        $realPath = realpath($path);

        return $realPath === false ? $path : $realPath;
    }

    /**
     * Format a path for user-facing output relative to the project root; the root itself renders as ".".
     *
     * @return string
     */
    private function displayPath(string $path): string
    {
        $canonicalPath = $this->canonicalPath($path);
        $root          = rtrim($this->canonicalPath($this->projectRoot), '/');

        if ($canonicalPath === $root) {
            return '.';
        }

        if (str_starts_with($canonicalPath, $root . '/')) {
            return substr($canonicalPath, strlen($root) + 1);
        }

        return $canonicalPath;
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
     * Detect whether the path matches a built-in ignored directory or filename (vendor, node_modules, lock files, etc.).
     *
     * @return bool
     */
    private function isDefaultIgnoredPath(string $path): bool
    {
        $displayPath = str_replace('\\', '/', $this->displayPath($path));
        $segments    = explode('/', trim($displayPath, '/'));

        foreach (self::IGNORED_DIRECTORIES as $ignoredDirectory) {
            $ignoredSegments = explode('/', $ignoredDirectory);
            $ignoredCount    = count($ignoredSegments);

            for ($i = 0, $max = count($segments) - $ignoredCount; $i <= $max; $i++) {
                if (array_slice($segments, $i, $ignoredCount) === $ignoredSegments) {
                    return true;
                }
            }
        }

        if (in_array(basename($path), self::IGNORED_FILENAMES, true)) {
            return true;
        }

        return false;
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
            return $this->emptyGitDiscoveryResult($request['missingPaths'], $request['ignoredPaths']);
        }

        $visiblePaths = $this->gitVisiblePathspecs($request['pathspecs']);
        if ($visiblePaths === null) {
            return null;
        }

        $ignoredPaths = array_merge(
            $request['ignoredPaths'],
            $this->ignoredRequestedGitPaths($request['requestedExistingPaths'], $visiblePaths),
        );
        $sourceResult  = $this->sourceFilesFromGitVisiblePaths($visiblePaths, $configuredIgnorePatterns);
        $files         = $sourceResult['files'];
        $ignoredPaths  = array_merge($ignoredPaths, $sourceResult['ignoredPaths']);
        $missingPaths  = $request['missingPaths'];

        ksort($files, SORT_STRING);
        sort($missingPaths, SORT_STRING);
        sort($ignoredPaths, SORT_STRING);

        return new SourceDiscoveryResult(array_values($files), $missingPaths, array_values(array_unique($ignoredPaths)));
    }

    /**
     * @param list<string> $requestedPaths
     * @param list<string> $configuredIgnorePatterns
     * @return array{missingPaths: list<string>, ignoredPaths: list<string>, pathspecs: list<string>, requestedExistingPaths: list<array{absolutePath: string, pathspec: string, isFile: bool}>}|null
     */
    private function buildGitDiscoveryRequest(array $requestedPaths, array $configuredIgnorePatterns): ?array
    {
        $missingPaths = [];
        $ignoredPaths = [];
        $pathspecs    = [];
        /** @var list<array{absolutePath: string, pathspec: string, isFile: bool}> $requestedExistingPaths Existing request metadata checked after Git visibility is known. */
        $requestedExistingPaths = [];

        foreach ($requestedPaths as $path) {
            $absolutePath = $this->absolutePath($path);

            if (!file_exists($absolutePath)) {
                $missingPaths[] = $path;
                continue;
            }

            if ($this->isConfiguredIgnoredPath($absolutePath, $configuredIgnorePatterns)) {
                $ignoredPaths[] = $this->displayPath($absolutePath);
                continue;
            }

            $pathspec = $this->gitPathspec($absolutePath);
            if ($pathspec === null) {
                return null;
            }

            $pathspecs[]               = $pathspec;
            $requestedExistingPaths[] = [
                'absolutePath' => $absolutePath,
                'pathspec' => $pathspec,
                'isFile' => is_file($absolutePath),
            ];
        }

        return [
            'missingPaths' => $missingPaths,
            'ignoredPaths' => $ignoredPaths,
            'pathspecs' => $pathspecs,
            'requestedExistingPaths' => $requestedExistingPaths,
        ];
    }

    /**
     * @param list<string> $missingPaths
     * @param list<string> $ignoredPaths
     * @return SourceDiscoveryResult Empty Git discovery result.
     */
    private function emptyGitDiscoveryResult(array $missingPaths, array $ignoredPaths): SourceDiscoveryResult
    {
        sort($missingPaths, SORT_STRING);
        sort($ignoredPaths, SORT_STRING);

        return new SourceDiscoveryResult([], $missingPaths, array_values(array_unique($ignoredPaths)));
    }

    /**
     * @param list<array{absolutePath: string, pathspec: string, isFile: bool}> $requestedExistingPaths
     * @param list<string>                                                        $visiblePaths
     * @return list<string> Existing requested paths skipped by Git or generated-file protection.
     */
    private function ignoredRequestedGitPaths(array $requestedExistingPaths, array $visiblePaths): array
    {
        $ignoredPaths = [];

        foreach ($requestedExistingPaths as $requestedPath) {
            if ($this->hasVisibleFileForPathspec($requestedPath['pathspec'], $visiblePaths, $requestedPath['isFile'])) {
                continue;
            }

            if (
                $this->isGitIgnoredPath($requestedPath['pathspec'])
                || in_array(basename($requestedPath['absolutePath']), self::IGNORED_FILENAMES, true)
            ) {
                $ignoredPaths[] = $this->displayPath($requestedPath['absolutePath']);
            }
        }

        return $ignoredPaths;
    }

    /**
     * @param list<string> $visiblePaths
     * @param list<string> $configuredIgnorePatterns
     * @return array{files: array<string, SourceFile>, ignoredPaths: list<string>}
     */
    private function sourceFilesFromGitVisiblePaths(array $visiblePaths, array $configuredIgnorePatterns): array
    {
        $files        = [];
        $ignoredPaths = [];

        foreach ($visiblePaths as $displayPath) {
            $this->appendGitVisibleSourceFile($displayPath, $configuredIgnorePatterns, $files, $ignoredPaths);
        }

        return [
            'files' => $files,
            'ignoredPaths' => $ignoredPaths,
        ];
    }

    /**
     * @param list<string>              $configuredIgnorePatterns
     * @param array<string, SourceFile> $files
     * @param list<string>              $ignoredPaths
     * @return void No return value.
     */
    private function appendGitVisibleSourceFile(
        string $displayPath,
        array $configuredIgnorePatterns,
        array &$files,
        array &$ignoredPaths,
    ): void {
        $absolutePath = $this->projectRoot . '/' . $displayPath;

        if (!is_file($absolutePath)) {
            return;
        }

        if ($this->isConfiguredIgnoredPath($absolutePath, $configuredIgnorePatterns)) {
            $ignoredPaths[] = $this->configuredIgnoredDisplayPath($absolutePath, $configuredIgnorePatterns);
            return;
        }

        if (in_array(basename($absolutePath), self::IGNORED_FILENAMES, true)) {
            $ignoredPaths[] = $this->displayPath($absolutePath);
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
     * @return bool True when Git excludes the pathspec by ignore rules.
     */
    private function isGitIgnoredPath(string $pathspec): bool
    {
        $process = new Process(['git', 'check-ignore', '--quiet', '--', $pathspec], $this->projectRoot);
        $process->run();

        return $process->getExitCode() === 0;
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
     * @param list<string> $patterns
     * @return bool True when the path matches a configured ignore pattern.
     */
    private function isConfiguredIgnoredPath(string $path, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }

        $displayPath = str_replace('\\', '/', $this->displayPath($path));

        foreach ($patterns as $pattern) {
            if ($this->matchesPathPattern($displayPath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect whether the display path matches a glob-style pattern (`*`, `**`, `?` supported).
     *
     * @return bool
     */
    private function matchesPathPattern(string $displayPath, string $pattern): bool
    {
        $normalizedPattern = trim(str_replace('\\', '/', $pattern), '/');
        $normalizedPath    = trim($displayPath, '/');

        if ($normalizedPattern === $normalizedPath || str_starts_with($normalizedPath, $normalizedPattern . '/')) {
            return true;
        }

        $regex = '#^' . strtr(preg_quote($normalizedPattern, '#'), [
            '\\*\\*' => '.*',
            '\\*' => '[^/]*',
            '\\?' => '[^/]',
        ]) . '$#';

        return preg_match($regex, $normalizedPath) === 1;
    }
}
