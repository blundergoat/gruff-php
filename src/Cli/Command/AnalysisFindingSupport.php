<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Support\PathHelper;

/**
 * Stateless helpers that tidy findings and paths after a scan, before the user ever sees them.
 *
 * Changed-code and branch-review flows use them to scope findings and show short project-relative paths.
 * Methods never mutate their inputs; filesystem lookups only resolve the paths users supplied.
 */
final readonly class AnalysisFindingSupport
{
    /**
     * Narrows findings to the files the user actually touched, so a changed-only or branch review
     * reports on this diff instead of the whole project.
     *
     * @param list<Finding> $findings     - Findings to filter.
     * @param list<string>  $changedFiles - Project-relative paths considered changed; an empty set means nothing changed and drops every finding.
     *
     * @return list<Finding> - Findings located in a changed file; empty when no files changed.
     */
    public function filterFindingsToChangedFiles(array $findings, array $changedFiles): array
    {
        // An empty changed set means nothing changed, so no finding qualifies - an intentional drop-all, not a bug.
        if ($changedFiles === []) {
            return [];
        }

        $changed = array_fill_keys($changedFiles, true);

        return array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => isset($changed[$finding->filePath]),
        ));
    }

    /**
     * Scopes whole-project rule findings to the files the user asked about, so analysing a single
     * path does not surface project-wide issues from files that were never requested.
     *
     * @param list<Finding> $findings       - Findings to filter.
     * @param list<string>  $projectRuleIds - Project-wide rule ids; empty means no rule needs scoping and every finding is kept.
     * @param list<string>  $filePaths      - Requested display paths; an empty set drops every project-rule finding.
     *
     * @return list<Finding> - Findings with out-of-scope project-rule rows removed.
     */
    public function filterProjectRuleFindingsToFiles(array $findings, array $projectRuleIds, array $filePaths): array
    {
        if ($projectRuleIds === []) {
            return $findings;
        }

        $projectRules = array_fill_keys($projectRuleIds, true);

        // An empty source set excludes project-rule findings while leaving other rules' findings eligible.
        if ($filePaths === []) {
            return array_values(array_filter(
                $findings,
                static fn(Finding $finding): bool => !isset($projectRules[$finding->ruleId]),
            ));
        }

        $files = array_fill_keys($filePaths, true);

        return array_values(array_filter(
            $findings,
            static fn(Finding $finding): bool => !isset($projectRules[$finding->ruleId])
                || isset($files[$finding->filePath]),
        ));
    }

    /**
     * Shortens absolute finding paths that share the resolved base-directory prefix for report display.
     *
     * @param list<Finding> $findings        - Findings whose paths may need normalising.
     * @param string|null   $pathsRelativeTo - Base directory for relative paths; null leaves every path exactly as the run produced it.
     *
     * @return list<Finding> - Findings with the resolved base prefix rebased for display; other paths remain unchanged.
     */
    public function normalizeFindingPaths(array $findings, ?string $pathsRelativeTo): array
    {
        // No base directory was requested, so leave every path exactly as the run reported it.
        if ($pathsRelativeTo === null) {
            return $findings;
        }

        $realRoot = realpath($pathsRelativeTo);
        // The base directory does not resolve on disk, so there is nothing to rebase against; hand paths back untouched.
        if ($realRoot === false) {
            return $findings;
        }

        $root       = rtrim(PathHelper::normalizeSeparators($realRoot), '/');
        $normalized = [];

        foreach ($findings as $finding) {
            $path = PathHelper::normalizeSeparators($finding->filePath);
            if (!PathHelper::isAbsolute($path)) {
                $normalized[] = $finding;
                continue;
            }

            $filePath     = str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $finding->filePath;
            $normalized[] = new Finding(
                ruleId:           $finding->ruleId,
                message:          $finding->message,
                filePath:         $filePath,
                line:             $finding->line,
                severity:         $finding->severity,
                pillar:           $finding->pillar,
                tier:             $finding->tier,
                confidence:       $finding->confidence,
                endLine:          $finding->endLine,
                column:           $finding->column,
                symbol:           $finding->symbol,
                remediation:      $finding->remediation,
                secondaryPillars: $finding->secondaryPillars,
                metadata:         $finding->metadata,
            );
        }

        return $normalized;
    }

    /**
     * Normalises separators, leading ./ segments, and trailing slashes for path matching.
     * Absolute inputs are rebased only when their canonical path matches the project-root prefix.
     *
     * @param string       $projectRoot - Project root requested paths resolve against.
     * @param list<string> $paths       - Path arguments; blank values and absolute paths without the project-root prefix are dropped.
     *
     * @return list<string> - Unique sorted paths; relative arguments retain parent-directory segments, and no usable input yields an empty list.
     */
    public function normaliseRequestedPaths(string $projectRoot, array $paths): array
    {
        $root       = rtrim(PathHelper::canonical($projectRoot), '/');
        $normalised = [];

        foreach ($paths as $path) {
            $candidate = PathHelper::normalizeSeparators($path);
            if ($candidate === '') {
                continue;
            }

            // An absolute path such as /home/me/proj/src must be rebased against the project root first.
            if (PathHelper::isAbsolute($candidate)) {
                $candidate = rtrim(PathHelper::canonical($candidate), '/');
                // An absolute path without the project-root prefix is omitted from this scoped set.
                if ($candidate === $root) {
                    $candidate = '.';
                } elseif (str_starts_with($candidate, $root . '/')) {
                    $candidate = substr($candidate, strlen($root) + 1);
                } else {
                    continue;
                }
            }

            while (str_starts_with($candidate, './')) {
                $candidate = substr($candidate, 2);
            }

            $candidate                                        = rtrim($candidate, '/');
            $normalised[$candidate === '' ? '.' : $candidate] = $candidate === '' ? '.' : $candidate;
        }

        $paths = array_values($normalised);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Decides whether a changed file falls within what the user asked to review, so a branch review
     * scoped to `src/` ignores a change made anywhere else.
     *
     * @param string       $changedFile    - Project-relative changed file path.
     * @param list<string> $requestedPaths - Normalised requested paths to match against; an empty set matches nothing.
     *
     * @return bool - True when the changed file is inside the requested path set.
     */
    public function matchesRequestedPath(string $changedFile, array $requestedPaths): bool
    {
        $changedFile = PathHelper::normalizeSeparators($changedFile);

        foreach ($requestedPaths as $requestedPath) {
            // A `.` request means the whole project, so any changed file counts as in scope.
            if ($requestedPath === '.') {
                return true;
            }

            // A requested path covers itself and everything beneath it; the trailing `/` is the boundary that
            // stops a request for `src/Foo` also matching the sibling file `src/FooBar.php`.
            if ($changedFile === $requestedPath || str_starts_with($changedFile, $requestedPath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filters a diff's paths down to the ones still present on disk, so a file the user deleted in
     * this change is not handed to source discovery as something to scan.
     *
     * @param string       $projectRoot  - Project root the changed paths resolve against.
     * @param list<string> $changedFiles - Project-relative paths from a diff.
     *
     * @return list<string> - Existing paths that can be passed to source discovery; empty when none survive.
     */
    public function existingChangedFiles(string $projectRoot, array $changedFiles): array
    {
        $existing = [];

        foreach ($changedFiles as $changedFile) {
            if (file_exists(PathHelper::resolveAgainst($projectRoot, $changedFile))) {
                $existing[] = $changedFile;
            }
        }

        sort($existing, SORT_STRING);

        return array_values(array_unique($existing));
    }

    /**
     * Filters requested paths to those present in the branch review's base snapshot, so the "before"
     * side of the diff only looks where the checked-out base actually has files.
     *
     * @param string       $baseRoot - Base-snapshot root the paths resolve against.
     * @param list<string> $paths    - Requested project-relative paths; an empty list is treated as the whole project (`.`).
     *
     * @return list<string> - Paths that exist in the base snapshot; empty when none are present there.
     */
    public function existingSnapshotPaths(string $baseRoot, array $paths): array
    {
        // No explicit paths means the user is reviewing everything, so fall back to the whole project.
        $requested = $paths === [] ? ['.'] : $paths;
        $existing  = [];

        foreach ($requested as $path) {
            $absolute = PathHelper::resolveAgainst($baseRoot, $path);
            // Present in the base, so it can anchor the before/after comparison; a path new in this branch is skipped.
            if (file_exists($absolute)) {
                $existing[] = PathHelper::relativeToRoot($absolute, $baseRoot) ?? $path;
            }
        }

        return $existing === [] ? [] : $existing;
    }
}
