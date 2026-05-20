<?php

declare(strict_types=1);

namespace GruffPhp\Support;

/**
 * Centralises path handling that must work across Unix and Windows inputs.
 */
final class PathHelper
{
    /**
     * Normalize directory separators while preserving the path's absolute form.
     *
     * @param string $path Path supplied by a user, Git, or a report payload.
     * @return string Path using forward slash separators.
     */
    public static function normalizeSeparators(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        return str_replace('\\', '/', $path);
    }

    /**
     * Detect Unix, UNC, and Windows drive-letter absolute paths.
     *
     * @param string $path Path to classify.
     * @return bool True when the path is absolute on a supported platform.
     */
    public static function isAbsolute(string $path): bool
    {
        $path = self::normalizeSeparators($path);
        if ($path === '') {
            return false;
        }

        // Match Windows drive-letter roots such as C:/repo without accepting C:relative.
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:($|\/)/', $path) === 1;
    }

    /**
     * Resolve a possibly-relative path against a root directory.
     *
     * @param string $root Root used for relative paths.
     * @param string $path Path to resolve.
     * @return string Absolute path when $path is relative; original absolute path otherwise.
     */
    public static function resolveAgainst(string $root, string $path): string
    {
        $root = rtrim(self::normalizeSeparators($root), '/');
        $path = self::normalizeSeparators($path);

        if ($path === '') {
            return $root;
        }

        if (self::isAbsolute($path)) {
            return $path;
        }

        return $root . '/' . $path;
    }

    /**
     * Canonicalize a path when possible and normalize separators either way.
     *
     * @param string $path Path to canonicalize.
     * @return string Real path when it exists; normalized input otherwise.
     */
    public static function canonical(string $path): string
    {
        $realPath = realpath($path);

        return self::normalizeSeparators(is_string($realPath) ? $realPath : $path);
    }

    /**
     * Convert a path to project-relative form when it is inside the given root.
     *
     * @param string $path Path to relativize.
     * @param string $root Root directory to compare against.
     * @return string|null "." for the root, a relative path inside root, or null outside root.
     */
    public static function relativeToRoot(string $path, string $root): ?string
    {
        $path = rtrim(self::canonical($path), '/');
        $root = rtrim(self::canonical($root), '/');

        if ($path === $root) {
            return '.';
        }

        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return null;
    }

    /**
     * Normalize a display path by trimming a leading current-directory prefix.
     *
     * @param string $path Path to normalize for matching.
     * @return string Normalized path without leading "./" segments.
     */
    public static function normalizeRelative(string $path): string
    {
        $path = self::normalizeSeparators($path);

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return rtrim($path, '/');
    }
}
