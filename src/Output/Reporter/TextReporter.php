<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Analysis\SensitiveExclusionSummary;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Mutation\MutationAnalysisResult;

/**
 * Renders the terminal text format for `gruff-php analyse`.
 *
 * Combines finding and file totals with available diagnostic, scoring, baseline, mutation, and branch-review sections.
 * The flat finding list is followed by the exit summary and, for high finding counts, a hint to use `summary`.
 */
final readonly class TextReporter
{
    /**
     * Minimum displayed finding count that adds a `summary` hint to the text report.
     */
    private const OUTPUT_VOLUME_HINT_THRESHOLD = 50;

    /**
     * Builds the whole text report the user sees after a bare gruff-php analyse: header, grade,
     * file counts, every optional section, then the flat finding list and the closing run summary.
     *
     * @param AnalysisReport $report - Run data supplying counts, optional sections, findings, and the exit summary.
     *
     * @return string - Complete text report ending in the platform newline.
     */
    public function render(AnalysisReport $report): string
    {
        $counts = $report->findingCounts();
        $lines  = [sprintf('%s %s analyse', AnalysisReport::TOOL_NAME, $report->toolVersion)];


        if ($report->score !== null) {
            // An unevaluated run has no grade; showing 100.00 would misrepresent it as a perfect score.

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


        if ($report->failureReason !== null) {
            $lines[] = sprintf('  Failed: %s.', $report->failureReason->message());
        }


        if ($report->newFindingsCount !== null) {
            $lines[] = sprintf('  New findings: %d', $report->newFindingsCount);
        }

        $this->appendOutputVolumeHint($lines, $counts['total']);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Makes suppressed findings visible through their counts and configured or contract-provided reasons.
     * Appends the suppression summary supplied by the exclusion audit.
     *
     * @param list<string>   $lines - Output buffer appended in place; untouched when nothing was suppressed.
     * @param AnalysisReport $report - Report whose sensitive-exclusion audit rows are summarised.
     *
     * @return void - Appends one suppression line when the total is nonzero; otherwise leaves the buffer unchanged.
     */
    private function appendSensitiveExclusions(array &$lines, AnalysisReport $report): void
    {
        $suppressionLine = SensitiveExclusionSummary::describeTotal($report->sensitiveExclusions);


        if ($suppressionLine === null) {
            return;
        }

        $lines[] = '';
        $lines[] = $suppressionLine;
    }

    /**
     * Appends a summary command hint when the displayed finding count reaches the volume threshold.
     *
     * @param list<string> $lines - Output buffer; receives the hint at or above the threshold.
     * @param int          $findingCount - Total finding count this report rendered; gates whether the hint appears.
     *
     * @return void - Adds a blank line, explanatory hint, and suggested command when the threshold is reached.
     */
    private function appendOutputVolumeHint(array &$lines, int $findingCount): void
    {

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
     * Lists up to five rules with the largest count reductions and up to five with the largest increases.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when no review is attached.
     * @param AnalysisReport $report - Report whose attached branch-review supplies the per-rule deltas, if any.
     *
     * @return void - Appends a rule-delta section only when the review has nonzero rule changes.
     */
    private function appendRuleDeltas(array &$lines, AnalysisReport $report): void
    {

        if ($report->review === null) {
            return;
        }

        $rows = $report->review->perRuleDelta();
        // The comparison ran but no rule's count moved, so there are no deltas worth their own block.
        if ($rows === []) {
            return;
        }

        $improved  = array_slice(array_filter($rows, static fn (array $ruleDelta): bool => $ruleDelta['net'] < 0), 0, 5);
        $regressed = array_slice(
            array_reverse(array_filter($rows, static fn (array $ruleDelta): bool => $ruleDelta['net'] > 0)),
            0,
            5,
        );


        if ($improved === [] && $regressed === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Rule deltas';


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
     * available score delta, and each newly introduced finding - the "what did my change do" view.
     *
     * @param list<string>   $lines - Output buffer appended in place; left untouched when no review is attached.
     * @param AnalysisReport $report - Report whose attached branch-review supplies the base ref and finding sets.
     *
     * @return void - Appends review totals, an available score delta, and any introduced findings.
     */
    private function appendReview(array &$lines, AnalysisReport $report): void
    {

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


        if ($report->review->deltaScore !== null) {
            $lines[] = sprintf('  Score delta: %+.2f', $report->review->deltaScore);
        }


        if ($report->review->introduced === []) {
            return;
        }

        $lines[] = '  Introduced:';

        foreach ($report->review->introduced as $finding) {
            $location = $finding->filePath;

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
     * @return void - Appends score, diff/filter context, and pillar grades when a score is available.
     */
    private function appendScore(array &$lines, AnalysisReport $report): void
    {

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
     * @param list<string>   $lines - Output buffer appended in place; left untouched when the report has no baseline.
     * @param AnalysisReport $report - Report supplying baseline movement counts and the stale-entry resolution flag.
     *
     * @return void - Appends baseline details and tips; generated baselines stop before resolved-entry listing.
     */
    private function appendBaseline(array &$lines, AnalysisReport $report): void
    {

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

        // A fresh --generate-baseline run just wrote the file, so tell the user to commit it and rerun to apply it.
        if ($report->baseline->generated) {
            $lines[] = sprintf(
                '  Tip: commit %s and rerun `gruff-php analyse` to apply it; pass --baseline %s for explicit application.',
                $report->baseline->path,
                $report->baseline->path,
            );

            return;
        }

        // Stale entries include surplus reviewed occurrences, even when the same identity still has live findings.
        if ($report->baseline->staleEntries !== []) {
            $lines[] = sprintf(
                '  Tip: %d stale baseline entries no longer match a finding. Regenerate with `gruff-php analyse --generate-baseline %s` after reviewing the diff.',
                count($report->baseline->staleEntries),
                $report->baseline->path,
            );
        }


        if ($report->shouldListAbsentBaseline && $report->baseline->staleEntries !== []) {
            $lines[] = '  Resolved entries:';

            foreach ($report->baseline->staleEntries as $resolvedEntry) {
                $lines[] = sprintf(
                    '    %s %s (resolved %d): %s',
                    $resolvedEntry->ruleId,
                    $resolvedEntry->path,
                    $resolvedEntry->count,
                    $resolvedEntry->subject,
                );
            }
        }
    }

    /**
     * Prints the Mutation block when a mutation run is in scope: the MSI scores, surviving-mutant
     * counts, status breakdown, any baseline delta and budget verdict, and a per-file table.
     *
     * @param list<string> $lines - Output buffer appended in place.
     * @param MutationAnalysisResult|null $mutation - Result to render; null leaves the buffer unchanged.
     *
     * @return void - Appends mutation totals and available context, comparison, budget, and file details.
     */
    private function appendMutation(array &$lines, ?MutationAnalysisResult $mutation): void
    {

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


        if (($mutation->report->statusCounts()['escaped'] ?? 0) > 0 || ($mutation->report->statusCounts()['timed out'] ?? 0) > 0) {
            $lines[] = '  Survived status note: escaped mutants are test gaps; timed-out mutants exceeded Infection timeout and are tracked separately.';
        }

        $contextStatuses = $this->mutationContextSummary($mutation->report->statusCounts());
        // Context-only statuses explain why some reported mutants do not create survived-mutant findings.
        if ($contextStatuses !== null) {
            $lines[] = sprintf(
                '  Context-only statuses: %s. These do not create mutation.survived-mutant findings.',
                $contextStatuses,
            );
        }

        $baselineDelta = $mutation->msiDelta();

        if ($mutation->baselineReport !== null && $baselineDelta !== null) {
            $lines[] = sprintf(
                '  Baseline: %.2f%% (%+.2f points)',
                $mutation->baselineReport->msi(),
                $baselineDelta,
            );
        }


        if ($mutation->mutationBudget !== null) {
            $status  = $mutation->isBudgetExceeded() ? 'exceeded' : 'within budget';
            $lines[] = sprintf('  Budget: %d survived mutants allowed (%s)', $mutation->mutationBudget, $status);
        }

        $fileSummaries = $mutation->report->fileSummaries();

        if ($fileSummaries === []) {
            return;
        }

        $lines[] = '  Files:';

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
     * Flattens the mutation status tallies into one compact killed=…, escaped=… line for the
     * Mutation block's Statuses row.
     *
     * @param array<string, int> $counts - Status counts to join in their existing iteration order.
     *
     * @return string - Comma-separated label=count entries, or none when no counts are supplied.
     */
    private function mutationStatusSummary(array $counts): string
    {

        if ($counts === []) {
            return 'none';
        }

        $parts = [];

        foreach ($counts as $status => $count) {
            $parts[] = sprintf('%s=%d', $status, $count);
        }

        return implode(', ', $parts);
    }

    /**
     * Pulls out only the context-only mutation statuses (not covered, error, syntax error, ignored, and skipped) for their
     * own line, so the user can distinguish them from survived-mutant findings.
     *
     * @param array<string, int> $counts - Mutation status counts keyed by status label; only context-only statuses are emitted.
     *
     * @return string|null - Positive context-only counts as label=count entries; null when none are positive.
     */
    private function mutationContextSummary(array $counts): ?string
    {
        $parts = [];

        foreach (['not covered', 'error', 'syntax error', 'ignored', 'skipped'] as $status) {
            $count = $counts[$status] ?? 0;
            if ($count > 0) {
                $parts[] = sprintf('%s=%d', $status, $count);
            }
        }


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
     * @return void - Appends a heading and one line per path, or leaves the buffer unchanged for an empty list.
     */
    private function appendPathSection(array &$lines, string $title, array $paths): void
    {

        if ($paths === []) {
            return;
        }

        $lines[] = '';
        $lines[] = $title;


        foreach ($paths as $path) {
            $lines[] = sprintf('  %s', $path);
        }
    }

    /**
     * Renders run diagnostics, using a file location when known and otherwise the supplied path.
     * A diagnostic without either location still renders its type and message.
     *
     * @param list<string>        $lines - Output buffer appended in place after the Diagnostics heading.
     * @param list<RunDiagnostic> $diagnostics - Run diagnostics to render; empty suppresses the whole section.
     *
     * @return void - Appends the Diagnostics section when the supplied list is nonempty.
     */
    private function appendDiagnostics(array &$lines, array $diagnostics): void
    {

        if ($diagnostics === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Diagnostics';


        foreach ($diagnostics as $diagnostic) {
            $location = $diagnostic->filePath;


            if ($location !== null && $diagnostic->line !== null) {
                $location .= sprintf(':%d', $diagnostic->line);
            }


            if ($location === null) {
                $location = $diagnostic->path;
            }

            $prefix  = strtoupper(str_replace('-', '-', $diagnostic->type));

            $lines[] = $location === null
                ? sprintf('  [%s] %s', $prefix, $diagnostic->message)
                : sprintf('  [%s] %s %s', $prefix, $location, $diagnostic->message);
        }
    }

    /**
     * Appends the displayed findings with severity, location, rule, and message.
     * An empty displayed set emits None, which can also result from display filtering.
     *
     * @param list<string>  $lines    - Output buffer appended in place after the Findings heading.
     * @param list<Finding> $findings - Findings to render; empty emits the explicit None line.
     *
     * @return void - Appends the Findings section, including None when the supplied list is empty.
     */
    private function appendFindings(array &$lines, array $findings): void
    {
        $lines[] = '';
        $lines[] = 'Findings';


        if ($findings === []) {
            $lines[] = '  None';
            return;
        }


        foreach ($findings as $finding) {
            $location = $finding->filePath;


            if ($finding->line !== null) {
                $location .= sprintf(':%d', $finding->line);
            }

            // FAMILY-CONTRACT.md requires each text finding to include severity, location, rule ID, and message in the shared order.



            $lines[] = sprintf('- [%s] %s %s - %s', $finding->severity->value, $location, $finding->ruleId, $finding->message);
        }
    }
}
