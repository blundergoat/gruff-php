<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Support\PathHelper;

/**
 * Stateless path- and finding-normalisation helpers shared by the analyse command and branch-review builder.
 */
final readonly class AnalysisFindingSupport
{
    /**
     * Drop sensitive-data findings whose preview is on the config allowlist.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param list<Finding> $findings - Findings produced for the run.
     * @param AnalysisConfig $config - Effective config supplying the secret-preview allowlist.
     *
     * @return list<Finding> - Findings with allowlisted secret previews removed.
     */
    public function filterAllowedSecretPreviews(array $findings, AnalysisConfig $config): array
    {
        $allowedPreviews = $config->allowedSecretPreviews();
        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($allowedPreviews === []) {
            return $findings;
        }

        return array_values(array_filter(
            $findings,
            static function (Finding $finding) use ($allowedPreviews): bool {
                // User view: missing data becomes a safe terminal output default.
                $preview = $finding->metadata['preview'] ?? null;

                // Keep the finding unless it is a sensitive-data hit whose string preview is on the allowlist.
                return $finding->pillar !== Pillar::SensitiveData
                    || !is_string($preview)
                    || !in_array($preview, $allowedPreviews, true);
            },
        ));
    }

    /**
     * Keep only findings whose file is in the changed-files set.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param list<Finding> $findings - Findings to filter.
     * @param list<string>  $changedFiles - Project-relative paths considered changed.
     *
     * @return list<Finding> - Findings located in a changed file.
     */
    public function filterFindingsToChangedFiles(array $findings, array $changedFiles): array
    {
        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($changedFiles === []) {
            // An empty changed set means nothing changed, so no finding qualifies; this is an intentional drop-all.
            return [];
        }

        $changed = array_fill_keys($changedFiles, true);

        return array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => isset($changed[$finding->filePath]),
        ));
    }

    /**
     * Keep project-rule findings inside the files requested by this invocation.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param list<Finding> $findings - Findings to filter.
     * @param list<string>  $projectRuleIds - Rule ids whose output came from project-wide context.
     * @param list<string>  $filePaths - Project-relative display paths in the requested source set; an empty set drops every project-rule finding.
     *
     * @return list<Finding> - Findings with out-of-scope project-rule rows removed.
     */
    public function filterProjectRuleFindingsToFiles(array $findings, array $projectRuleIds, array $filePaths): array
    {
        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($projectRuleIds === []) {
            return $findings;
        }

        $projectRules = array_fill_keys($projectRuleIds, true);

        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($filePaths === []) {
            // The invocation requested files but discovered none, so nothing is in scope. Drop every
            // project-rule finding rather than leaking the whole-project context this run never loaded.
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
     * Rewrite absolute finding paths to be relative to the requested base directory.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param list<Finding> $findings - Findings whose paths may need normalising.
     * @param string|null   $pathsRelativeTo - Base directory for relative paths, or null to leave paths untouched.
     *
     * @return list<Finding> - Findings with absolute paths rebased under the directory when it resolves.
     */
    public function normalizeFindingPaths(array $findings, ?string $pathsRelativeTo): array
    {
        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
        if ($pathsRelativeTo === null) {
            return $findings;
        }

        $realRoot = realpath($pathsRelativeTo);
        // User view: choose the terminal output branch for this case.
        if ($realRoot === false) {
            return $findings;
        }

        $root       = rtrim(PathHelper::normalizeSeparators($realRoot), '/');
        $normalized = [];

        // User view: add each item that can appear in terminal output.
        foreach ($findings as $finding) {
            $path = PathHelper::normalizeSeparators($finding->filePath);
            // User view: choose the terminal output branch for this case.
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
     * Normalise user-supplied path arguments to project-relative paths sorted for stable matching.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string       $projectRoot - Project root requested paths resolve against.
     * @param list<string> $paths - User-supplied path arguments.
     *
     * @return list<string> - Project-relative paths sorted for stable matching.
     */
    public function normaliseRequestedPaths(string $projectRoot, array $paths): array
    {
        $root       = rtrim(PathHelper::canonical($projectRoot), '/');
        $normalised = [];

        // User view: add each item that can appear in terminal output.
        foreach ($paths as $path) {
            $candidate = PathHelper::normalizeSeparators($path);
            // User view: choose the terminal output branch for this case.
            // User view: an empty value becomes a clear terminal output fallback.
            if ($candidate === '') {
                continue;
            }

            // User view: choose the terminal output branch for this case.
            if (PathHelper::isAbsolute($candidate)) {
                $candidate = rtrim(PathHelper::canonical($candidate), '/');
                // User view: choose the terminal output branch for this case.
                if ($candidate === $root) {
                    $candidate = '.';
                }
                // User view: choose the next terminal output branch for this case.
                elseif (str_starts_with($candidate, $root . '/')) {
                    $candidate = substr($candidate, strlen($root) + 1);
                } else {
                    continue;
                }
            }

            while (str_starts_with($candidate, './')) {
                $candidate = substr($candidate, 2);
            }

            $candidate                                        = rtrim($candidate, '/');
            // User view: an empty value becomes a clear terminal output fallback.
            $normalised[$candidate === '' ? '.' : $candidate] = $candidate === '' ? '.' : $candidate;
        }

        $paths = array_values($normalised);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Report whether a changed file is inside the requested path set.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string       $changedFile - Project-relative changed file path.
     * @param list<string> $requestedPaths - Normalised requested paths to match against.
     *
     * @return bool - True when the changed file is inside the requested path set.
     */
    public function matchesRequestedPath(string $changedFile, array $requestedPaths): bool
    {
        $changedFile = PathHelper::normalizeSeparators($changedFile);

        // User view: add each item that can appear in terminal output.
        foreach ($requestedPaths as $requestedPath) {
            // User view: choose the terminal output branch for this case.
            if ($requestedPath === '.') {
                // '.' means the whole project was requested, so every changed file is in scope.
                return true;
            }

            // User view: choose the terminal output branch for this case.
            if ($changedFile === $requestedPath || str_starts_with($changedFile, $requestedPath . '/')) {
                // A requested path scopes itself and everything under it as a directory; the trailing '/' is the
                // boundary that stops a request for 'src/Foo' matching the sibling file 'src/FooBar.php'.
                return true;
            }
        }

        return false;
    }

    /**
     * Keep the changed paths that exist on disk under the project root.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string       $projectRoot - Project root the changed paths resolve against.
     * @param list<string> $changedFiles - Project-relative paths from a diff.
     *
     * @return list<string> - Existing paths that can be passed to source discovery.
     */
    public function existingChangedFiles(string $projectRoot, array $changedFiles): array
    {
        $existing = [];

        // User view: add each item that can appear in terminal output.
        foreach ($changedFiles as $changedFile) {
            // User view: choose the terminal output branch for this case.
            if (file_exists(PathHelper::resolveAgainst($projectRoot, $changedFile))) {
                $existing[] = $changedFile;
            }
        }

        sort($existing, SORT_STRING);

        return array_values(array_unique($existing));
    }

    /**
     * Keep requested paths that exist in the base snapshot.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string       $baseRoot - Base-snapshot root the paths resolve against.
     * @param list<string> $paths - Requested project-relative paths.
     *
     * @return list<string> - Paths that exist in the base snapshot.
     */
    public function existingSnapshotPaths(string $baseRoot, array $paths): array
    {
        // User view: an empty value becomes a clear terminal output fallback.
        $requested = $paths === [] ? ['.'] : $paths;
        $existing  = [];

        // User view: add each item that can appear in terminal output.
        foreach ($requested as $path) {
            $absolute = PathHelper::resolveAgainst($baseRoot, $path);
            // User view: choose the terminal output branch for this case.
            if (file_exists($absolute)) {
                // User view: missing data becomes a safe terminal output default.
                $existing[] = PathHelper::relativeToRoot($absolute, $baseRoot) ?? $path;
            }
        }

        // User view: an empty value becomes a clear terminal output fallback.
        return $existing === [] ? [] : $existing;
    }
}
