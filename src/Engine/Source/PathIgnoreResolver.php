<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

use Symfony\Component\Process\Process;

/**
 * Single source of truth for whether a path is ignored and why.
 *
 * Both source discovery and the check-ignore command resolve exclusions through
 * this engine so analysis and pre-flight checks can never disagree.
 */
final readonly class PathIgnoreResolver
{
    /**
     * A path matched a configured paths.ignore glob.
     */
    public const SOURCE_CONFIG = 'config';

    /**
     * A path matched a built-in ignored directory such as vendor or node_modules.
     */
    public const SOURCE_DEFAULT = 'default';

    /**
     * A path matched generated-file handling outside the default directory policy.
     */
    public const SOURCE_GENERATED = 'generated';

    /**
     * A path is excluded by Git ignore rules.
     */
    public const SOURCE_GITIGNORE = 'gitignore';

    /** @var list<string> */
    private const VCS_DIRECTORIES = [
        '.git',
        '.hg',
        '.svn',
    ];

    /** @var list<string> */
    private const FALLBACK_DIRECTORIES = [
        '.fleet',
        '.gruff-cache',
        '.idea',
        '.phpunit.cache',
        '.vscode',
        'build',
        'coverage',
        'node_modules',
        'var/cache',
    ];

    /**
     * Directories holding third-party copies, and `dist`, the one build-output name section 7 adds; `build` and
     * `coverage` stay fallback-only. Like the fallback directories they match at any depth, but they are skipped even
     * under a governing .gitignore and compared over ASCII case, because a committed copy is still someone else's
     * code (FAMILY-CONTRACT.md section 7, amended by precision-floor M04).
     *
     * @var list<string>
     */
    private const THIRD_PARTY_DIRECTORIES = ['bower_components', 'dist', 'third-party', 'third_party', 'vendor', 'vendors'];

    /**
     * Pattern recorded for a minified file such as `jquery.min.js`, which section 7 skips as generated.
     */
    public const MINIFIED_FILE_PATTERN = '*.min.*';

    /**
     * Wraps the project root the resolver evaluates Git ignore rules against.
     *
     * @param string $projectRoot - Project root used to evaluate Git ignore rules.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Decides whether a path is ignored and why, applying config, VCS, and fallback exclusions in
     * priority order - the one place discovery and check-ignore agree on what gets skipped.
     *
     * Configured paths.ignore and VCS internals are authoritative. Explicit files and include-ignored bypass the
     * third-party, build-output and minified classes, which never defer to a .gitignore, and the Git/default fallback.
     *
     * @param string       $displayPath - Project-relative display path used for glob and directory matching.
     * @param string       $absolutePath - Absolute path used for filename matching.
     * @param list<string> $configuredPatterns - Configured paths.ignore glob patterns.
     * @param bool         $shouldIncludeIgnored - When true, non-VCS fallback ignores are bypassed for this run.
     * @param bool         $isExplicitFile - When true, the caller requested this existing file directly.
     * @param bool|null    $isDirectory - Whether the path is a directory; null asks the filesystem, which a caller
     *                                    classifying a path that is not on disk should not rely on.
     *
     * @return IgnoreDecision - Whether the path is ignored and, if so, the source and pattern behind it.
     */
    public function decide(
        string $displayPath,
        string $absolutePath,
        array $configuredPatterns,
        bool $shouldIncludeIgnored,
        bool $isExplicitFile = false,
        ?bool $isDirectory = null,
    ): IgnoreDecision {
        $configuredPattern = $this->matchedConfiguredPattern($displayPath, $configuredPatterns);
        // The user's own paths.ignore wins first and always, even when they asked to include ignored files.
        if ($configuredPattern !== null) {
            // Configured paths.ignore wins first and unconditionally, even when ignored files are requested.
            return IgnoreDecision::ignored(self::SOURCE_CONFIG, $configuredPattern);
        }

        $vcsDirectory = $this->matchedVcsDirectory($displayPath);
        if ($vcsDirectory !== null) {
            return IgnoreDecision::ignored(self::SOURCE_DEFAULT, $vcsDirectory);
        }

        if ($shouldIncludeIgnored || $isExplicitFile) {
            return IgnoreDecision::notIgnored();
        }

        $isDirectory ??= is_dir($absolutePath);
        $thirdPartyDirectory = self::matchedThirdPartyDirectory($displayPath, $isDirectory);
        // A committed copy is still someone else's code, so these classes skip whatever the .gitignore says.
        if ($thirdPartyDirectory !== null) {
            return IgnoreDecision::ignored(self::SOURCE_DEFAULT, $thirdPartyDirectory);
        }

        if (!$isDirectory && self::isMinifiedName(basename(str_replace('\\', '/', $displayPath)))) {
            return IgnoreDecision::ignored(self::SOURCE_DEFAULT, self::MINIFIED_FILE_PATTERN);
        }

        $defaultDirectory = $this->matchedDefaultDirectory($displayPath);
        if ($defaultDirectory !== null && $this->shouldApplyFallbackAt($absolutePath)) {
            return IgnoreDecision::ignored(self::SOURCE_DEFAULT, $defaultDirectory);
        }

        return IgnoreDecision::notIgnored();
    }

    /**
     * Finds an always-blocked VCS directory component in a path.
     *
     * @param string $displayPath - Project-relative display path being tested.
     *
     * @return string|null - The matching VCS token, or null when the path is outside VCS internals.
     */
    public function matchedVcsDirectory(string $displayPath): ?string
    {
        $segments = explode('/', trim(str_replace('\\', '/', $displayPath), '/'));

        foreach ($segments as $segment) {
            if (in_array($segment, self::VCS_DIRECTORIES, true)) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Finds which configured ignore glob matches a path, so a report can name the exact rule that
     * excluded it, or null when the user's config does not cover it.
     *
     * @param string       $displayPath - Project-relative display path being tested.
     * @param list<string> $patterns - Configured paths.ignore glob patterns.
     *
     * @return string|null - The matching pattern; null when the path is not configured-ignored.
     */
    public function matchedConfiguredPattern(string $displayPath, array $patterns): ?string
    {
        $normalizedDisplayPath = str_replace('\\', '/', $displayPath);

        // Test the path against each configured pattern, taking the first that matches.
        foreach ($patterns as $pattern) {
            // First matching glob wins - return it so callers can report which rule excluded the path.
            if ($this->matchesPathPattern($normalizedDisplayPath, $pattern)) {
                // First matching glob wins; return it verbatim so callers can report which rule excluded the path.
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Finds which built-in ignored directory (vendor, node_modules, ...) a path sits under, or null
     * when it is not inside one - how gruff skips dependency and tooling trees by default.
     *
     * @param string $displayPath - Project-relative display path being tested.
     *
     * @return string|null - The matching directory token; null when the path is not default-ignored.
     */
    public function matchedDefaultDirectory(string $displayPath): ?string
    {
        $normalizedDisplayPath = str_replace('\\', '/', $displayPath);
        $segments              = explode('/', trim($normalizedDisplayPath, '/'));

        // Test the path's segments against each built-in ignored directory.
        foreach (self::FALLBACK_DIRECTORIES as $ignoredDirectory) {
            $ignoredSegments = explode('/', $ignoredDirectory);
            $ignoredCount    = count($ignoredSegments);

            for ($i = 0, $max = count($segments) - $ignoredCount; $i <= $max; $i++) {
                // The ignored directory appears somewhere in the path, so the whole path is excluded.
                if (array_slice($segments, $i, $ignoredCount) === $ignoredSegments) {
                    // A matched directory token anywhere in the path excludes it; return the token that matched.
                    return $ignoredDirectory;
                }
            }
        }

        return null;
    }

    /**
     * Finds the outermost third-party or build-output directory on a path, lowercased over ASCII.
     * A file's own name is not a directory, so `a/Vendor/X.php` gives `vendor` while a file named `dist` matches nothing.
     *
     * @param string $displayPath - Project-relative display path being tested.
     * @param bool   $isDirectory - Whether the path itself is a directory, which makes its own name eligible.
     *
     * @return string|null - The matched directory name in lowercase; null when no directory on the path is in the class.
     */
    private static function matchedThirdPartyDirectory(string $displayPath, bool $isDirectory): ?string
    {
        $segments = explode('/', trim(str_replace('\\', '/', $displayPath), '/'));
        // A file's last segment is its own name, never a directory.
        if (!$isDirectory) {
            array_pop($segments);
        }

        // The outermost matching directory names the reason, as it does in every port.
        foreach ($segments as $segment) {
            $lowered = strtr($segment, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
            if (in_array($lowered, self::THIRD_PARTY_DIRECTORIES, true)) {
                return $lowered;
            }
        }

        return null;
    }

    /**
     * Reports a base name with `.min.` directly before its final extension, such as `jquery.min.js`;
     * `app.min.js.map` and `admin.mini.php` do not match. The name is compared after lowercasing A-Z only, so
     * `JQUERY.MIN.JS` matches while a non-ASCII look-alike does not, as in every port.
     *
     * @param string $fileName - Base name of the candidate file.
     *
     * @return bool - True when the name marks a minified file.
     */
    public static function isMinifiedName(string $fileName): bool
    {
        // `.min.` followed by one final extension that holds no further dot, checked after A-Z lowercasing.
        return preg_match('/\\.min\\.[^.]+$/D', strtr($fileName, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')) === 1;
    }

    /**
     * Reports whether no `.gitignore` exists from the project root through the candidate's parent.
     *
     * @param string $absolutePath - Existing candidate path whose ancestor chain owns fallback applicability.
     *
     * @return bool - True only when the family fallback should apply at this candidate.
     */
    private function shouldApplyFallbackAt(string $absolutePath): bool
    {
        $root      = rtrim(str_replace('\\', '/', realpath($this->projectRoot) ?: $this->projectRoot), '/');
        $candidate = str_replace('\\', '/', realpath($absolutePath) ?: $absolutePath);
        $parent    = rtrim(str_replace('\\', '/', dirname($candidate)), '/');

        if ($parent !== $root && !str_starts_with($parent, $root . '/')) {
            return true;
        }

        while (true) {
            if (is_file($parent . '/.gitignore')) {
                return false;
            }
            if ($parent === $root) {
                return true;
            }

            $next = rtrim(str_replace('\\', '/', dirname($parent)), '/');
            if ($next === $parent) {
                return true;
            }
            $parent = $next;
        }
    }

    /**
     * Asks Git whether it ignores a path and returns the rule that does, or null when Git does not -
     * how the check-ignore command explains a .gitignore exclusion to the user.
     *
     * @param string $pathspec - Project-relative pathspec to test against Git ignore rules.
     *
     * @return string|null - The matching git rule (or the pathspec when git reports no rule text); null when the path is not git-ignored.
     */
    public function gitIgnoreRule(string $pathspec): ?string
    {
        $process = new Process(['git', 'check-ignore', '--verbose', '--', $pathspec], $this->projectRoot);
        $process->run();

        // A non-zero exit is git's "not ignored" answer, not an error to surface.
        if ($process->getExitCode() !== 0) {
            // Non-zero exit is git's "not ignored" signal; report no rule rather than treating it as an error.
            return null;
        }

        $output = trim($process->getOutput());
        // Git confirmed the ignore but named no rule, so use the pathspec itself as the label.
        if ($output === '') {
            // Git confirmed the ignore but gave no rule text, so fall back to the pathspec as the rule label.
            return $pathspec;
        }

        // `git check-ignore -v` prints "<source>:<line>:<pattern>\t<path>"; keep the pattern.
        $parts = explode("\t", $output, 2);
        $rule  = explode(':', $parts[0]);
        $patternText = trim((string) end($rule));

        // Prefer the parsed pattern; fall back to the pathspec when parsing yielded an empty rule.
        return $patternText === '' ? $pathspec : $patternText;
    }

    /**
     * Tests whether a path matches a glob-style ignore pattern, supporting `*`, `**`, and `?`, so
     * configured ignores behave the way a user expects from .gitignore-style globs.
     *
     * @param string $displayPath - Project-relative path to test; backslashes and edge slashes are normalized here.
     * @param string $pattern - Glob with `*` (within a segment), `**` (across segments), and `?` placeholders.
     *
     * @return bool - True when the normalized path matches the pattern.
     */
    private function matchesPathPattern(string $displayPath, string $pattern): bool
    {
        $normalizedPattern = trim(str_replace('\\', '/', $pattern), '/');
        $normalizedPath    = trim($displayPath, '/');

        // A trailing `/**` matches a directory and everything nested under it.
        if (str_ends_with($normalizedPattern, '/**')) {
            $directoryPrefix = substr($normalizedPattern, 0, -3);
            // The path is that directory itself or sits inside it, so it matches.
            if ($directoryPrefix !== '' && ($normalizedPath === $directoryPrefix || str_starts_with($normalizedPath, $directoryPrefix . '/'))) {
                return true;
            }
        }

        // A plain literal or directory-prefix match needs no glob work, so short-circuit here.
        if ($normalizedPattern === $normalizedPath || str_starts_with($normalizedPath, $normalizedPattern . '/')) {
            // A literal or directory-prefix match needs no glob, so short-circuit before building the regex.
            return true;
        }

        // Otherwise translate the glob into a regex and test the path against it.
        $regex = '#^' . strtr(preg_quote($normalizedPattern, '#'), [
            '\\*\\*' => '.*',
            '\\*' => '[^/]*',
            '\\?' => '[^/]',
        ]) . '$#';

        return preg_match($regex, $normalizedPath) === 1;
    }
}
