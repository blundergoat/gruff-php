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
     * A path matched a built-in generated/lock filename such as composer.lock.
     */
    public const SOURCE_GENERATED = 'generated';

    /**
     * A path is excluded by Git ignore rules.
     */
    public const SOURCE_GITIGNORE = 'gitignore';

    /** @var list<string> */
    private const IGNORED_DIRECTORIES = [
        '.fleet',
        '.git',
        '.goat-flow/logs',
        '.goat-flow/scratchpad',
        '.goat-flow/tasks',
        '.gruff-cache',
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
     * Wraps the project root the resolver evaluates Git ignore rules against.
     *
     * @param string $projectRoot - Project root used to evaluate Git ignore rules.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Decides whether a path is ignored and why, applying config, built-in, and generated exclusions in
     * priority order - the one place discovery and check-ignore agree on what gets skipped.
     *
     * Configured paths.ignore is authoritative and is applied even when ignored
     * files are otherwise included; default and generated exclusions are skipped
     * when ignored files are requested.
     *
     * @param string       $displayPath - Project-relative display path used for glob and directory matching.
     * @param string       $absolutePath - Absolute path used for filename matching.
     * @param list<string> $configuredPatterns - Configured paths.ignore glob patterns.
     * @param bool         $shouldIncludeIgnored - When true, the built-in default/generated ignores are bypassed for this run.
     *
     * @return IgnoreDecision - Whether the path is ignored and, if so, the source and pattern behind it.
     */
    public function decide(
        string $displayPath,
        string $absolutePath,
        array $configuredPatterns,
        bool $shouldIncludeIgnored,
    ): IgnoreDecision {
        $configuredPattern = $this->matchedConfiguredPattern($displayPath, $configuredPatterns);
        // The user's own paths.ignore wins first and always, even when they asked to include ignored files.
        if ($configuredPattern !== null) {
            // Configured paths.ignore wins first and unconditionally, even when ignored files are requested.
            return IgnoreDecision::ignored(self::SOURCE_CONFIG, $configuredPattern);
        }

        // With ignored files requested, skip the built-in exclusions below (config above was already honoured).
        if ($shouldIncludeIgnored) {
            // Requesting ignored files bypasses only the built-in default/generated exclusions below.
            return IgnoreDecision::notIgnored();
        }

        $defaultDirectory = $this->matchedDefaultDirectory($displayPath);
        // Next, a built-in tool or dependency directory like vendor or node_modules.
        if ($defaultDirectory !== null) {
            // A built-in tool/vendor directory match is the next exclusion source after config.
            return IgnoreDecision::ignored(self::SOURCE_DEFAULT, $defaultDirectory);
        }

        $generatedFilename = $this->matchedGeneratedFilename($absolutePath);
        // Last, a known generated or lock file such as composer.lock.
        if ($generatedFilename !== null) {
            // A known generated/lock filename is the last built-in exclusion before falling through.
            return IgnoreDecision::ignored(self::SOURCE_GENERATED, $generatedFilename);
        }

        return IgnoreDecision::notIgnored();
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
        foreach (self::IGNORED_DIRECTORIES as $ignoredDirectory) {
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
     * Reports whether a path is a known generated or lock file (composer.lock, package-lock.json, ...)
     * that gruff skips wherever it sits, or null when it is not one.
     *
     * @param string $absolutePath - Absolute filesystem path being tested.
     *
     * @return string|null - The matching filename; null when the path is not a known generated artifact.
     */
    public function matchedGeneratedFilename(string $absolutePath): ?string
    {
        $basename = basename($absolutePath);

        // Match on the bare filename only, so a generated artifact is ignored wherever it sits in the tree.
        return in_array($basename, self::IGNORED_FILENAMES, true) ? $basename : null;
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
