<?php

declare(strict_types=1);

namespace GruffPhp\Support;

/**
 * Folds the many ways a filesystem path can be spelled into one canonical form, so the same file
 * is never mistaken for two different ones.
 *
 * A single file can reach gruff written several ways at once: the user types paths on the command
 * line (files to analyse, a project root, `--diff` targets), Git reports them in its own spelling,
 * and reports echo them back - any of which may use Windows backslashes, a `./` prefix, `..`
 * segments, a drive letter, or a UNC root. Every method here reduces those spellings to one
 * forward-slash form, so findings line up against the right file and the paths shown in reports and
 * the dashboard read cleanly. Reach for it wherever a path crosses between the CLI, Git, and output.
 */
final class PathHelper
{
    /**
     * Folds a path onto forward slashes so a Windows-style path a user typed lines up with the same
     * file written Unix-style elsewhere in the run.
     *
     * @param string $path - Path supplied by a user, Git, or a report payload.
     *
     * @return string - Path using forward-slash separators; empty string when the input was blank, which every caller reads as "no path".
     */
    public static function normalizeSeparators(string $path): string
    {
        $path = trim($path);
        // A blank path has nothing to fold, and downstream code already treats "" as "no path", so hand it straight back.
        if ($path === '') {
            // An empty string is already canonical; downstream callers treat "" as "no path".
            return '';
        }

        // Backslash is Windows' separator; forward slash is the one form every other comparison here assumes.
        return str_replace('\\', '/', $path);
    }

    /**
     * Reports whether a path already names a fixed filesystem location or still needs resolving
     * against a root - checked before gruff joins a user's relative path onto the project directory.
     *
     * @param string $path - Path to classify.
     *
     * @return bool - True when the path is absolute on Unix, UNC, or Windows; false for a relative path or an empty one, which names no location at all.
     */
    public static function isAbsolute(string $path): bool
    {
        $path = self::normalizeSeparators($path);
        // An empty path points at no location, so it cannot be absolute.
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
     * Anchors a possibly-relative path the user gave onto a root directory, leaving an already-absolute
     * path untouched - how a bare `src/Foo.php` becomes a full path gruff can open.
     *
     * @param string $root - Root used for relative paths.
     * @param string $path - Path to resolve.
     *
     * @return string - Absolute path when $path is relative; the original absolute path otherwise, and the root itself when $path is empty.
     */
    public static function resolveAgainst(string $root, string $path): string
    {
        $root = rtrim(self::normalizeSeparators($root), '/');
        $path = self::normalizeSeparators($path);

        // Nothing was supplied to join on, so the root directory itself is the answer.
        if ($path === '') {
            // An empty target resolves to the root itself; there is nothing to append.
            return $root;
        }

        // The user already handed over a full path, so honour it and ignore the root.
        if (self::isAbsolute($path)) {
            // An already-absolute path ignores the root, matching POSIX path-join semantics.
            return $path;
        }

        return $root . '/' . $path;
    }

    /**
     * Resolves a path to its real on-disk form when the file exists, and tidies it textually when it
     * doesn't, so two spellings of one file collapse to a single path the run can trust.
     *
     * @param string $path - Path to canonicalize.
     *
     * @return string - The OS-resolved real path when the file exists; the normalized, dot-collapsed input otherwise.
     */
    public static function canonical(string $path): string
    {
        $realPath = realpath($path);

        // The file is really on disk, so trust the OS-resolved path - symlinks and dot segments are already gone.
        if (is_string($realPath)) {
            // The path exists on disk, so prefer the OS-resolved real path (symlinks and dot segments already gone).
            return self::normalizeSeparators($realPath);
        }

        // The path does not exist (or realpath() failed), so collapse dot segments textually instead of via disk.
        return self::collapseDotSegments(self::normalizeSeparators($path));
    }

    /**
     * Rewrites an absolute path as one relative to the project root, so reports and the dashboard show
     * a short `src/Foo.php` instead of a long machine-specific path.
     *
     * @param string $path - Path to relativize.
     * @param string $root - Root directory to compare against.
     *
     * @return string|null - "." when the path is the root, a path relative to root when inside it, or null when the path lives outside the project and so has no project-relative form to show.
     */
    public static function relativeToRoot(string $path, string $root): ?string
    {
        $path = rtrim(self::canonical($path), '/');
        $root = rtrim(self::canonical($root), '/');

        // The path is the project root itself, conventionally shown as ".".
        if ($path === $root) {
            // The path is the root itself; "." is the conventional relative spelling of "here".
            return '.';
        }

        // The path sits inside the project, so strip the root prefix to get the short form users see.
        if (str_starts_with($path, $root . '/')) {
            // The path sits inside root, so strip the root prefix and its trailing separator.
            return substr($path, strlen($root) + 1);
        }

        // The path escapes the root, so it has no representation relative to it; null signals "outside the project".
        return null;
    }

    /**
     * Strips a leading `./` and any trailing slash from a display path, so two spellings of the same
     * file compare equal when findings are matched against ignore rules.
     *
     * @param string $path - Path to normalize for matching.
     *
     * @return string - Normalized path with leading "./" segments and any trailing slash removed.
     */
    public static function normalizeRelative(string $path): string
    {
        $path = self::normalizeSeparators($path);

        // Peel every leading "./" a tool or the user may have prefixed, so "./src" and "src" match.
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        // Drop a trailing separator so two spellings of the same directory compare equal during matching.
        return rtrim($path, '/');
    }

    /**
     * Resolves `.` and `..` in a path purely as text, for paths that don't exist on disk where
     * `realpath()` can offer no help.
     *
     * @param string $path - Normalized path using forward-slash separators.
     *
     * @return string - Path with dot segments removed; empty string when the input was empty, since there is nothing to collapse.
     */
    private static function collapseDotSegments(string $path): string
    {
        // An empty path has nothing to collapse, so it stays empty.
        if ($path === '') {
            // Nothing to collapse; an empty path stays empty.
            return '';
        }

        $rootParts = self::splitRoot($path);
        $prefix    = $rootParts['prefix'];
        $remaining = $rootParts['remaining'];
        $collapsed = implode('/', self::collapsedSegments($remaining, $prefix !== ''));

        // A relative path had no root to peel off, so the collapsed segments are the whole answer.
        if ($prefix === '') {
            // Relative input has no root to re-attach, so the collapsed segments are the whole answer.
            return $collapsed;
        }

        // Re-attach the root prefix that was split off so an absolute path stays absolute.
        return $prefix . $collapsed;
    }

    /**
     * Peels any absolute root - Unix `/`, UNC `//`, or a Windows drive like `C:/` - off the front of a
     * path, so the segments after it can be collapsed without disturbing the root.
     *
     * Callers must pass a path that has already been through normalizeSeparators(); the patterns here
     * assume forward slashes and would miss Windows roots written with backslashes.
     *
     * @param string $path - Forward-slash-normalized path whose root, if any, should be peeled off.
     *
     * @return array{prefix: string, remaining: string} - The root prefix (empty for a relative path) and the remaining path after it.
     */
    private static function splitRoot(string $path): array
    {
        // A drive letter followed by a slash (like "C:/") is a rooted Windows path.
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            // Keep the three-character drive root (e.g. "C:/") as the prefix so it is never treated as a segment.
            return self::rootParts(substr($path, 0, 3), substr($path, 3));
        }

        // A bare drive letter ("C:") with nothing after it is all root and has no segments to collapse.
        if (preg_match('/^[A-Za-z]:$/', $path) === 1) {
            // A bare drive ("C:") is all root and has no segments to collapse.
            return self::rootParts($path, '');
        }

        // Two leading slashes mark a UNC/network share root.
        if (str_starts_with($path, '//')) {
            // A leading "//" is a UNC/network root; preserve both slashes so it stays absolute after collapsing.
            return self::rootParts('//', substr($path, 2));
        }

        // A single leading slash is an ordinary Unix root.
        if (str_starts_with($path, '/')) {
            // A single leading "/" is the Unix root; keep it so the collapsed result stays absolute.
            return self::rootParts('/', substr($path, 1));
        }

        // No recognised root, so the input is relative: empty prefix, whole path as segments.
        return self::rootParts('', $path);
    }

    /**
     * Bundles a split root and its remainder into the shape splitRoot() hands back.
     *
     * Building the array key-by-key (rather than `['prefix' => ..., 'remaining' => ...]`) keeps the
     * literal from resembling a `[Class, method]` callable, which static analysers otherwise flag here.
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
     * Walks a rootless path's segments and drops the `.`/`..` noise, handing back clean segments the
     * caller re-joins into a tidy path.
     *
     * @param string $path - Rootless path remainder (root already peeled by splitRoot) to reduce to clean segments.
     * @param bool   $isAbsolute - True when the path had a root: leading ".." is then dropped (cannot ascend past the
     *                           root); false keeps leading ".." so a relative path can still climb above its base.
     *
     * @return list<string> - Normalized path segments, in order; empty list when nothing but "." and empty pieces remained.
     */
    private static function collapsedSegments(string $path, bool $isAbsolute): array
    {
        $segments = [];

        // Walk each slash-separated piece, folding away the ones that don't name a real step down.
        foreach (explode('/', $path) as $segment) {
            // Empty pieces (from doubled slashes) and a bare "." both mean "stay here", so skip them.
            if ($segment === '' || $segment === '.') {
                continue;
            }

            // A ".." asks to climb one level up.
            if ($segment === '..') {
                // There is a real segment above to cancel out, so pop it rather than keeping the "..".
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
