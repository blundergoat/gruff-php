<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

use Symfony\Component\Process\Process;

/**
 * Shares path-exclusion rules between source discovery and the check-ignore command.
 * Configured globs, VCS internals, default directories, and minified files are classified here.
 * Git rule lookup is separate so callers can combine it with their discovery strategy.
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

    /**
     * Gruff's own result-cache directory; skipped by default regardless of ancestor `.gitignore` files
     * (overridable with `--include-ignored` or an explicit file operand) so repeated runs never scan their own cache artifacts.
     *
     * @var list<string>
     */
    private const TOOL_CACHE_DIRECTORIES = [
        '.gruff-cache',
    ];

    /** @var list<string> */
    private const FALLBACK_DIRECTORIES = [
        '.fleet',
        '.idea',
        '.phpunit.cache',
        '.vscode',
        'build',
        'coverage',
        'node_modules',
        'var/cache',
    ];

    /**
     * Directories treated as dependency copies or build output under FAMILY-CONTRACT.md section 7.
     * They match at any depth and ignore ASCII case, even when a governing `.gitignore` exists.
     * `build` and `coverage` remain fallback-only directories.
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
     * Applies configured ignores and VCS exclusions before considering caller overrides and default exclusions.
     * Explicit files and include-ignored bypass third-party, build-output, minified-file, and fallback-directory exclusions.
     * Git ignore rules are queried separately through `gitIgnoreRule()`; this method never queries Git.
     *
     * @param string       $displayPath - Project-relative display path used for glob and directory matching.
     * @param string       $absolutePath - Absolute path used for directory detection and ancestor `.gitignore` checks.
     * @param list<string> $configuredPatterns - Configured paths.ignore glob patterns.
     * @param bool         $shouldIncludeIgnored - When true, bypass non-config, non-VCS exclusions.
     * @param bool         $isExplicitFile - When true, the caller requested this existing file directly.
     * @param bool|null    $isDirectory - Directory status; null asks the filesystem, so supply it explicitly for nonexistent paths.
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
        $toolCacheDirectory = self::matchedDirectorySegment($displayPath, self::TOOL_CACHE_DIRECTORIES, $isDirectory);
        // Never scan Gruff's own result cache by default, even in Git repositories whose .gitignore omits .gruff-cache/.
        if ($toolCacheDirectory !== null) {
            return IgnoreDecision::ignored(self::SOURCE_DEFAULT, $toolCacheDirectory);
        }

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
        return self::matchedDirectorySegment($displayPath, self::VCS_DIRECTORIES);
    }

    /**
     * Finds the first matching single-segment directory token on a path.
     *
     * @param string       $displayPath - Project-relative display path being tested.
     * @param list<string> $directories - Exact directory segment names to look for.
     * @param bool         $isDirectory - True when the path itself is a directory (or unknown for VCS matching); false drops the trailing filename.
     *
     * @return string|null - The matched directory name, or null when absent from the path.
     */
    private static function matchedDirectorySegment(string $displayPath, array $directories, bool $isDirectory = true): ?string
    {
        $segments = explode('/', trim(str_replace('\\', '/', $displayPath), '/'));

        // A regular file named like a cache directory is not a directory itself, so inspect only parent directory segments.
        if (!$isDirectory) {
            array_pop($segments);
        }

        // Inspect each path component so nested caches or VCS folders match at any depth.
        foreach ($segments as $segment) {
            // The first matched folder name on the path, such as .gruff-cache or .git, is reported as the ignore pattern.
            if (in_array($segment, $directories, true)) {
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


        foreach ($patterns as $pattern) {

            if ($this->matchesPathPattern($normalizedDisplayPath, $pattern)) {

                return $pattern;
            }
        }

        return null;
    }

    /**
     * Finds the first fallback-directory token present in a path, in fallback-list order.
     * A match alone does not exclude the path; decide() checks caller overrides and .gitignore presence.
     *
     * @param string $displayPath - Project-relative display path being tested.
     *
     * @return string|null - Matching fallback token, or null when none is present in the path.
     */
    public function matchedDefaultDirectory(string $displayPath): ?string
    {
        $normalizedDisplayPath = str_replace('\\', '/', $displayPath);
        $segments              = explode('/', trim($normalizedDisplayPath, '/'));


        foreach (self::FALLBACK_DIRECTORIES as $ignoredDirectory) {
            $ignoredSegments = explode('/', $ignoredDirectory);
            $ignoredCount    = count($ignoredSegments);

            for ($i = 0, $max = count($segments) - $ignoredCount; $i <= $max; $i++) {

                if (array_slice($segments, $i, $ignoredCount) === $ignoredSegments) {

                    return $ignoredDirectory;
                }
            }
        }

        return null;
    }

    /**
     * Finds the outermost third-party or build-output directory on a path, lowercased over ASCII.
     * A file's own name is not a directory: a/Vendor/X.php yields vendor, while a file named dist does not match.
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


        foreach ($segments as $segment) {
            $lowered = strtr($segment, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
            if (in_array($lowered, self::THIRD_PARTY_DIRECTORIES, true)) {
                return $lowered;
            }
        }

        return null;
    }

    /**
     * Reports whether a base name uses .min. before its final extension; jquery.min.js matches, but app.min.js.map and admin.mini.php do not.
     * Comparison lowercases ASCII A-Z only, so JQUERY.MIN.JS matches while non-ASCII look-alikes do not, consistently across ports.
     *
     * @param string $fileName - Base name of the candidate file.
     *
     * @return bool - True when the name marks a minified file.
     */
    public static function isMinifiedName(string $fileName): bool
    {
        // Match .min. plus one final dot-free extension after lowercasing ASCII A-Z.
        return preg_match('/\\.min\\.[^.]+$/D', strtr($fileName, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')) === 1;
    }

    /**
     * Checks whether fallback exclusions apply because no governing ancestor .gitignore is present.
     * Paths outside the resolved project root use fallback exclusions without inspecting their ancestors.
     *
     * @param string $absolutePath - Candidate path, resolved when possible before its parent chain is inspected.
     *
     * @return bool - True for paths outside the root, or when no governing ancestor .gitignore is found.
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
            return null;
        }

        $output = trim($process->getOutput());
        // Git confirmed the ignore but named no rule, so use the pathspec itself as the label.
        if ($output === '') {
            return $pathspec;
        }

        // `git check-ignore -v` prints "<source>:<line>:<pattern>\t<path>"; keep the pattern.
        $parts = explode("\t", $output, 2);
        $rule  = explode(':', $parts[0]);
        $patternText = trim((string) end($rule));

        return $patternText === '' ? $pathspec : $patternText;
    }

    /**
     * Matches a normalized path against configured globs supporting *, **, and ?.
     * Literal paths match descendants; appending /** to a literal directory path also matches the directory itself.
     *
     * @param string $displayPath - Path with separators already normalized to /; edge slashes are trimmed here.
     * @param string $pattern - Glob with * within a segment, ** across segments, and ? placeholders.
     *
     * @return bool - True when the normalized path matches the pattern.
     */
    private function matchesPathPattern(string $displayPath, string $pattern): bool
    {
        $normalizedPattern = trim(str_replace('\\', '/', $pattern), '/');
        $normalizedPath    = trim($displayPath, '/');

        // A trailing /** matches a directory and everything nested under it.
        if (str_ends_with($normalizedPattern, '/**')) {
            $directoryPrefix = substr($normalizedPattern, 0, -3);

            if ($directoryPrefix !== '' && ($normalizedPath === $directoryPrefix || str_starts_with($normalizedPath, $directoryPrefix . '/'))) {
                return true;
            }
        }


        if ($normalizedPattern === $normalizedPath || str_starts_with($normalizedPath, $normalizedPattern . '/')) {

            return true;
        }

        // Anchors require a whole-path match; single-star and question-mark globs stay within a segment, while double-star may cross segments.
        $regex = '#^' . strtr(preg_quote($normalizedPattern, '#'), [
            '\\*\\*' => '.*',
            '\\*' => '[^/]*',
            '\\?' => '[^/]',
        ]) . '$#';

        return preg_match($regex, $normalizedPath) === 1;
    }
}
