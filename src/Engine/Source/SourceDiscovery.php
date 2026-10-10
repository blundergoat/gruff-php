<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

use GruffPhp\Support\PathHelper;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * Works out exactly which files a run should analyse, honouring Git visibility and every layer of
 * ignore rules, so the user scans what they mean to and nothing they do not.
 *
 * Given the paths a user asked for (or the whole project by default), this resolves them to a concrete,
 * de-duplicated set of source files. It prefers Git's own tracked/unignored view of the worktree when
 * available - so a project's .gitignore is respected for free - and falls back to a filesystem walk
 * otherwise. Along the way it records what was missing and what was ignored (and why), tags each file as
 * PHP or plain text, and returns it all as a SourceDiscoveryResult the run and reports read from.
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
        'md',
        'neon',
        'sh',
        'toml',
        'xml',
        'yaml',
        'yml',
    ];

    /** @var list<string> */
    private const TEXT_FILENAMES = [
        '.editorconfig',
        '.gitattributes',
        '.gitignore',
    ];

    /**
     * Shared ignore engine used for every exclusion decision.
     */
    private PathIgnoreResolver $ignoreResolver;

    /**
     * Builds the scanner for one project root and wires up the shared ignore engine.
     *
     * @param string $projectRoot - Project root used to resolve requested paths.
     */
    public function __construct(private string $projectRoot)
    {
        $this->ignoreResolver = new PathIgnoreResolver($projectRoot);
    }

    /**
     * Resolves the user's requested paths into the concrete set of files to analyse, preferring Git's
     * view and falling back to a filesystem walk - the entry point for "what does this run scan?".
     *
     * @param list<string> $paths - Requested paths to discover; empty means the whole project.
     * @param bool         $shouldIncludeIgnored - Whether built-in ignored paths should still be included.
     * @param list<string> $configuredIgnorePatterns - Additional ignore patterns from config.
     *
     * @return SourceDiscoveryResult - discovered source files plus the missing inputs and ignored-path records the caller reports back.
     */
    public function discover(array $paths, bool $shouldIncludeIgnored = false, array $configuredIgnorePatterns = []): SourceDiscoveryResult
    {
        // No paths given means scan the whole project, so default to the root.
        $requestedPaths = $paths === [] ? ['.'] : $paths;

        // Unless the user asked to include ignored files, try Git's own view first - it respects .gitignore for us.
        if (!$shouldIncludeIgnored) {
            $gitResult = $this->discoverGitVisible($requestedPaths, $configuredIgnorePatterns);
            // Git's tracked/unignored view is authoritative when available, so skip the filesystem walk.
            if ($gitResult instanceof SourceDiscoveryResult) {
                // Git's tracked/unignored view is authoritative when available, so skip the filesystem walk.
                return $gitResult;
            }
        }

        $files          = [];
        $missingPaths   = [];
        $ignoredDetails = [];

        // Otherwise walk each requested path on the filesystem, collecting files, misses, and ignores.
        foreach ($requestedPaths as $path) {
            $this->collectPath(
                path:                     $path,
                shouldIncludeIgnored:     $shouldIncludeIgnored,
                configuredIgnorePatterns: $configuredIgnorePatterns,
                files:                    $files,
                missingPaths:             $missingPaths,
                ignoredDetails:           $ignoredDetails,
            );
        }

        ksort($files, SORT_STRING);
        sort($missingPaths, SORT_STRING);
        $ignoredDetails = $this->finalizeIgnored($ignoredDetails);

        return new SourceDiscoveryResult(array_values($files), $missingPaths, $this->pathsFromDetails($ignoredDetails), $ignoredDetails);
    }

    /**
     * Resolves one requested path into discovered files, a missing-path record, or an ignore record -
     * the per-path core of the filesystem fallback.
     *
     * @param string                    $path - Requested path to resolve against the project root.
     * @param bool                      $shouldIncludeIgnored - Whether built-in ignored paths should still be included.
     * @param list<string>              $configuredIgnorePatterns - Additional ignore patterns from config.
     * @param array<string, SourceFile> $files - Discovered files keyed by canonical path; appended in place.
     * @param list<string>              $missingPaths - Requested paths that do not exist; appended in place.
     * @param list<IgnoredPath>         $ignoredDetails - Ignored-path records; appended in place.
     *
     * @return void
     */
    private function collectPath(
        string $path,
        bool   $shouldIncludeIgnored,
        array  $configuredIgnorePatterns,
        array  &$files,
        array  &$missingPaths,
        array  &$ignoredDetails,
    ): void {
        $absolutePath = $this->absolutePath($path);

        // A requested path that is not on disk is recorded as missing, so the user knows it did not resolve.
        if (!file_exists($absolutePath)) {
            $missingPaths[] = $path;

            return;
        }

        $displayPath    = $this->displayPath($absolutePath);
        $isExplicitFile = is_file($absolutePath);
        $decision       = $this->ignoreResolver->decide(
            $displayPath,
            $absolutePath,
            $configuredIgnorePatterns,
            $shouldIncludeIgnored,
            $isExplicitFile,
        );
        // An ignored path is recorded (with why) rather than analysed.
        if ($decision->ignored) {
            $ignoredDetails[] = IgnoredPath::from($displayPath, $decision);

            return;
        }

        // A single file is added directly when it is a source type we scan.
        if (is_file($absolutePath)) {
            $type = $this->sourceType($absolutePath);
            // Only add the file when it is a type gruff analyses; skip anything else.
            if ($type !== null) {
                $files[$this->canonicalPath($absolutePath)] = new SourceFile(
                    $this->canonicalPath($absolutePath),
                    $this->displayPath($absolutePath),
                    $type,
                );
            }

            return;
        }

        // A directory is walked recursively for the source files inside it.
        if (is_dir($absolutePath)) {
            // Add each analysable file the walk yields under this directory.
            foreach ($this->walkDirectory($absolutePath, $shouldIncludeIgnored, $configuredIgnorePatterns, $ignoredDetails) as $file) {
                $canonicalPath = $this->canonicalPath($file->getPathname());
                $type          = $this->sourceType($canonicalPath);

                // Keep only the files whose type gruff actually scans.
                if ($type !== null) {
                    $files[$canonicalPath] = new SourceFile($canonicalPath, $this->displayPath($canonicalPath), $type);
                }
            }
        }
    }

    /**
     * Lazily yields the analysable files under a directory, pruning ignored nodes and their whole
     * subtrees so an ignored folder is never descended into.
     *
     * @param string            $directory - Existing directory whose tree is recursively scanned.
     * @param bool              $shouldIncludeIgnored - When true, default/generated ignores are bypassed so those files surface too.
     * @param list<string>      $configuredIgnorePatterns - Additional ignore patterns from config.
     * @param list<IgnoredPath> $ignoredDetails - Ignore records discovered while walking; appended in place.
     *
     * @return iterable<SplFileInfo> - lazily yielded regular files under the directory that classify as source; ignored nodes and their subtrees are
     *                               pruned.
     */
    private function walkDirectory(
        string $directory,
        bool   $shouldIncludeIgnored,
        array  $configuredIgnorePatterns,
        array  &$ignoredDetails,
    ): iterable {
        $recursiveDirectoryIterator      = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $recursiveCallbackFilterIterator = new RecursiveCallbackFilterIterator(
            $recursiveDirectoryIterator,
            function (SplFileInfo $file, mixed $key, RecursiveIterator $recursiveIterator) use ($shouldIncludeIgnored, $configuredIgnorePatterns, &$ignoredDetails): bool {
                $path        = $file->getPathname();
                $isDir       = $file->isDir();
                $displayPath = $this->displayPath($path);
                $decision    = $this->ignoreResolver->decide($displayPath, $path, $configuredIgnorePatterns, $shouldIncludeIgnored);

                // A node that is not ignored is kept so the iterator can descend and yield its files.
                if (!$decision->ignored) {
                    // Keeping the node lets the iterator descend into it and yield its files.
                    return true;
                }

                // Configured ignores are recorded for files and directories alike;
                // built-in default/generated ignores are only surfaced for directories.
                if ($decision->source === PathIgnoreResolver::SOURCE_CONFIG || $isDir) {
                    $ignoredDetails[] = IgnoredPath::from($displayPath, $decision);
                }

                // Pruning the node here also prunes its whole subtree from the walk.
                return false;
            },
        );

        $recursiveIteratorIterator = new RecursiveIteratorIterator($recursiveCallbackFilterIterator, RecursiveIteratorIterator::SELF_FIRST);

        // Walk the surviving tree and yield the real source files.
        foreach ($recursiveIteratorIterator as $file) {
            // Skip anything that is not a real filesystem entry.
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            // Yield only regular files that classify as a source type.
            if ($file->isFile() && $this->sourceType($file->getPathname()) !== null) {
                yield $file;
            }
        }
    }

    /**
     * Anchors a user-supplied path to the project root, returning an absolute filesystem path.
     *
     * @param string $path - Relative or absolute path as typed by the user.
     *
     * @return string - absolute filesystem path; relative inputs are anchored to the project root, absolute inputs returned unchanged.
     */
    private function absolutePath(string $path): string
    {
        return PathHelper::resolveAgainst($this->projectRoot, $path);
    }

    /**
     * Canonicalises a path via realpath(), falling back to the original string when the file does not exist.
     *
     * @param string $path - Absolute path to canonicalise; need not exist on disk.
     *
     * @return string - symlink-resolved canonical path when the file exists, otherwise the input string verbatim.
     */
    private function canonicalPath(string $path): string
    {
        return PathHelper::canonical($path);
    }

    /**
     * Renders a path for user-facing output relative to the project root; the root itself renders as ".".
     *
     * @param string $path - Absolute path to render for display.
     *
     * @return string - project-root-relative path for inputs inside the root (the root itself as "."), or the canonical absolute path when outside it.
     */
    private function displayPath(string $path): string
    {
        // A path outside the project root has no relative form, so fall back to its canonical absolute path.
        return PathHelper::relativeToRoot($path, $this->projectRoot) ?? PathHelper::canonical($path);
    }

    /**
     * Classifies a file as PHP, scannable text/config, or unsupported (null), so discovery only keeps
     * files a rule can actually read.
     *
     * @param string $path - Path whose extension and basename decide the source type.
     *
     * @return string|null - SourceFile::TYPE_PHP for .php, TYPE_TEXT for recognised config/dotfiles, or null when the file is unsupported and
     *                     excluded from discovery.
     */
    private function sourceType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // A .php file is PHP source, headed for the parser.
        if ($extension === self::PHP_EXTENSION) {
            return SourceFile::TYPE_PHP;
        }

        // Recognised config extensions, known dotfiles, and .env files are scanned as plain text.
        if (
            in_array($extension, self::TEXT_EXTENSIONS, true)
            || in_array(basename($path), self::TEXT_FILENAMES, true)
            || $this->isEnvLikeFile($path)
        ) {
            return SourceFile::TYPE_TEXT;
        }

        return null;
    }

    /**
     * Reports whether a file is `.env` or a `.env.*` variant, which the text scanners treat as
     * potentially secret-bearing.
     *
     * @param string $path - Path whose basename is tested against env-file naming.
     *
     * @return bool - true when the basename is `.env` or a `.env.*` variant that the text scanners treat as secret-bearing, false otherwise.
     */
    private function isEnvLikeFile(string $path): bool
    {
        $basename = basename($path);

        return $basename === '.env' || str_starts_with($basename, '.env.');
    }

    /**
     * Discovers files through Git's tracked plus unignored-untracked view of the worktree, or null when
     * Git cannot answer so the caller falls back to walking the filesystem.
     *
     * @param list<string> $requestedPaths - User-requested paths, or empty to discover the whole project.
     * @param list<string> $configuredIgnorePatterns - Project config ignore patterns applied after Git visibility.
     *
     * @return SourceDiscoveryResult|null - the Git-derived discovery result, or null when Git discovery is unavailable so the caller falls back to
     *                                    the filesystem walk.
     */
    private function discoverGitVisible(array $requestedPaths, array $configuredIgnorePatterns): ?SourceDiscoveryResult
    {
        // Outside a Git worktree there is no tracked view, so the caller falls back to the filesystem walk.
        if (!$this->isGitWorkTree()) {
            // Outside a Git worktree there is no tracked view, so the caller falls back to the filesystem walk.
            return null;
        }

        $request = $this->buildGitDiscoveryRequest($requestedPaths, $configuredIgnorePatterns);
        // A path that cannot be expressed as a pathspec (outside the root) forces the filesystem fallback.
        if ($request === null) {
            // A path that cannot be expressed as a pathspec (outside the root) forces the filesystem fallback.
            return null;
        }

        // Every requested path was missing or ignored, so report that without invoking Git.
        if ($request['pathspecs'] === []) {
            // Every requested path was missing or ignored, so report that without invoking Git.
            return $this->emptyGitDiscoveryResult($request['missingPaths'], $request['ignoredDetails']);
        }

        $visiblePaths = $this->gitVisiblePathspecs($request['pathspecs']);
        // A failed `git ls-files` invocation is non-fatal here; the caller retries via the filesystem walk.
        if ($visiblePaths === null) {
            // A failed `git ls-files` invocation is non-fatal here; the caller retries via the filesystem walk.
            return null;
        }

        $explicitFilePathspecs = array_values(array_map(
            static fn(array $requestedPath): string => $requestedPath['pathspec'],
            array_filter(
                $request['requestedExistingPaths'],
                static fn(array $requestedPath): bool => $requestedPath['isFile'],
            ),
        ));
        $visiblePaths = array_values(array_unique(array_merge($visiblePaths, $explicitFilePathspecs)));
        sort($visiblePaths, SORT_STRING);

        $ignoredDetails = array_merge(
            $request['ignoredDetails'],
            $this->ignoredRequestedGitPaths($request['requestedExistingPaths'], $visiblePaths),
        );
        $sourceResult   = $this->sourceFilesFromGitVisiblePaths($visiblePaths, $configuredIgnorePatterns, $explicitFilePathspecs);
        $files          = $sourceResult['files'];
        $ignoredDetails = array_merge($ignoredDetails, $sourceResult['ignoredDetails']);
        $missingPaths   = $request['missingPaths'];

        ksort($files, SORT_STRING);
        sort($missingPaths, SORT_STRING);
        $ignoredDetails = $this->finalizeIgnored($ignoredDetails);

        return new SourceDiscoveryResult(array_values($files), $missingPaths, $this->pathsFromDetails($ignoredDetails), $ignoredDetails);
    }

    /**
     * Pre-resolves the requested paths into Git pathspecs (plus the misses and ignores found so far), or
     * null when any request reaches outside the project root and Git discovery must be abandoned.
     *
     * @param list<string> $requestedPaths - User-requested paths, or empty to build a root-wide Git pathspec.
     * @param list<string> $configuredIgnorePatterns - Project config ignore patterns used to preclassify requested paths.
     *
     * @return array|null - pre-resolved Git query inputs (pathspecs to list plus missing/ignored
     *                      records found so far), or null when any request reaches outside the
     *                      project root and Git discovery must be abandoned.
     * @phpstan-return array{
     *     missingPaths: list<string>,
     *     ignoredDetails: list<IgnoredPath>,
     *     pathspecs: list<string>,
     *     requestedExistingPaths: list<array{absolutePath: string, pathspec: string, isFile: bool}>
     * }|null
     */
    private function buildGitDiscoveryRequest(array $requestedPaths, array $configuredIgnorePatterns): ?array
    {
        $missingPaths   = [];
        $ignoredDetails = [];
        $pathspecs      = [];
        /** @var list<array{absolutePath: string, pathspec: string, isFile: bool}> $requestedExistingPaths Existing request metadata checked after Git visibility is known. */
        $requestedExistingPaths = [];

        // Pre-classify each requested path as missing, ignored, or a pathspec to hand to Git.
        foreach ($requestedPaths as $path) {
            $absolutePath = $this->absolutePath($path);

            // A path that is not on disk is recorded as missing.
            if (!file_exists($absolutePath)) {
                $missingPaths[] = $path;
                continue;
            }

            $displayPath    = $this->displayPath($absolutePath);
            $isExplicitFile = is_file($absolutePath);
            $decision       = $this->ignoreResolver->decide(
                $displayPath,
                $absolutePath,
                $configuredIgnorePatterns,
                false,
                $isExplicitFile,
            );
            // An ignored path is recorded here so Git never even sees it.
            if ($decision->ignored) {
                $ignoredDetails[] = IgnoredPath::from($displayPath, $decision);
                continue;
            }

            $pathspec = $this->gitPathspec($absolutePath);
            // A request reaching outside the project root cannot use Git discovery at all; abandon it wholesale.
            if ($pathspec === null) {
                // A request reaching outside the project root cannot use Git discovery at all; abandon it wholesale.
                return null;
            }

            $pathspecs[]              = $pathspec;
            $requestedExistingPaths[] = [
                'absolutePath' => $absolutePath,
                'pathspec'     => $pathspec,
                'isFile'       => is_file($absolutePath),
            ];
        }

        return [
            'missingPaths'           => $missingPaths,
            'ignoredDetails'         => $ignoredDetails,
            'pathspecs'              => $pathspecs,
            'requestedExistingPaths' => $requestedExistingPaths,
        ];
    }

    /**
     * Builds a file-less result that still reports the misses and ignores, for when every requested path
     * was missing or ignored before Git was even consulted.
     *
     * @param list<string>      $missingPaths - Requested paths that were already known to be absent.
     * @param list<IgnoredPath> $ignoredDetails - Ignored requested paths collected before Git listing.
     *
     * @return SourceDiscoveryResult - a file-less result that still reports the missing and ignored inputs, so the user sees why nothing was analysed.
     */
    private function emptyGitDiscoveryResult(array $missingPaths, array $ignoredDetails): SourceDiscoveryResult
    {
        sort($missingPaths, SORT_STRING);
        $ignoredDetails = $this->finalizeIgnored($ignoredDetails);

        return new SourceDiscoveryResult([], $missingPaths, $this->pathsFromDetails($ignoredDetails), $ignoredDetails);
    }

    /**
     * Explains why each explicitly-requested directory that Git withheld was left out.
     *
     * @param list<array{absolutePath: string, pathspec: string, isFile: bool}> $requestedExistingPaths - Existing requested paths expressed as Git pathspecs.
     * @param list<string>                                                      $visiblePaths - Root-relative paths returned by `git ls-files`.
     *
     * @return list<IgnoredPath> - one record per explicitly-requested directory that Git's view withheld.
     */
    private function ignoredRequestedGitPaths(array $requestedExistingPaths, array $visiblePaths): array
    {
        $ignoredDetails = [];

        // Check each requested path Git did not surface and record why it was withheld.
        foreach ($requestedExistingPaths as $requestedPath) {
            // The path did show up in Git's view, so it was not withheld - nothing to explain.
            if ($this->hasVisibleFileForPathspec($requestedPath['pathspec'], $visiblePaths, $requestedPath['isFile'])) {
                continue;
            }

            $displayPath = $this->displayPath($requestedPath['absolutePath']);
            $gitRule     = $this->ignoreResolver->gitIgnoreRule($requestedPath['pathspec']);
            // Git's own ignore rule withheld it, so record that as the reason.
            if ($gitRule !== null) {
                $ignoredDetails[] = new IgnoredPath($displayPath, PathIgnoreResolver::SOURCE_GITIGNORE, $gitRule);
            }
        }

        return $ignoredDetails;
    }

    /**
     * Turns the paths Git reported into SourceFile objects, splitting off any that config or built-in
     * ignores still hold back.
     *
     * @param list<string> $visiblePaths - Root-relative paths returned by `git ls-files`.
     * @param list<string> $configuredIgnorePatterns - Project config ignore patterns applied before creating SourceFile objects.
     * @param list<string> $explicitFilePathspecs - Existing file operands that bypass Git and fallback exclusions.
     *
     * @return array{files: array<string, SourceFile>, ignoredDetails: list<IgnoredPath>} - the Git-visible set split into accepted source files
     *                      keyed by canonical path and the records for entries held back by config/default/generated ignores.
     */
    private function sourceFilesFromGitVisiblePaths(
        array $visiblePaths,
        array $configuredIgnorePatterns,
        array $explicitFilePathspecs,
    ): array
    {
        $files          = [];
        $ignoredDetails = [];

        // Classify each Git-visible path into an accepted file or an ignore record.
        foreach ($visiblePaths as $displayPath) {
            $this->appendGitVisibleSourceFile(
                $displayPath,
                $configuredIgnorePatterns,
                in_array($displayPath, $explicitFilePathspecs, true),
                $files,
                $ignoredDetails,
            );
        }

        return [
            'files'          => $files,
            'ignoredDetails' => $ignoredDetails,
        ];
    }

    /**
     * Classifies one Git-visible path, adding it as a source file or recording it as ignored - config
     * and built-in ignores still win over Git's own visibility.
     *
     * @param string                    $displayPath - Root-relative path emitted by `git ls-files` to classify.
     * @param list<string>              $configuredIgnorePatterns - Additional ignore patterns from config.
     * @param bool                      $isExplicitFile - Whether this path was supplied as an existing file operand.
     * @param array<string, SourceFile> $files - Accepted files keyed by canonical path; appended in place.
     * @param list<IgnoredPath>         $ignoredDetails - Ignore records for paths held back; appended in place.
     *
     * @return void
     */
    private function appendGitVisibleSourceFile(
        string $displayPath,
        array  $configuredIgnorePatterns,
        bool   $isExplicitFile,
        array  &$files,
        array  &$ignoredDetails,
    ): void {
        $absolutePath = $this->projectRoot . '/' . $displayPath;

        // Git can list a path that is no longer a regular file (deleted or a directory); skip it.
        if (!is_file($absolutePath)) {
            // Git can list a path that is no longer a regular file (deleted or a directory); skip it.
            return;
        }

        $relativeDisplayPath = $this->displayPath($absolutePath);

        $decision = $this->ignoreResolver->decide(
            $relativeDisplayPath,
            $absolutePath,
            $configuredIgnorePatterns,
            false,
            $isExplicitFile,
        );
        if ($decision->ignored) {
            $ignoredDisplayPath = $decision->source === PathIgnoreResolver::SOURCE_CONFIG
                ? $this->configuredIgnoredDisplayPath($absolutePath, $configuredIgnorePatterns)
                : $relativeDisplayPath;
            $ignoredDetails[] = new IgnoredPath(
                $ignoredDisplayPath,
                (string) $decision->source,
                (string) $decision->pattern,
            );

            return;
        }

        $type = $this->sourceType($absolutePath);
        // An unsupported extension is neither a source file nor an ignore worth reporting, so drop it silently.
        if ($type === null) {
            // An unsupported extension is neither a source file nor an ignore worth reporting; drop it silently.
            return;
        }

        $canonicalPath         = $this->canonicalPath($absolutePath);
        $files[$canonicalPath] = new SourceFile($canonicalPath, $this->displayPath($absolutePath), $type);
    }

    /**
     * Reports whether the project root sits inside a Git worktree, which gates all Git-based discovery.
     *
     * @return bool - true only when `git rev-parse` ran successfully and confirmed the project root is inside a worktree, gating all Git-based
     *              discovery.
     */
    private function isGitWorkTree(): bool
    {
        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $this->projectRoot);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === 'true';
    }

    /**
     * Asks `git ls-files` which of the pathspecs Git treats as tracked or unignored-untracked, or null
     * when Git errors so the caller falls back to the filesystem walk.
     *
     * @param list<string> $pathspecs - Git pathspecs to pass after `--`; empty input is handled by the caller.
     *
     * @return list<string>|null - deduplicated, sorted root-relative paths Git treats as tracked or unignored-untracked, or null when `git ls-files`
     *                           fails so the caller retries via the filesystem walk.
     */
    private function gitVisiblePathspecs(array $pathspecs): ?array
    {
        $command = array_merge(
            ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--'],
            array_values(array_unique($pathspecs)),
        );
        $process = new Process($command, $this->projectRoot);
        $process->run();

        // A failed `git ls-files` returns null so the caller falls back rather than reporting zero files.
        if (!$process->isSuccessful()) {
            // Signal failure with null so the caller can fall back to the filesystem walk rather than report zero files.
            return null;
        }

        $paths = array_values(array_filter(
                                  explode("\0", $process->getOutput()),
                                  // Drop the empty trailing entry the NUL-separated output leaves behind.
                                  static fn(string $path): bool => $path !== '',
                              ));
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Expresses an existing path as a Git pathspec relative to the project root, or null when it sits
     * outside the root and Git discovery must be dropped.
     *
     * @param string $absolutePath - Existing absolute path to express relative to the project root.
     *
     * @return string|null - the path expressed relative to the worktree ("." for the root itself), or null when it sits outside the project root and
     *                     Git discovery must be dropped.
     */
    private function gitPathspec(string $absolutePath): ?string
    {
        $root          = rtrim($this->canonicalPath($this->projectRoot), '/');
        $canonicalPath = $this->canonicalPath($absolutePath);

        // The path is the project root itself, which Git addresses as ".".
        if ($canonicalPath === $root) {
            return '.';
        }

        // The path sits inside the root, so strip the root prefix for the pathspec.
        if (str_starts_with($canonicalPath, $root . '/')) {
            return substr($canonicalPath, strlen($root) + 1);
        }

        return null;
    }

    /**
     * Reports whether a requested pathspec matched anything in Git's visible set, so the caller can tell
     * which requests were withheld.
     *
     * @param string       $pathspec - Requested pathspec to look for among the visible paths.
     * @param list<string> $visiblePaths - Root-relative paths Git reported as visible.
     * @param bool         $isFile - True when the request was a file, so only an exact match counts; directories also match by prefix.
     *
     * @return bool - true when the pathspec matched a visible path (an exact match for a file request, the directory or anything beneath it
     *              otherwise), false when the input was withheld.
     */
    private function hasVisibleFileForPathspec(string $pathspec, array $visiblePaths, bool $isFile): bool
    {
        $normalizedPathspec = trim($pathspec, '/');

        // A root-level request matches as long as Git returned anything at all.
        if ($normalizedPathspec === '' || $normalizedPathspec === '.') {
            // A root-level request matches as long as Git returned anything at all.
            return $visiblePaths !== [];
        }

        // Otherwise look for the pathspec among the visible paths.
        foreach ($visiblePaths as $visiblePath) {
            // A file request needs an exact match.
            if ($isFile && $visiblePath === $normalizedPathspec) {
                return true;
            }

            // A directory request matches the directory itself or anything beneath it.
            if (!$isFile && ($visiblePath === $normalizedPathspec || str_starts_with($visiblePath, $normalizedPathspec . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapses a config-ignored file to its `dir/**` base for reporting, so one glob does not list
     * every single file it covers.
     *
     * @param string       $path - Absolute path of the ignored file to present compactly.
     * @param list<string> $patterns - Configured ignore globs whose `/**` directory form collapses the report.
     *
     * @return string - the directory base when a `dir/**` glob covers the file (collapsing the report to one entry), otherwise the file's own
     *                root-relative display path.
     */
    private function configuredIgnoredDisplayPath(string $path, array $patterns): string
    {
        $displayPath = str_replace('\\', '/', $this->displayPath($path));

        // Look for a `dir/**` glob that covers this file, to report the directory once.
        foreach ($patterns as $pattern) {
            $normalizedPattern = trim(str_replace('\\', '/', $pattern), '/');
            // Only directory globs collapse the report, so skip the rest.
            if (!str_ends_with($normalizedPattern, '/**')) {
                continue;
            }

            $base = substr($normalizedPattern, 0, -3);
            // This file sits under the glob's directory, so report that directory instead of the file.
            if ($displayPath === $base || str_starts_with($displayPath, $base . '/')) {
                // Report the directory base once instead of every file under a `dir/**` ignore.
                return $base;
            }
        }

        return $displayPath;
    }

    /**
     * Reduces the ignored records to one entry per path, sorted, so reports stay stable run to run.
     *
     * @param list<IgnoredPath> $ignoredDetails - Ignored-path records that may contain duplicate paths from different discovery stages.
     *
     * @return list<IgnoredPath> - one record per path in stable path order, so repeated runs and snapshots stay deterministic.
     */
    private function finalizeIgnored(array $ignoredDetails): array
    {
        $byPath = [];
        // Keep the first record seen for each path, dropping later duplicates.
        foreach ($ignoredDetails as $ignoredPath) {
            $byPath[$ignoredPath->path] ??= $ignoredPath;
        }

        $deduped = array_values($byPath);
        usort($deduped, static fn(IgnoredPath $left, IgnoredPath $right): int => strcmp($left->path, $right->path));

        return $deduped;
    }

    /**
     * Extracts just the path strings from the enriched ignore records, for the legacy plain-list field.
     *
     * @param list<IgnoredPath> $ignoredDetails - Enriched ignored-path records whose path strings feed the legacy plain list.
     *
     * @return list<string> - just the path strings extracted from the detail records, for the legacy plain-list field alongside the richer records.
     */
    private function pathsFromDetails(array $ignoredDetails): array
    {
        return array_map(static fn(IgnoredPath $ignoredPath): string => $ignoredPath->path, $ignoredDetails);
    }
}
