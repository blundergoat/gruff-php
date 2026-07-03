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
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $path - Path supplied by a user, Git, or a report payload.
     *
     * @return string - Path using forward slash separators.
     */
    public static function normalizeSeparators(string $path): string
    {
        $path = trim($path);
        // User view: choose the analysis output branch for this case.
        // User view: an empty value becomes a clear analysis output fallback.
        if ($path === '') {
            // An empty string is already canonical; downstream callers treat "" as "no path".
            return '';
        }

        // Backslash is Windows' separator; forward slash is the one form every other comparison here assumes.
        return str_replace('\\', '/', $path);
    }

    /**
     * Detect Unix, UNC, and Windows drive-letter absolute paths.
     *
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $path - Path to classify.
     *
     * @return bool - True when the path is absolute on a supported platform.
     */
    public static function isAbsolute(string $path): bool
    {
        $path = self::normalizeSeparators($path);
        // User view: choose the analysis output branch for this case.
        // User view: an empty value becomes a clear analysis output fallback.
        if ($path === '') {
            // No characters means no root, so it cannot be anchored to a filesystem root.
            return false;
        }

        // Match Windows drive-letter roots such as C:/repo without accepting C:relative.
        $hasDriveLetterRoot = preg_match('/^[A-Za-z]:($|\/)/', $path) === 1;

        // A leading "/" anchors a Unix or UNC root; a drive letter anchors a Windows one. Either is absolute.
        return str_starts_with($path, '/')
            || $hasDriveLetterRoot;
    }

    /**
     * Resolve a possibly-relative path against a root directory.
     *
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $root - Root used for relative paths.
     * @param string $path - Path to resolve.
     *
     * @return string - Absolute path when $path is relative; original absolute path otherwise.
     */
    public static function resolveAgainst(string $root, string $path): string
    {
        $root = rtrim(self::normalizeSeparators($root), '/');
        $path = self::normalizeSeparators($path);

        // User view: choose the analysis output branch for this case.
        // User view: an empty value becomes a clear analysis output fallback.
        if ($path === '') {
            // An empty target resolves to the root itself; there is nothing to append.
            return $root;
        }

        // User view: choose the analysis output branch for this case.
        if (self::isAbsolute($path)) {
            // An already-absolute path ignores the root, matching POSIX path-join semantics.
            return $path;
        }

        return $root . '/' . $path;
    }

    /**
     * Canonicalize a path when possible and normalize separators either way.
     *
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $path - Path to canonicalize.
     *
     * @return string - Real path when it exists; normalized input otherwise.
     */
    public static function canonical(string $path): string
    {
        $realPath = realpath($path);

        // User view: choose the analysis output branch for this case.
        if (is_string($realPath)) {
            // The path exists on disk, so prefer the OS-resolved real path (symlinks and dot segments already gone).
            return self::normalizeSeparators($realPath);
        }

        // The path does not exist (or realpath() failed), so collapse dot segments textually instead of via disk.
        return self::collapseDotSegments(self::normalizeSeparators($path));
    }

    /**
     * Convert a path to project-relative form when it is inside the given root.
     *
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $path - Path to relativize.
     * @param string $root - Root directory to compare against.
     *
     * @return string|null - "." for the root, a relative path inside root, or null outside root.
     */
    public static function relativeToRoot(string $path, string $root): ?string
    {
        $path = rtrim(self::canonical($path), '/');
        $root = rtrim(self::canonical($root), '/');

        // User view: choose the analysis output branch for this case.
        if ($path === $root) {
            // The path is the root itself; "." is the conventional relative spelling of "here".
            return '.';
        }

        // User view: choose the analysis output branch for this case.
        if (str_starts_with($path, $root . '/')) {
            // The path sits inside root, so strip the root prefix and its trailing separator.
            return substr($path, strlen($root) + 1);
        }

        // The path escapes the root, so it has no representation relative to it; null signals "outside the project".
        return null;
    }

    /**
     * Normalize a display path by trimming a leading current-directory prefix.
     *
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $path - Path to normalize for matching.
     *
     * @return string - Normalized path without leading "./" segments.
     */
    public static function normalizeRelative(string $path): string
    {
        $path = self::normalizeSeparators($path);

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        // Drop a trailing separator so two spellings of the same directory compare equal during matching.
        return rtrim($path, '/');
    }

    /**
     * Collapse "." and ".." path segments without requiring the path to exist.
     *
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $path - Normalized path using forward slash separators.
     *
     * @return string - Path with dot segments removed.
     */
    private static function collapseDotSegments(string $path): string
    {
        // User view: choose the analysis output branch for this case.
        // User view: an empty value becomes a clear analysis output fallback.
        if ($path === '') {
            // Nothing to collapse; an empty path stays empty.
            return '';
        }

        $rootParts = self::splitRoot($path);
        $prefix    = $rootParts['prefix'];
        $remaining = $rootParts['remaining'];
        // User view: an empty value becomes a clear analysis output fallback.
        $collapsed = implode('/', self::collapsedSegments($remaining, $prefix !== ''));

        // User view: choose the analysis output branch for this case.
        // User view: an empty value becomes a clear analysis output fallback.
        if ($prefix === '') {
            // Relative input has no root to re-attach, so the collapsed segments are the whole answer.
            return $collapsed;
        }

        // Re-attach the root prefix that was split off so an absolute path stays absolute.
        return $prefix . $collapsed;
    }

    /**
     * Split an absolute root prefix from the rest of a normalized path.
     *
     * Callers must pass a path that has already been through normalizeSeparators(); the patterns here
     * assume forward slashes and would miss Windows roots written with backslashes.
     *
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $path - Forward-slash-normalized path whose root, if any, should be peeled off.
     *
     * @return array{prefix: string, remaining: string} - Root prefix and remaining path.
     */
    private static function splitRoot(string $path): array
    {
        // User view: choose the analysis output branch for this case.
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            // Keep the three-character drive root (e.g. "C:/") as the prefix so it is never treated as a segment.
            return self::rootParts(substr($path, 0, 3), substr($path, 3));
        }

        // User view: choose the analysis output branch for this case.
        if (preg_match('/^[A-Za-z]:$/', $path) === 1) {
            // A bare drive ("C:") is all root and has no segments to collapse.
            return self::rootParts($path, '');
        }

        // User view: choose the analysis output branch for this case.
        if (str_starts_with($path, '//')) {
            // A leading "//" is a UNC/network root; preserve both slashes so it stays absolute after collapsing.
            return self::rootParts('//', substr($path, 2));
        }

        // User view: choose the analysis output branch for this case.
        if (str_starts_with($path, '/')) {
            // A single leading "/" is the Unix root; keep it so the collapsed result stays absolute.
            return self::rootParts('/', substr($path, 1));
        }

        // No recognised root, so the input is relative: empty prefix, whole path as segments.
        return self::rootParts('', $path);
    }

    /**
     * Package a path root split without looking like callable-array syntax.
     *
     * Building the array key-by-key (rather than `['prefix' => ..., 'remaining' => ...]`) keeps the
     * literal from resembling a `[Class, method]` callable, which static analysers otherwise flag here.
     *
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $prefix - Root segment to preserve verbatim (e.g. "/", "//", "C:/"), empty for relative paths.
     * @param string $remaining - Path after the root, still slash-separated, for the caller to collapse into segments.
     *
     * @return array{prefix: string, remaining: string} - Root split parts.
     */
    private static function rootParts(string $prefix, string $remaining): array
    {
        $parts              = [];
        $parts['prefix']    = $prefix;
        $parts['remaining'] = $remaining;

        return $parts;
    }

    /**
     * Collapse relative path segments after the root has been separated.
     *
      * User flow: Moves analysis state toward the output users review.
      *
     * @param string $path - Rootless path remainder (root already peeled by splitRoot) to reduce to clean segments.
     * @param bool   $isAbsolute - True when the path had a root: leading ".." is then dropped (cannot ascend past the
     *                           root); false keeps leading ".." so a relative path can still climb above its base.
     *
     * @return list<string> - Normalized path segments.
     */
    private static function collapsedSegments(string $path, bool $isAbsolute): array
    {
        $segments = [];

        // User view: add each item that can appear in analysis output.
        foreach (explode('/', $path) as $segment) {
            // User view: choose the analysis output branch for this case.
            // User view: an empty value becomes a clear analysis output fallback.
            if ($segment === '' || $segment === '.') {
                continue;
            }

            // User view: choose the analysis output branch for this case.
            if ($segment === '..') {
                // User view: choose the analysis output branch for this case.
                // User view: an empty value becomes a clear analysis output fallback.
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                    continue;
                }

                // User view: choose the analysis output branch for this case.
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
