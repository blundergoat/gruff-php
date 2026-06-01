<?php

declare(strict_types=1);

namespace GruffPhp\Source;

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
     * @param string $projectRoot - Project root used to evaluate Git ignore rules.
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Resolve the configured and built-in ignore decision for a path, without consulting Git.
     *
     * Configured paths.ignore is authoritative and is applied even when ignored
     * files are otherwise included; default and generated exclusions are skipped
     * when ignored files are requested.
     *
     * @param string       $displayPath - Project-relative display path used for glob and directory matching.
     * @param string       $absolutePath - Absolute path used for filename matching.
     * @param list<string> $configuredPatterns - Configured paths.ignore glob patterns.
     * @param bool         $shouldIncludeIgnored - Whether default/generated ignores are bypassed for this run.
     *
     * @return IgnoreDecision - Decision describing whether and why the path is ignored.
     */
    public function decide(
        string $displayPath,
        string $absolutePath,
        array $configuredPatterns,
        bool $shouldIncludeIgnored,
    ): IgnoreDecision {
        $configuredPattern = $this->matchedConfiguredPattern($displayPath, $configuredPatterns);
        if ($configuredPattern !== null) {
            // Configured paths.ignore wins first and unconditionally, even when ignored files are requested.
            return IgnoreDecision::ignored(self::SOURCE_CONFIG, $configuredPattern);
        }

        if ($shouldIncludeIgnored) {
            // Requesting ignored files bypasses only the built-in default/generated exclusions below.
            return IgnoreDecision::notIgnored();
        }

        $defaultDirectory = $this->matchedDefaultDirectory($displayPath);
        if ($defaultDirectory !== null) {
            // A built-in tool/vendor directory match is the next exclusion source after config.
            return IgnoreDecision::ignored(self::SOURCE_DEFAULT, $defaultDirectory);
        }

        $generatedFilename = $this->matchedGeneratedFilename($absolutePath);
        if ($generatedFilename !== null) {
            // A known generated/lock filename is the last built-in exclusion before falling through.
            return IgnoreDecision::ignored(self::SOURCE_GENERATED, $generatedFilename);
        }

        return IgnoreDecision::notIgnored();
    }

    /**
     * Return the configured ignore glob that matches the path, or null when none match.
     *
     * @param string       $displayPath - Project-relative display path being tested.
     * @param list<string> $patterns - Configured paths.ignore glob patterns.
     *
     * @return string|null - Matching pattern, or null when the path is not configured-ignored.
     */
    public function matchedConfiguredPattern(string $displayPath, array $patterns): ?string
    {
        $normalizedDisplayPath = str_replace('\\', '/', $displayPath);

        foreach ($patterns as $pattern) {
            if ($this->matchesPathPattern($normalizedDisplayPath, $pattern)) {
                // First matching glob wins; return it verbatim so callers can report which rule excluded the path.
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Return the built-in ignored directory token that matches the path, or null when none match.
     *
     * @param string $displayPath - Project-relative display path being tested.
     *
     * @return string|null - Matching directory token, or null when the path is not default-ignored.
     */
    public function matchedDefaultDirectory(string $displayPath): ?string
    {
        $normalizedDisplayPath = str_replace('\\', '/', $displayPath);
        $segments              = explode('/', trim($normalizedDisplayPath, '/'));

        foreach (self::IGNORED_DIRECTORIES as $ignoredDirectory) {
            $ignoredSegments = explode('/', $ignoredDirectory);
            $ignoredCount    = count($ignoredSegments);

            for ($i = 0, $max = count($segments) - $ignoredCount; $i <= $max; $i++) {
                if (array_slice($segments, $i, $ignoredCount) === $ignoredSegments) {
                    // A matched directory token anywhere in the path excludes it; return the token that matched.
                    return $ignoredDirectory;
                }
            }
        }

        return null;
    }

    /**
     * Return the built-in generated/lock filename that matches the path, or null when none match.
     *
     * @param string $absolutePath - Absolute filesystem path being tested.
     *
     * @return string|null - Matching filename, or null when the path is not a known generated artifact.
     */
    public function matchedGeneratedFilename(string $absolutePath): ?string
    {
        $basename = basename($absolutePath);

        // Match on the bare filename only, so a generated artifact is ignored wherever it sits in the tree.
        return in_array($basename, self::IGNORED_FILENAMES, true) ? $basename : null;
    }

    /**
     * Return the Git ignore rule that excludes the pathspec, or null when Git does not ignore it.
     *
     * @param string $pathspec - Project-relative pathspec to test against Git ignore rules.
     *
     * @return string|null - Matching git rule (or the pathspec when no rule text is reported), or null when not ignored.
     */
    public function gitIgnoreRule(string $pathspec): ?string
    {
        $process = new Process(['git', 'check-ignore', '--verbose', '--', $pathspec], $this->projectRoot);
        $process->run();

        if ($process->getExitCode() !== 0) {
            // Non-zero exit is git's "not ignored" signal; report no rule rather than treating it as an error.
            return null;
        }

        $output = trim($process->getOutput());
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
     * Detect whether the display path matches a glob-style pattern (`*`, `**`, `?` supported).
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

        if ($normalizedPattern === $normalizedPath || str_starts_with($normalizedPath, $normalizedPattern . '/')) {
            // A literal or directory-prefix match needs no glob, so short-circuit before building the regex.
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
