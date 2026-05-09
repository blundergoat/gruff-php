<?php

declare(strict_types=1);

namespace GruffPhp\Source;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class SourceDiscovery
{
    private const PHP_EXTENSION = 'php';

    /** @var list<string> */
    private const IGNORED_DIRECTORIES = [
        '.git',
        '.hg',
        '.svn',
        '.phpunit.cache',
        'build',
        'cache',
        'coverage',
        'dist',
        'generated',
        'node_modules',
        'var/cache',
        'vendor',
    ];

    public function __construct(private string $projectRoot)
    {
    }

    /**
     * @param list<string> $paths
     */
    public function discover(array $paths, bool $includeIgnored = false): SourceDiscoveryResult
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

            if (!$includeIgnored && $this->isIgnoredPath($absolutePath)) {
                $ignoredPaths[] = $this->displayPath($absolutePath);
                continue;
            }

            if (is_file($absolutePath)) {
                if ($this->isPhpFile($absolutePath)) {
                    $files[$this->canonicalPath($absolutePath)] = new SourceFile(
                        $this->canonicalPath($absolutePath),
                        $this->displayPath($absolutePath),
                    );
                }

                continue;
            }

            if (is_dir($absolutePath)) {
                foreach ($this->walkDirectory($absolutePath, $includeIgnored, $ignoredPaths) as $file) {
                    $canonicalPath = $this->canonicalPath($file->getPathname());
                    $files[$canonicalPath] = new SourceFile($canonicalPath, $this->displayPath($canonicalPath));
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
     * @return iterable<SplFileInfo>
     */
    private function walkDirectory(string $directory, bool $includeIgnored, array &$ignoredPaths): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $path = $file->getPathname();

            if (!$includeIgnored && $this->isIgnoredPath($path)) {
                if ($file->isDir()) {
                    $ignoredPaths[] = $this->displayPath($path);
                }

                continue;
            }

            if ($file->isFile() && $this->isPhpFile($path)) {
                yield $file;
            }
        }
    }

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

    private function canonicalPath(string $path): string
    {
        $realPath = realpath($path);

        return $realPath === false ? $path : $realPath;
    }

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

    private function isPhpFile(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === self::PHP_EXTENSION;
    }

    private function isIgnoredPath(string $path): bool
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

        return false;
    }
}
