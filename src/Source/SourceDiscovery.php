<?php

declare(strict_types=1);

namespace GruffPhp\Source;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
        'neon',
        'xml',
        'yaml',
        'yml',
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
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * @param list<string> $paths
     * @param list<string> $configuredIgnorePatterns
     * @return SourceDiscoveryResult Files, missing inputs, and ignored paths.
     */
    public function discover(array $paths, bool $includeIgnored = false, array $configuredIgnorePatterns = []): SourceDiscoveryResult
    {
        $requestedPaths = $paths === [] ? ['.'] : $paths;
        $files = [];
        $missingPaths = [];
        $ignoredPaths = [];

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
                    $type = $this->sourceType($canonicalPath);

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
    ): iterable
    {
        $inner = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $inner,
            function (SplFileInfo $file, mixed $key, RecursiveIterator $iterator) use ($includeIgnored, $configuredIgnorePatterns, &$ignoredPaths): bool {
                $path = $file->getPathname();
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
        $root = rtrim($this->canonicalPath($this->projectRoot), '/');

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

        if (in_array($extension, self::TEXT_EXTENSIONS, true) || $this->isEnvLikeFile($path)) {
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
        $segments = explode('/', trim($displayPath, '/'));

        foreach (self::IGNORED_DIRECTORIES as $ignoredDirectory) {
            $ignoredSegments = explode('/', $ignoredDirectory);
            $ignoredCount = count($ignoredSegments);

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
        $normalizedPath = trim($displayPath, '/');

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
