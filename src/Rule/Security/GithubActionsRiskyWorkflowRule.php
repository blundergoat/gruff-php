<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\SourceTextRuleInterface;

/**
 * Detects risky GitHub Actions workflow patterns in source text.
 */
final class GithubActionsRiskyWorkflowRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for risky GitHub Actions workflow patterns.
     */
    public const ID = 'security.github-actions-risky-workflow';

    /**
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
     * Describe the risky GitHub Actions workflow rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * Find risky patterns in GitHub Actions workflow YAML.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for risky workflow patterns.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!$this->isWorkflowFile($analysisUnit->file->displayPath)) {
            return [];
        }

        $findings             = [];
        $lines                = preg_split('/\R/', $analysisUnit->source);
        $hasPullRequestEvent  = $this->hasPullRequestEvent($analysisUnit->source);
        $runBlockIndent       = null;
        $reportedRunBlockLine = null;

        foreach ($lines === false ? [] : $lines as $index => $line) {
            $lineNumber = $index + 1;
            $sinks      = $this->lineFindingSinks($line, $hasPullRequestEvent, $runBlockIndent, $reportedRunBlockLine);

            foreach ($sinks as $sink) {
                $findings[] = $this->finding($analysisUnit, $lineNumber, $sink);
            }
        }

        return $findings;
    }

    /**
     * @return bool True when the display path is a GitHub Actions workflow YAML file.
     */
    private function isWorkflowFile(string $displayPath): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        // Match only GitHub Actions workflow YAML files, even when nested under fixtures.
        return preg_match('#(^|/)\.github/workflows/[^/]+\.(?:ya?ml)$#', $normalizedPath) === 1;
    }

    /**
     * @param int|null $runBlockIndent       Current block scalar run indentation, updated in place.
     * @param int|null $reportedRunBlockLine First reported run-block interpolation line, updated in place.
     * @return list<string> Finding sinks detected on the line.
     */
    private function lineFindingSinks(
        string $line,
        bool $hasPullRequestEvent,
        ?int &$runBlockIndent,
        ?int &$reportedRunBlockLine,
    ): array {
        $this->closeRunBlockWhenDedented($line, $runBlockIndent, $reportedRunBlockLine);

        $sinks = [];
        foreach ($this->directLineSinks($line, $hasPullRequestEvent) as $sink) {
            $sinks[] = $sink;
        }

        if ($this->isRunBlockStart($line)) {
            $runBlockIndent = $this->indent($line);
        }

        if ($runBlockIndent !== null && $reportedRunBlockLine === null && $this->hasGithubEventInterpolation($line)) {
            $reportedRunBlockLine = 1;
            $sinks[]              = 'github-event-run-interpolation';
        }

        return $sinks;
    }

    /**
     * @return list<string> Finding sinks that do not need multiline run-block state.
     */
    private function directLineSinks(string $line, bool $hasPullRequestEvent): array
    {
        $sinks = [];

        if ($this->isPullRequestTargetTrigger($line)) {
            $sinks[] = 'pull_request_target';
        }

        foreach ([$this->riskyUsesFinding($line), $this->riskyPermissionFinding($line)] as $sink) {
            if ($sink !== null) {
                $sinks[] = $sink;
            }
        }

        if ($hasPullRequestEvent && str_contains($line, '${{ secrets.')) {
            $sinks[] = 'secrets-in-pr-workflow';
        }

        if ($this->hasInlineGithubEventRun($line)) {
            $sinks[] = 'github-event-run-interpolation';
        }

        return $sinks;
    }

    /**
     * Reset run-block state once a non-empty line dedents back to the parent level.
     *
     * @return void
     */
    private function closeRunBlockWhenDedented(string $line, ?int &$runBlockIndent, ?int &$reportedRunBlockLine): void
    {
        if ($runBlockIndent === null || trim($line) === '' || $this->indent($line) > $runBlockIndent) {
            return;
        }

        $runBlockIndent       = null;
        $reportedRunBlockLine = null;
    }

    /**
     * @return bool True when the workflow listens to pull_request or pull_request_target.
     */
    private function hasPullRequestEvent(string $source): bool
    {
        // Match mapping-style pull_request or pull_request_target triggers.
        $mappingTrigger = preg_match('/^\s*pull_request(?:_target)?\s*:/m', $source) === 1;
        // Match list-style pull_request or pull_request_target triggers.
        $listTrigger = preg_match('/^\s*-\s*pull_request(?:_target)?\s*(?:#.*)?$/m', $source) === 1;

        return $mappingTrigger || $listTrigger;
    }

    /**
     * @return bool True when the line declares a pull_request_target trigger.
     */
    private function isPullRequestTargetTrigger(string $line): bool
    {
        // Match pull_request_target as an event mapping key.
        $matches = preg_match('/^\s*pull_request_target\s*:/', $line);

        return $matches === 1;
    }

    /**
     * @return bool True when the line starts a YAML block-scalar run step.
     */
    private function isRunBlockStart(string $line): bool
    {
        // Match run: | and run: > forms, including list-item steps.
        $matches = preg_match('/^\s*(?:-\s*)?run:\s*(?:\||>)\s*(?:#.*)?$/', $line);

        return $matches === 1;
    }

    /**
     * @return bool True when an inline run step interpolates github.event data.
     */
    private function hasInlineGithubEventRun(string $line): bool
    {
        // Match shell steps where untrusted github.event context is interpolated directly.
        $matches = preg_match('/^\s*(?:-\s*)?run:\s+.*\$\{\{\s*github\.event\./', $line);

        return $matches === 1;
    }

    /**
     * @return bool True when any text contains github.event interpolation.
     */
    private function hasGithubEventInterpolation(string $line): bool
    {
        // Match github.event interpolation inside multiline run blocks.
        $matches = preg_match('/\$\{\{\s*github\.event\./', $line);

        return $matches === 1;
    }

    /**
     * @return int Number of leading spaces.
     */
    private function indent(string $line): int
    {
        $trimmedLine = ltrim($line, ' ');

        return strlen($line) - strlen($trimmedLine);
    }

    /**
     * @return string|null Finding sink for a risky uses: reference.
     */
    private function riskyUsesFinding(string $line): ?string
    {
        // Match third-party action references in uses: steps, including list items.
        if (preg_match('/^\s*(?:-\s*)?uses:\s*[\'"]?(?<target>[^\'"\s#]+)[\'"]?/', $line, $matches) !== 1) {
            return null;
        }

        $target = $matches['target'];
        if (str_starts_with($target, './') || str_starts_with($target, 'docker://')) {
            return null;
        }

        $lastAt = strrpos($target, '@');
        if ($lastAt === false) {
            return 'action-missing-ref';
        }

        $ref = strtolower(substr($target, $lastAt + 1));
        if (in_array($ref, ['dev', 'head', 'latest', 'main', 'master', 'trunk'], true)) {
            return 'action-floating-ref';
        }

        return null;
    }

    /**
     * @return string|null Finding sink for broad write permissions.
     */
    private function riskyPermissionFinding(string $line): ?string
    {
        // Match top-level or job-level permission blocks set to write-all.
        if (preg_match('/^\s*permissions:\s*write-all\s*(?:#.*)?$/', $line) === 1) {
            return 'permissions-write-all';
        }

        $permissionAlternation = implode('|', array_map(static fn (string $permission): string => preg_quote($permission, '/'), self::WRITE_PERMISSIONS));
        // Match broad write permission grants while leaving security-events: write clean.
        if (preg_match('/^\s*(?:' . $permissionAlternation . '):\s*write\s*(?:#.*)?$/', $line) === 1) {
            return 'broad-write-permission';
        }

        return null;
    }

    /**
     * Build the workflow finding.
     *
     * @return Finding Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, int $line, string $sink): Finding
    {
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
