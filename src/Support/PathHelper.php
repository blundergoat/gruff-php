<?php

declare(strict_types=1);

namespace GruffPhp\Support;

/**
 * Provides path normalization, root resolution, and relative-path comparison helpers.
 * Separator normalization and canonicalization are separate operations with different effects.
 * canonical() uses realpath() when available and falls back to textual dot-segment reduction.
 */
final class PathHelper
{
    /**
     * Trims surrounding whitespace and replaces backslashes with forward slashes for path comparisons.
     *
     * @param string $path - Path supplied by a user, Git, or a report payload.
     *
     * @return string - Trimmed path using forward slashes; empty for an empty or whitespace-only input.
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
     * Reports whether Gruff treats a path as rooted or still needs to resolve it against a project root.
     *
     * @param string $path - Path to classify.
     *
     * @return bool - True for Gruff's Unix, UNC, or Windows root forms; false for relative or empty paths.
     */
    public static function isAbsolute(string $path): bool
    {
        $path = self::normalizeSeparators($path);

        if ($path === '') {

            return false;
        }

        // Match Windows drive-letter roots such as C:/repo without accepting C:relative.
        $hasDriveLetterRoot = preg_match('/^[A-Za-z]:($|\/)/', $path) === 1;


        return str_starts_with($path, '/')
            || $hasDriveLetterRoot;
    }

    /**
     * Normalizes both paths, returning normalized rooted input and joining relative input to the trimmed root.
     *
     * @param string $root - Root used for relative paths.
     * @param string $path - Path to resolve.
     *
     * @return string - Normalized rooted input, or the trimmed root joined to relative input; empty input returns the trimmed root.
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
     * Uses realpath() when it succeeds, otherwise normalizes separators and collapses dot segments textually.
     *
     * @param string $path - Path to canonicalize.
     *
     * @return string - Normalized realpath() result on success; normalized, dot-collapsed input on failure.
     */
    public static function canonical(string $path): string
    {
        $realPath = realpath($path);

        // The file is really on disk, so trust the OS-resolved path - symlinks and dot segments are already gone.
        if (is_string($realPath)) {

            return self::normalizeSeparators($realPath);
        }

        // The path does not exist (or realpath() failed), so collapse dot segments textually instead of via disk.
        return self::collapseDotSegments(self::normalizeSeparators($path));
    }

    /**
     * Compares canonical paths and returns a relative form only for the root itself or its descendants.
     *
     * @param string $path - Path to relativize.
     * @param string $root - Root directory to compare against.
     *
     * @return string|null - "." for the root, a relative descendant path, or null when outside the root.
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
     * Normalizes separators and whitespace, then removes leading "./" segments and trailing slashes for comparison.
     *
     * @param string $path - Path to normalize for matching.
     *
     * @return string - Normalized path with leading "./" segments and any trailing slash removed.
     */
    public static function normalizeRelative(string $path): string
    {
        $path = self::normalizeSeparators($path);


        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }


        return rtrim($path, '/');
    }

    /**
     * Collapses "." and resolvable ".." segments without consulting the filesystem.
     *
     * @param string $path - Normalized path using forward-slash separators.
     *
     * @return string - Collapsed path, possibly empty; unmatched ".." is retained for relative input.
     */
    private static function collapseDotSegments(string $path): string
    {

        if ($path === '') {

            return '';
        }

        $rootParts = self::splitRoot($path);
        $prefix    = $rootParts['prefix'];
        $remaining = $rootParts['remaining'];
        $collapsed = implode('/', self::collapsedSegments($remaining, $prefix !== ''));


        if ($prefix === '') {

            return $collapsed;
        }


        return $prefix . $collapsed;
    }

    /**
     * Separates Gruff's recognized root prefix from the path segments that follow it.
     * Call after normalizeSeparators(); these patterns classify only forward-slash forms.
     *
     * @param string $path - Forward-slash-normalized path whose root, if any, should be peeled off.
     *
     * @return array{prefix: string, remaining: string} - The root prefix (empty for a relative path) and the remaining path after it.
     */
    private static function splitRoot(string $path): array
    {
        // Gruff recognizes a drive letter followed by a slash, such as "C:/", as a root prefix.
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {

            return self::rootParts(substr($path, 0, 3), substr($path, 3));
        }

        // Gruff also preserves a bare drive letter as a root prefix with no remaining segments.
        if (preg_match('/^[A-Za-z]:$/', $path) === 1) {

            return self::rootParts($path, '');
        }

        // Gruff preserves two leading slashes as a distinct root prefix.
        if (str_starts_with($path, '//')) {
            return self::rootParts('//', substr($path, 2));
        }


        if (str_starts_with($path, '/')) {

            return self::rootParts('/', substr($path, 1));
        }


        return self::rootParts('', $path);
    }

    /**
     * Returns the root prefix and remaining path as named fields for dot-segment reduction.
     *
     * @param string $prefix - Root segment to preserve verbatim (e.g. "/", "//", "C:/"), empty for relative paths.
     * @param string $remaining - Path after the root, still slash-separated, for the caller to collapse into segments.
     *
     * @return array{prefix: string, remaining: string} - Prefix to preserve and remainder to collapse.
     */
    private static function rootParts(string $prefix, string $remaining): array
    {
        $parts              = [];
        $parts['prefix']    = $prefix;
        $parts['remaining'] = $remaining;

        return $parts;
    }

    /**
     * Reduces path segments, preserving unmatched ".." for relative input and discarding them at absolute roots.
     *
     * @param string $path - Rootless path remainder (root already peeled by splitRoot) to reduce to clean segments.
     * @param bool $isAbsolute - Whether unmatched ".." is discarded at the root instead of preserved.
     *
     * @return list<string> - Remaining segments in order; empty when all segments are skipped or cancelled.
     */
    private static function collapsedSegments(string $path, bool $isAbsolute): array
    {
        $segments = [];


        foreach (explode('/', $path) as $segment) {

            if ($segment === '' || $segment === '.') {
                continue;
            }


            if ($segment === '..') {

                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                    continue;
                }

                // A relative path with nothing above it keeps the ".." so it can still climb past its base.
                if (!$isAbsolute) {
                    $segments[] = $segment;
                }

                continue;
            }

            $segments[] = $segment;
        }

        return $segments;
    }
}
