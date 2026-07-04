<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\SourceTextRuleInterface;

/**
 * Flags a risky GitHub Actions workflow pattern - an unpinned action ref, `write-all` token permissions, a
 * `pull_request_target` trigger, secrets in a PR-triggered job, or `github.event` data spliced into a shell
 * - so the user tightens the workflow before a fork PR can steal secrets or run code.
 *
 * Scans a `.github/workflows/*.yml` file line by line as text. Warning, medium confidence.
 */
final class GithubActionsRiskyWorkflowRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for risky GitHub Actions workflow patterns.
     */
    public const ID = 'security.github-actions-risky-workflow';

    /**
     * Token permission scopes whose `write` grant is broader than most jobs need.
     *
     * @var list<string>
     */
    private const WRITE_PERMISSIONS = [
        'actions',
        'checks',
        'contents',
        'deployments',
        'id-token',
        'packages',
        'pull-requests',
        'repository-projects',
    ];

    /**
     * Describes the risky-GitHub-Actions-workflow rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advertise this as a medium-confidence Security warning so downstream gating can weigh it.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Risky GitHub Actions workflow',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Reports each risky pattern in a GitHub Actions workflow YAML file.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for risky workflow patterns.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!$this->isWorkflowFile($analysisUnit->file->displayPath)) {
            // Non-workflow files cannot carry these YAML patterns, so skip them with no findings.
            return [];
        }

        $findings             = [];
        $lines                = preg_split('/\R/', $analysisUnit->source);
        $hasPullRequestEvent  = $this->hasPullRequestEvent($analysisUnit->source);
        $runBlockIndent       = null;
        $reportedRunBlockLine = null;

        // Scan the workflow line by line.
        foreach ($lines === false ? [] : $lines as $index => $line) {
            $lineNumber = $index + 1;
            $sinks      = $this->lineFindingSinks($line, $hasPullRequestEvent, $runBlockIndent, $reportedRunBlockLine);

            // Emit a finding for each risky sink found on the line.
            foreach ($sinks as $sink) {
                $findings[] = $this->finding($analysisUnit, $lineNumber, $sink);
            }
        }

        return $findings;
    }

    /**
     * Reports whether a display path is a GitHub Actions workflow YAML file.
     *
     * @param string $displayPath - Repository-relative path of the unit, in either slash style.
     *
     * @return bool - True when the display path is a GitHub Actions workflow YAML file.
     */
    private function isWorkflowFile(string $displayPath): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        // Match only GitHub Actions workflow YAML files, even when nested under fixtures.
        return preg_match('#(^|/)\.github/workflows/[^/]+\.(?:ya?ml)$#', $normalizedPath) === 1;
    }

    /**
     * Collects the risky sinks on one line, tracking multiline run-block state across lines.
     *
     * @param string   $line - One raw YAML line, leading indentation intact for block-scalar tracking.
     * @param bool     $hasPullRequestEvent - True when a pull_request event is present, gating secret leaks.
     * @param int|null $runBlockIndent - Current block scalar run indentation, updated in place.
     * @param int|null $reportedRunBlockLine - First reported run-block interpolation line, updated in place.
     *
     * @return list<string> - Per-line sinks, including at most one run-block interpolation per block.
     */
    private function lineFindingSinks(
        string $line,
        bool $hasPullRequestEvent,
        ?int &$runBlockIndent,
        ?int &$reportedRunBlockLine,
    ): array {
        $this->closeRunBlockWhenDedented($line, $runBlockIndent, $reportedRunBlockLine);

        $sinks = [];
        // Add each single-line sink found on this line.
        foreach ($this->directLineSinks($line, $hasPullRequestEvent) as $sink) {
            $sinks[] = $sink;
        }

        // A `run: |` opener starts tracking a block scalar.
        if ($this->isRunBlockStart($line)) {
            $runBlockIndent = $this->indent($line);
        }

        // Report the first github.event interpolation inside an open run block.
        if ($runBlockIndent !== null && $reportedRunBlockLine === null && $this->hasGithubEventInterpolation($line)) {
            $reportedRunBlockLine = 1;
            $sinks[]              = 'github-event-run-interpolation';
        }

        return $sinks;
    }

    /**
     * Collects the single-line risky sinks that need no multiline run-block state.
     *
     * @param string $line - One raw YAML line to scan for single-line risky patterns.
     * @param bool   $hasPullRequestEvent - True when a pull_request event is present, enabling the secrets-in-PR sink.
     *
     * @return list<string> - Single-line sinks that do not need multiline run-block state.
     */
    private function directLineSinks(string $line, bool $hasPullRequestEvent): array
    {
        $sinks = [];

        // A pull_request_target trigger runs with the base repo's secrets.
        if ($this->isPullRequestTargetTrigger($line)) {
            $sinks[] = 'pull_request_target';
        }

        // Add any risky uses: or permissions: sink on the line.
        foreach ([$this->riskyUsesFinding($line), $this->riskyPermissionFinding($line)] as $sink) {
            // Skip a check that found nothing.
            if ($sink !== null) {
                $sinks[] = $sink;
            }
        }

        // Secrets referenced in a PR-triggered workflow can leak to a fork.
        if ($hasPullRequestEvent && str_contains($line, '${{ secrets.')) {
            $sinks[] = 'secrets-in-pr-workflow';
        }

        // An inline run step interpolating event data is a shell-injection sink.
        if ($this->hasInlineGithubEventRun($line)) {
            $sinks[] = 'github-event-run-interpolation';
        }

        return $sinks;
    }

    /**
     * Resets run-block state once a non-empty line dedents back to the parent level.
     *
     * @param string   $line - Current YAML line; its indentation decides whether the run block closed.
     * @param int|null $runBlockIndent - Active run-block indentation, cleared in place once the block ends.
     * @param int|null $reportedRunBlockLine - Already-reported interpolation marker, cleared in place with the indent.
     *
     * @return void
     */
    private function closeRunBlockWhenDedented(string $line, ?int &$runBlockIndent, ?int &$reportedRunBlockLine): void
    {
        if ($runBlockIndent === null || trim($line) === '' || $this->indent($line) > $runBlockIndent) {
            // Still inside (or below) the run block, or on a blank line: leave the tracked state untouched.
            return;
        }

        $runBlockIndent       = null;
        $reportedRunBlockLine = null;
    }

    /**
     * Reports whether the workflow listens to a pull_request event.
     *
     * @param string $source - Full workflow YAML source to scan for a pull_request trigger in either syntax.
     *
     * @return bool - True when the workflow listens to pull_request or pull_request_target.
     */
    private function hasPullRequestEvent(string $source): bool
    {
        // Match mapping-style pull_request or pull_request_target triggers.
        $mappingTrigger = preg_match('/^\s*pull_request(?:_target)?\s*:/m', $source) === 1;
        // Match list-style pull_request or pull_request_target triggers.
        $listTrigger = preg_match('/^\s*-\s*pull_request(?:_target)?\s*(?:#.*)?$/m', $source) === 1;

        // Either trigger syntax counts, so the workflow can receive a fork pull request and its secrets.
        return $mappingTrigger || $listTrigger;
    }

    /**
     * Reports whether a line declares a pull_request_target trigger.
     *
     * @param string $line - One YAML line to test for a pull_request_target mapping key.
     *
     * @return bool - True when the line declares a pull_request_target trigger.
     */
    private function isPullRequestTargetTrigger(string $line): bool
    {
        // Match pull_request_target as an event mapping key.
        $matches = preg_match('/^\s*pull_request_target\s*:/', $line);

        // preg_match yields 1 only on a match; treat any non-match (including a regex error) as not a trigger.
        return $matches === 1;
    }

    /**
     * Reports whether a line starts a YAML block-scalar run step.
     *
     * @param string $line - One YAML line to test for the start of a block-scalar run step.
     *
     * @return bool - True when the line starts a YAML block-scalar run step.
     */
    private function isRunBlockStart(string $line): bool
    {
        // Match run: | and run: > forms, including list-item steps.
        $matches = preg_match('/^\s*(?:-\s*)?run:\s*(?:\||>)\s*(?:#.*)?$/', $line);

        // Only an exact match opens a run block; any other result leaves block tracking off.
        return $matches === 1;
    }

    /**
     * Reports whether an inline run step interpolates github.event data.
     *
     * @param string $line - One YAML line to test for an inline run step that interpolates event data.
     *
     * @return bool - True when an inline run step interpolates github.event data.
     */
    private function hasInlineGithubEventRun(string $line): bool
    {
        // Match shell steps where untrusted github.event context is interpolated directly.
        $matches = preg_match('/^\s*(?:-\s*)?run:\s+.*\$\{\{\s*github\.event\./', $line);

        // A match means untrusted event text reaches the shell on this single line, so flag it.
        return $matches === 1;
    }

    /**
     * Reports whether a line carries github.event interpolation.
     *
     * @param string $line - One line of a run block to test for any github.event interpolation.
     *
     * @return bool - True when any text contains github.event interpolation.
     */
    private function hasGithubEventInterpolation(string $line): bool
    {
        // Match github.event interpolation inside multiline run blocks.
        $matches = preg_match('/\$\{\{\s*github\.event\./', $line);

        // A match means this block-scalar line carries untrusted event text into the shell.
        return $matches === 1;
    }

    /**
     * Returns the number of leading spaces on a line.
     *
     * @param string $line - One YAML line whose leading-space count defines its indentation level.
     *
     * @return int - Number of leading spaces.
     */
    private function indent(string $line): int
    {
        $trimmedLine = ltrim($line, ' ');

        // The leading-space count is the original length minus the length once those spaces are stripped.
        return strlen($line) - strlen($trimmedLine);
    }

    /**
     * Returns the sink for a risky uses: action reference, or null when it is pinned.
     *
     * @param string $line - One YAML line that may declare a uses: step referencing an action.
     *
     * @return string|null - Sink name for a risky uses: reference, or null when the action is safely pinned.
     */
    private function riskyUsesFinding(string $line): ?string
    {
        // Match third-party action references in uses: steps, including list items.
        if (preg_match('/^\s*(?:-\s*)?uses:\s*[\'"]?(?<target>[^\'"\s#]+)[\'"]?/', $line, $matches) !== 1) {
            // Not a uses: step at all, so there is no action reference to judge.
            return null;
        }

        $target = $matches['target'];
        if (str_starts_with($target, './') || str_starts_with($target, 'docker://')) {
            // Out of scope for Git-ref pinning: ./ is an in-repo composite action, and docker:// names a
            // container image whose tag this rule does not evaluate, so a floating image tag is not flagged.
            return null;
        }

        $lastAt = strrpos($target, '@');
        if ($lastAt === false) {
            // A reference with no @ref is unpinned and resolves to a moving default branch.
            return 'action-missing-ref';
        }

        $ref = strtolower(substr($target, $lastAt + 1));
        if (in_array($ref, ['dev', 'head', 'latest', 'main', 'master', 'trunk'], true)) {
            // A branch- or tag-like ref can move under the action's owner, so treat it as floating.
            return 'action-floating-ref';
        }

        // A concrete ref (commit SHA or version tag) is acceptable, so report no sink.
        return null;
    }

    /**
     * Returns the sink for a broad token-permission grant, or null when scoped.
     *
     * @param string $line - One YAML line that may grant token permissions at workflow or job scope.
     *
     * @return string|null - Sink name for a broad write-permission grant, or null when the grant is scoped.
     */
    private function riskyPermissionFinding(string $line): ?string
    {
        // Match top-level or job-level permission blocks set to write-all.
        if (preg_match('/^\s*permissions:\s*write-all\s*(?:#.*)?$/', $line) === 1) {
            // write-all grants the token every scope, the broadest blast radius, so flag it distinctly.
            return 'permissions-write-all';
        }

        $permissionAlternation = implode('|', array_map(static fn (string $permission): string => preg_quote($permission, '/'), self::WRITE_PERMISSIONS));
        // Match broad write permission grants while leaving security-events: write clean.
        if (preg_match('/^\s*(?:' . $permissionAlternation . '):\s*write\s*(?:#.*)?$/', $line) === 1) {
            // A write grant on one of the sensitive scopes is broader than needed, so report it.
            return 'broad-write-permission';
        }

        // The line grants nothing risky (or only the allowlisted security-events scope), so report no sink.
        return null;
    }

    /**
     * Builds the workflow finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit under analysis, supplying the display path attached to the finding.
     * @param int          $line - 1-based line where the risky pattern was matched.
     * @param string       $sink - Sink identifier naming the matched pattern; carried into message and metadata.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, int $line, string $sink): Finding
    {
        // Emit a fixed-shape Security warning; the sink drives both the human message and the machine metadata.
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Risky GitHub Actions workflow pattern detected: %s.', $sink),
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Use least-privilege workflow permissions, trusted events, pinned action refs, and avoid interpolating event data into shell.',
            metadata:    [
                'sink' => $sink,
            ],
        );
    }
}
