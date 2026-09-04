<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Analysis\SensitiveExclusionSummary;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Mutation\MutationAnalysisResult;

/**
 * Renders the default terminal report a user reads after `gruff-php analyse`.
 *
 * This is the human-facing text format - everything except `--format json`. It stitches one
 * run into a single scrollable page: the headline grade and finding tallies, the file and
 * parse counts, then any diagnostics, rule deltas, score breakdown, baseline movement,
 * mutation results, and branch-review, before the flat per-finding list at the end. Sections
 * with nothing to show stay silent, so a clean run stays short, and a run with too many
 * findings to read flat closes with a nudge toward the rule-grouped `summary` command.
 */
final readonly class TextReporter
{
    /**
     * Total-finding floor above which the flat per-finding list is no longer
     * browsable and the footer hint suggests `summary` instead. Chosen at the
     * empirical break-point where consumers report needing to write a Python
     * summariser to triage; revisit if telemetry signals a different number.
     */
    private const OUTPUT_VOLUME_HINT_THRESHOLD = 50;

    /**
     * Builds the whole text report the user sees after a bare `gruff-php analyse`: header, grade,
     * file counts, every optional section, then the flat finding list and the closing exit code.
     *
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return string - Text report with summary, diagnostics, and findings.
     */
    public function render(AnalysisReport $report): string
    {
        $counts = $report->findingCounts();
        $lines  = [sprintf('%s %s analyse', AnalysisReport::TOOL_NAME, $report->toolVersion)];

        // Lead with the composite grade, but only when scoring ran; a metadata-only run has none to show.
        if ($report->score !== null) {
            // A run that evaluated nothing has no composite: printing 100.00 is what let an empty
            // directory read as perfect before the M06 break.
            $lines[] = $report->score->composite === null
                ? 'Composite: n/a (nothing evaluated)'
                : sprintf('Composite: %s (%.2f / 100)', $report->score->composite->letter, $report->score->composite->score);
        }

        $lines[] = sprintf(
            'Findings: %d total · %d error · %d warning · %d advisory',
            $counts['total'],
            $counts['error'],
            $counts['warning'],
            $counts['advisory'],
        );
        $lines[] = sprintf('Format: %s', $report->format);
        $lines[] = sprintf('Fail threshold: %s', $report->failOn);
        $lines[] = '';
        $lines[] = 'Files';
        $lines[] = sprintf('  Discovered: %d', $report->filesDiscovered);
        $lines[] = sprintf('  Parsed: %d', $report->filesParsed);
        $lines[] = sprintf('  Ignored: %d', count($report->ignoredPaths));
        $lines[] = sprintf('  Missing: %d', count($report->missingPaths));
        $lines[] = sprintf('  Parse errors: %d', $report->parseErrorCount());

        $this->appendPathSection($lines, 'Ignored paths', $report->ignoredPaths);
        $this->appendPathSection($lines, 'Missing paths', $report->missingPaths);
        $this->appendDiagnostics($lines, $report->diagnostics);
        $this->appendRuleDeltas($lines, $report);
        $this->appendScore($lines, $report);
        $this->appendBaseline($lines, $report);
        $this->appendSensitiveExclusions($lines, $report);
        $this->appendMutation($lines, $report->mutation);
        $this->appendReview($lines, $report);
        $this->appendFindings($lines, $report->findings);

        $lines[] = '';
        $lines[] = 'Summary';
        $lines[] = sprintf('  Exit code: %d', $report->exitCode);

        // Name why the run failed (a crossed severity threshold, say) so the exit code isn't a mystery.
        if ($report->failureReason !== null) {
            $lines[] = sprintf('  Failed: %s.', $report->failureReason->message());
        }

        // A new-findings gate is active (a `--diff-vs` review or an applied baseline), so surface how many findings are new.
        if ($report->newFindingsCount !== null) {
            $lines[] = sprintf('  New findings: %d', $report->newFindingsCount);
        }

        $this->appendOutputVolumeHint($lines, $counts['total']);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Surfaces what the configured `sensitiveExclusions:` entries hid on this run, so a suppressed
     * sensitive-data finding is a visible number with a rationale beside it rather than an absence
     * the reader has to notice. Nothing here comes from a matched value: the rule id, the count,
     * and the reason are all configuration.
     *
     * @param list<string>   $lines - Output buffer appended in place; untouched when nothing was suppressed.
     * @param AnalysisReport $report - Report whose sensitive-exclusion audit rows are summarised.
     *
     * @return void
     */
    private function appendSensitiveExclusions(array &$lines, AnalysisReport $report): void
    {
        $suppressionLine = SensitiveExclusionSummary::describeTotal($report->sensitiveExclusions);

        // With nothing suppressed there is no absence to explain, so the report stays as it was.
        if ($suppressionLine === null) {
            return;
        }

        $lines[] = '';
        $lines[] = $suppressionLine;
    }

    /**
     * Closes an overwhelming report by pointing the user at `summary`, since a flat per-finding
     * list past the volume floor stops being browsable and people resort to their own summariser.
     *
     * @param list<string> $lines - Output buffer appended in place; the hint is added only past the floor.
     * @param int          $findingCount - Total finding count this report rendered; gates whether the hint appears.
     *
     * @return void
     */
    private function appendOutputVolumeHint(array &$lines, int $findingCount): void
    {
        // Under the floor the flat list is still readable, so leave the report alone and add no nudge.
        if ($findingCount < self::OUTPUT_VOLUME_HINT_THRESHOLD) {
            return;
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Hint: %d findings is a lot to read flat. For a rule-grouped overview, try:',
            $findingCount,
        );
        $lines[] = '  php bin/gruff-php summary <paths>';
    }

    /**
     * Shows the five rules that improved most and the five that regressed most against the base
     * branch, so a single composite delta can't hide which checks actually moved under it.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when no review is attached.
     * @param AnalysisReport $report - Report whose attached branch-review supplies the per-rule deltas, if any.
     *
     * @return void
     */
    private function appendRuleDeltas(array &$lines, AnalysisReport $report): void
    {
        // Rule deltas only exist against a base branch; with no review attached there is nothing to compare.
        if ($report->review === null) {
            return;
        }

        $rows = $report->review->perRuleDelta();
        // The comparison ran but no rule's count moved, so there are no deltas worth their own block.
        if ($rows === []) {
            return;
        }

        // Split the moved rules: a negative net is a win (fewer findings), a positive net a regression; keep five of each.
        $improved  = array_slice(array_filter($rows, static fn (array $ruleDelta): bool => $ruleDelta['net'] < 0), 0, 5);
        $regressed = array_slice(
            array_reverse(array_filter($rows, static fn (array $ruleDelta): bool => $ruleDelta['net'] > 0)),
            0,
            5,
        );

        // If nothing netted either way, there's no progress or regression to headline, so skip the block.
        if ($improved === [] && $regressed === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Rule deltas';

        // When any rule improved, list the biggest wins first so the user sees progress since the base.
        if ($improved !== []) {
            $lines[] = sprintf(
                '  Top %d improved: %s',
                count($improved),
                implode(', ', array_map(
                    static fn (array $ruleDelta): string => sprintf('%d %s', $ruleDelta['net'], $ruleDelta['ruleId']),
                    $improved,
                )),
            );
        }

        // When any rule got worse, list the sharpest regressions so freshly added debt is easy to spot.
        if ($regressed !== []) {
            $lines[] = sprintf(
                '  Top %d regressed: %s',
                count($regressed),
                implode(', ', array_map(
                    static fn (array $ruleDelta): string => sprintf('+%d %s', $ruleDelta['net'], $ruleDelta['ruleId']),
                    $regressed,
                )),
            );
        }
    }

    /**
     * Prints the branch-review block: the base ref, the introduced/removed/unchanged tallies, the
     * score delta, and each newly introduced finding - the "what did my change do" view.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when no review is attached.
     * @param AnalysisReport $report - Report whose attached branch-review supplies the base ref and finding sets.
     *
     * @return void
     */
    private function appendReview(array &$lines, AnalysisReport $report): void
    {
        // Nothing to review unless the user compared against a base branch, so skip the whole block otherwise.
        if ($report->review === null) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Branch review';
        $lines[] = sprintf('  Base: %s', $report->review->base);
        $lines[] = sprintf('  Changed only: %s', $report->review->isChangedOnly ? 'yes' : 'no');
        $lines[] = sprintf(
            '  Findings: %d introduced, %d removed, %d unchanged',
            count($report->review->introduced),
            count($report->review->removed),
            count($report->review->unchanged),
        );

        // Show how the grade moved only when both sides were scored; a missing delta means it couldn't be computed.
        if ($report->review->deltaScore !== null) {
            $lines[] = sprintf('  Score delta: %+.2f', $report->review->deltaScore);
        }

        // If the change introduced no new findings, stop here - the good-news case needs no itemised list.
        if ($report->review->introduced === []) {
            return;
        }

        $lines[] = '  Introduced:';
        // Spell out each finding the change added, so the user can see exactly what regressed and where.
        foreach ($report->review->introduced as $finding) {
            $location = $finding->filePath;
            // Each introduced finding gets a `:line` suffix when it has a line; a file-level one shows just the path.
            if ($finding->line !== null) {
                $location .= sprintf(':%d', $finding->line);
            }

            $lines[] = sprintf('    [%s] %s %s', $finding->severity->value, $finding->ruleId, $location);
            $lines[] = sprintf('      %s', $finding->message);
        }
    }

    /**
     * Prints the Score block a user scans to judge overall health: scope, the drivers behind the
     * grade, any diff context, then a per-pillar grade line for naming, complexity, security, and so on.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when the report has no score.
     * @param AnalysisReport $report - Report supplying the composite score, per-pillar grades, and diff context.
     *
     * @return void
     */
    private function appendScore(array &$lines, AnalysisReport $report): void
    {
        // No score means scoring was disabled or nothing was scannable, so skip the block entirely.
        if ($report->score === null) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Score';
        $lines[] = sprintf('  Scope: %s', $report->score->scope);
        $lines[] = sprintf('  Score drivers: %s', $report->score->explanation);

        // When the run was scoped to a diff, note the mode and changed-file count so the grade's reach is clear.
        if ($report->diff !== null && $report->diff->active) {
            $lines[] = sprintf(
                '  Diff: %s, %d changed files',
                $report->diff->mode,
                count($report->diff->changedFiles),
            );
            $lines[] = sprintf('  Diff note: %s', $report->diff->message);
        }

        // If display filters are hiding findings, warn that the grade still reflects the full scored set, not the trimmed view.
        if ($report->filters !== null && $report->filters->isActive()) {
            $lines[] = '  Display filters: score and exit code use the scored finding set; filters only change rendered findings.';
        }

        $lines[] = '  Pillars:';
        // One grade line per pillar - the breakdown the user reads to see which quality dimension is dragging.
        foreach ($report->score->pillars as $pillar) {
            // A pillar with no applicable rules has no grade, so show "n/a" rather than a misleading 0.00.
            $grade   = $pillar->grade === null ? 'n/a' : $pillar->grade->letter;
            $score   = $pillar->grade === null ? 'n/a' : sprintf('%.2f', $pillar->grade->score);
            $lines[] = sprintf(
                '    %s: %s (%s) findings=%d',
                $pillar->pillar,
                $grade,
                $score,
                $pillar->findings,
            );
        }
    }

    /**
     * Prints the Baseline block: where the accepted-debt file lives, how many findings it
     * suppressed this run, how the recorded set moved, and tips for committing or refreshing it.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when no baseline was applied.
     * @param AnalysisReport $report - Report supplying baseline movement counts and the stale-entry resolution flag.
     *
     * @return void
     */
    private function appendBaseline(array &$lines, AnalysisReport $report): void
    {
        // Only projects using a baseline get this section; without one there is no accepted debt to report.
        if ($report->baseline === null) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Baseline';
        $lines[] = sprintf('  Path: %s', $report->baseline->path);
        $lines[] = sprintf('  Source: %s', $report->baseline->source);
        $lines[] = sprintf('  Entries: %d', $report->baseline->totalEntries);
        $lines[] = sprintf('  Generated: %s', $report->baseline->generated ? 'yes' : 'no');
        $lines[] = sprintf('  Suppressed findings: %d', $report->baseline->suppressedFindings);
        $lines[] = sprintf(
            '  Movement: %d new, %d unchanged, %d resolved',
            $report->baseline->newCount,
            $report->baseline->unchangedCount,
            $report->baseline->absentCount,
        );
        $lines[] = sprintf('  Stale evaluation: %s', $report->baseline->staleEvaluation);
        $lines[] = sprintf('  Stale entries: %d', count($report->baseline->staleEntries));
        $lines[] = '  Note: suppressed findings are accepted debt and are removed before scoring.';

        // A fresh `--generate-baseline` run just wrote the file, so tell the user to commit it and rerun to apply it.
        if ($report->baseline->generated) {
            $lines[] = sprintf(
                '  Tip: commit %s and rerun `gruff-php analyse` to apply it; pass --baseline %s for explicit application.',
                $report->baseline->path,
                $report->baseline->path,
            );

            return;
        }

        // Some recorded entries no longer match any finding, so nudge the user to regenerate and prune that dead debt.
        if ($report->baseline->staleEntries !== []) {
            $lines[] = sprintf(
                '  Tip: %d stale baseline entries no longer match a finding. Regenerate with `gruff-php analyse --generate-baseline %s` after reviewing the diff.',
                count($report->baseline->staleEntries),
                $report->baseline->path,
            );
        }

        // Only when the user asked to see resolved debt (and some exists) do we itemise what they've cleared.
        if ($report->shouldListAbsentBaseline && $report->baseline->staleEntries !== []) {
            $lines[] = '  Resolved entries:';
            // One line per fixed group so the user can see exactly which accepted debt they cleared.
            foreach ($report->baseline->staleEntries as $resolvedEntry) {
                $lines[] = sprintf(
                    '    %s %s (resolved %d): %s',
                    $resolvedEntry->ruleId,
                    $resolvedEntry->filePath,
                    $resolvedEntry->count,
                    $resolvedEntry->message,
                );
            }
        }
    }

    /**
     * Prints the Mutation block when a mutation run is in scope: the MSI scores, surviving-mutant
     * counts, status breakdown, any baseline delta and budget verdict, and a per-file table.
     *
     * @param list<string>                $lines - Output buffer appended in place.
     * @param MutationAnalysisResult|null $mutation - Mutation-testing result, or null when no mutation run is in scope
     *                                              (null produces no Mutation section at all).
     *
     * @return void
     */
    private function appendMutation(array &$lines, ?MutationAnalysisResult $mutation): void
    {
        // No mutation result means the user didn't run mutation testing this time, so skip the whole section.
        if (!$mutation instanceof MutationAnalysisResult) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Mutation';
        $lines[] = sprintf('  Source: %s', $mutation->report->reportPath);
        $lines[] = sprintf(
            '  MSI: %.2f%% | Covered MSI: %.2f%% | Mutation coverage: %.2f%%',
            $mutation->report->msi(),
            $mutation->report->coveredMsi(),
            $mutation->report->coverageRate(),
        );
        $lines[] = sprintf(
            '  Mutants: %d total, %d survived',
            $mutation->report->totalMutants(),
            $mutation->survivedCount(),
        );
        $lines[] = sprintf('  Statuses: %s', $this->mutationStatusSummary($mutation->report->statusCounts()));

        // Escaped or timed-out mutants each need a caveat, so add the note explaining what those two statuses mean.
        if (($mutation->report->statusCounts()['escaped'] ?? 0) > 0 || ($mutation->report->statusCounts()['timed out'] ?? 0) > 0) {
            $lines[] = '  Survived status note: escaped mutants are test gaps; timed-out mutants exceeded Infection timeout and are tracked separately.';
        }

        $contextStatuses = $this->mutationContextSummary($mutation->report->statusCounts());
        // Some statuses are context-only (not covered, error, syntax error, ignored, skipped); call them out so their absence from findings makes sense.
        if ($contextStatuses !== null) {
            $lines[] = sprintf(
                '  Context-only statuses: %s. These do not create mutation.survived-mutant findings.',
                $contextStatuses,
            );
        }

        $baselineDelta = $mutation->msiDelta();
        // When there's a prior mutation baseline to compare against, show how the MSI moved since it.
        if ($mutation->baselineReport !== null && $baselineDelta !== null) {
            $lines[] = sprintf(
                '  Baseline: %.2f%% (%+.2f points)',
                $mutation->baselineReport->msi(),
                $baselineDelta,
            );
        }

        // If the user set a survived-mutant budget, report whether this run stayed within it or blew past.
        if ($mutation->mutationBudget !== null) {
            $status  = $mutation->isBudgetExceeded() ? 'exceeded' : 'within budget';
            $lines[] = sprintf('  Budget: %d survived mutants allowed (%s)', $mutation->mutationBudget, $status);
        }

        $fileSummaries = $mutation->report->fileSummaries();
        // Per-file mutation data may be absent (a summary-only report); when it is, end without a Files table.
        if ($fileSummaries === []) {
            return;
        }

        $lines[] = '  Files:';
        // One row per file with its MSI and survivor counts, so the user can see which files are tested weakest.
        foreach ($fileSummaries as $summary) {
            $lines[] = sprintf(
                '    %s: MSI %.2f%%, Covered MSI %.2f%%, survived %d/%d, not covered %d',
                $summary->filePath,
                $summary->msi,
                $summary->coveredMsi,
                $summary->survivedMutants,
                $summary->totalMutants,
                $summary->notCoveredMutants,
            );
        }
    }

    /**
     * Flattens the mutation status tallies into one compact `killed=…, escaped=…` line for the
     * Mutation block's Statuses row.
     *
     * @param array<string, int> $counts - Mutation status counts keyed by status label; empty means no mutants ran.
     *
     * @return string - The statuses as a `label=count` list, or the literal "none" when no mutants ran.
     */
    private function mutationStatusSummary(array $counts): string
    {
        // No counts at all means no mutants ran, so say "none" rather than emit a blank status line.
        if ($counts === []) {
            return 'none';
        }

        $parts = [];
        // Turn each status/count pair into a `label=count` token for the joined summary line.
        foreach ($counts as $status => $count) {
            $parts[] = sprintf('%s=%d', $status, $count);
        }

        return implode(', ', $parts);
    }

    /**
     * Pulls out only the context-only mutation statuses (not covered, errored, skipped) for their
     * own line, so the user doesn't misread them as real test-gap findings.
     *
     * @param array<string, int> $counts - Mutation status counts keyed by status label; only context-only statuses are emitted.
     *
     * @return string|null - The context-only statuses as a `label=count` list, or null when none are present so no line is printed.
     */
    private function mutationContextSummary(array $counts): ?string
    {
        $parts = [];

        // Walk the fixed set of context-only statuses so they always appear in the same, predictable order.
        foreach (['not covered', 'error', 'syntax error', 'ignored', 'skipped'] as $status) {
            $count = $counts[$status] ?? 0;
            // Only include a status the user actually hit; a zero count would just add noise to the line.
            if ($count > 0) {
                $parts[] = sprintf('%s=%d', $status, $count);
            }
        }

        // No context-only statuses fired, so return null and let the caller print nothing for them.
        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Prints a titled list of paths - used for the Ignored and Missing sections - or nothing at all
     * when the list is empty, so a clean run never shows a hollow heading.
     *
     * @param list<string> $lines - Output buffer appended in place; left untouched when $paths is empty.
     * @param string       $title - Section heading printed once above the paths (for example "Ignored paths").
     * @param list<string> $paths - Paths to list under the heading; an empty list suppresses the whole section.
     *
     * @return void
     */
    private function appendPathSection(array &$lines, string $title, array $paths): void
    {
        // With no paths to show, skip the heading too; an empty "Ignored paths" section would only confuse.
        if ($paths === []) {
            return;
        }

        $lines[] = '';
        $lines[] = $title;

        // One indented line per path so the user can see exactly which files were skipped or unreachable.
        foreach ($paths as $path) {
            $lines[] = sprintf('  %s', $path);
        }
    }

    /**
     * Prints the Diagnostics block - parse errors and other per-file notes from the run - so the
     * user knows which files were skipped or had trouble, not silently dropped.
     *
     * @param list<string>        $lines - Output buffer appended in place after the Diagnostics heading.
     * @param list<RunDiagnostic> $diagnostics - Run diagnostics to render; empty suppresses the whole section.
     *
     * @return void
     */
    private function appendDiagnostics(array &$lines, array $diagnostics): void
    {
        // A clean run produces no diagnostics, so skip the section rather than print an empty heading.
        if ($diagnostics === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Diagnostics';

        // Render each diagnostic with its location so the user can jump straight to the file that had trouble.
        foreach ($diagnostics as $diagnostic) {
            $location = $diagnostic->filePath;

            // When both a file and a line are known, pin the note to `file:line` for a precise jump target.
            if ($location !== null && $diagnostic->line !== null) {
                $location .= sprintf(':%d', $diagnostic->line);
            }

            // Some diagnostics aren't tied to a resolved file, so fall back to the raw path the user passed.
            if ($location === null) {
                $location = $diagnostic->path;
            }

            $prefix  = strtoupper(str_replace('-', '-', $diagnostic->type));
            // With no location at all, print just the prefixed message; otherwise lead with where it happened.
            $lines[] = $location === null
                ? sprintf('  [%s] %s', $prefix, $diagnostic->message)
                : sprintf('  [%s] %s %s', $prefix, $location, $diagnostic->message);
        }
    }

    /**
     * Prints the Findings block - the flat list every run ends on - with one entry per finding, or
     * an explicit "None" line when the code came back clean.
     *
     * @param list<string>  $lines - Output buffer appended in place after the Findings heading.
     * @param list<Finding> $findings - Findings to render; empty emits the explicit "none" line.
     *
     * @return void
     */
    private function appendFindings(array &$lines, array $findings): void
    {
        $lines[] = '';
        $lines[] = 'Findings';

        // A clean scan still prints the heading, so state "None" outright rather than leave the user guessing.
        if ($findings === []) {
            $lines[] = '  None';
            return;
        }

        // Render every finding as severity, rule, location, and message - the core list the user acts on.
        foreach ($findings as $finding) {
            $location = $finding->filePath;

            // Append the line number when the finding has one; file-level findings show just the path.
            if ($finding->line !== null) {
                $location .= sprintf(':%d', $finding->line);
            }

            // FAMILY-CONTRACT section 1 made the rs/ts dash-line the family canon at this break:
            // `- [severity] file:line ruleId - message`. gruff-php emitted a three-line block, so the
            // same finding took three lines here and one line in two sibling ports, and no editor
            // jump-to-location convention matched.
            $lines[] = sprintf('- [%s] %s %s - %s', $finding->severity->value, $location, $finding->ruleId, $finding->message);
        }
    }
}
