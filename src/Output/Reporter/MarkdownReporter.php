<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Scoring\PillarScore;

/**
 * Turns a finished analysis run into a GitHub-flavoured Markdown document - the report a user
 * gets from `gruff-php analyse --format markdown`. Composes, in order, the headline summary
 * (grade, findings, and baseline or mutation notes), the branch-review section, the per-pillar
 * scores table, and the grouped findings list. Findings sit inside collapsible `<details>` blocks
 * so the output stays skimmable when it lands in a pull-request comment or an editor's Markdown
 * preview. Reach for this format when the verdict needs to travel as a shareable document rather
 * than terminal text (`--format text`) or machine JSON (`--format json`).
 */
final readonly class MarkdownReporter
{
    /**
     * Assembles the full Markdown document a user gets from `--format markdown`, stitching the
     * summary, branch review, pillars, and findings sections together in the order they read.
     *
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return string - the complete Markdown document (summary, branch review, pillars, findings) with a single trailing newline
     */
    public function render(AnalysisReport $report): string
    {
        $lines = [];

        $this->appendSummary($lines, $report);
        $this->appendBranchReviewSection($lines, $report);
        $this->appendPillarSection($lines, $report);
        $this->appendFindingsSection($lines, $report);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Writes the headline block the user reads first: overall grade, scope, and finding totals, then
     * whichever optional notes this run produced - failure reason, new-findings count, baseline,
     * mutation, branch review, score drivers, diff scope, and display filters among them. Each note is
     * emitted only when the run actually generated it.
     *
     * @param list<string>   $lines - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void
     */
    private function appendSummary(array &$lines, AnalysisReport $report): void
    {
        $score  = $report->score;
        $counts = $report->findingCounts();

        array_push(
            $lines,
            '# gruff-php report',
            '',
            sprintf('**Grade:** %s (%s/100)', $score === null ? 'n/a' : $score->composite->letter, $score === null ? 'n/a' : sprintf('%.2f', $score->composite->score)),
            sprintf('**Scope:** %s', $score === null ? 'full-project' : $score->scope),
            sprintf('**Findings:** %d total, %d error, %d warning, %d advisory', $counts['total'], $counts['error'], $counts['warning'], $counts['advisory']),
        );

        // The run tripped a fail-on threshold, so name the reason rather than leave the reader guessing why it failed.
        if ($report->failureReason !== null) {
            $lines[] = sprintf('**Failed:** %s.', $report->failureReason->message());
        }

        // A new-findings gate is active (a `--fail-on-new` run over a baseline or `--diff-vs`), so surface how many findings are genuinely new to act on.
        if ($report->newFindingsCount !== null) {
            $lines[] = sprintf('**New findings:** %d', $report->newFindingsCount);
        }

        // Scoring only produces a grade when there were files to grade; when it did, explain what moved the grade.
        if ($score !== null) {
            $lines[] = sprintf('**Score drivers:** %s', $score->explanation);
        }

        // The user narrowed the scan to a diff, so state which slice of code the grade actually covers.
        if ($report->diff !== null && $report->diff->active) {
            $lines[] = sprintf('**Diff scope:** %s', $report->diff->message);
        }

        // Display filters hide some findings from the list, so warn the reader that the score and exit code still use the whole scored finding set, not just what's shown.
        if ($report->filters !== null && $report->filters->isActive()) {
            $lines[] = sprintf(
                '**Display filters:** `%s`; score and exit code use the scored finding set.',
                json_encode($report->filters->toArray(), JSON_UNESCAPED_SLASHES) ?: '{}',
            );
        }

        // A baseline was applied, so report the new/unchanged/resolved split and note that accepted debt is dropped before scoring.
        if ($report->baseline !== null) {
            $lines[] = sprintf(
                '**Baseline:** %d new, %d unchanged, %d resolved (`%s`). Unchanged findings are accepted debt and are removed before scoring.',
                $report->baseline->newCount,
                $report->baseline->unchangedCount,
                $report->baseline->absentCount,
                $report->baseline->path,
            );

            // The user asked to see resolved debt and some exists, so open a collapsed block listing what got fixed.
            if ($report->shouldListAbsentBaseline && $report->baseline->staleEntries !== []) {
                $lines[] = '';
                $lines[] = '<details><summary>Resolved baseline entries</summary>';
                $lines[] = '';
                // One bullet per resolved finding group, listed inside the collapsed block so the PR comment stays tidy.
                foreach ($report->baseline->staleEntries as $resolvedEntry) {
                    $lines[] = sprintf(
                        '- `%s` %s (resolved %d): %s',
                        $resolvedEntry->ruleId,
                        $resolvedEntry->filePath,
                        $resolvedEntry->count,
                        $resolvedEntry->message,
                    );
                }
                $lines[] = '';
                $lines[] = '</details>';
            }
        }

        // Mutation testing ran this time, so hand off to the mutation block for its own summary lines.
        if ($report->mutation !== null) {
            $this->appendMutationSummary($lines, $report);
        }

        // This was a branch review, so headline how many findings the branch introduced, removed, or left unchanged.
        if ($report->review !== null) {
            $lines[] = sprintf(
                '**Branch review:** base `%s`, %d introduced, %d removed, %d unchanged',
                $report->review->base,
                count($report->review->introduced),
                count($report->review->removed),
                count($report->review->unchanged),
            );
            $this->appendRuleDeltas($lines, $report);
        }
    }

    /**
     * Lists the up-to-five rules that improved most and the up-to-five that regressed most between
     * the base branch and the user's branch. Placed just before the pillar scores so a rule-level
     * swing stays visible even when the composite grade barely moves and hides the churn.
     *
     * @param list<string>   $lines - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void
     */
    private function appendRuleDeltas(array &$lines, AnalysisReport $report): void
    {
        // Without a branch review there is no base-vs-branch comparison, so there are no deltas to surface.
        if ($report->review === null) {
            return;
        }

        $rows = $report->review->perRuleDelta();
        // No rule shifted between the base and the user's branch, so there is nothing to rank here.
        if ($rows === []) {
            return;
        }

        $improved  = array_slice(array_filter($rows, static fn(array $ruleDelta): bool => $ruleDelta['net'] < 0), 0, 5);
        $regressed = array_slice(
            array_reverse(array_filter($rows, static fn(array $ruleDelta): bool => $ruleDelta['net'] > 0)),
            0,
            5,
        );

        // At least one rule got better, so print the improved leaderboard with the biggest drops first.
        if ($improved !== []) {
            $lines[] = sprintf(
                '**Top %d improved:** %s',
                count($improved),
                implode(', ', array_map(
                    static fn(array $ruleDelta): string => sprintf('`%d %s`', $ruleDelta['net'], $ruleDelta['ruleId']),
                    $improved,
                )),
            );
        }

        // At least one rule got worse, so print the regressed leaderboard the reviewer should look at first.
        if ($regressed !== []) {
            $lines[] = sprintf(
                '**Top %d regressed:** %s',
                count($regressed),
                implode(', ', array_map(
                    static fn(array $ruleDelta): string => sprintf('`+%d %s`', $ruleDelta['net'], $ruleDelta['ruleId']),
                    $regressed,
                )),
            );
        }
    }

    /**
     * Adds the mutation-testing block - MSI percentages, the per-status tally, and a note about
     * context-only statuses - so the user can see how thoroughly their tests killed injected mutants.
     *
     * @param list<string>   $lines - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void
     */
    private function appendMutationSummary(array &$lines, AnalysisReport $report): void
    {
        // Mutation testing did not run this time, so skip the block rather than show zeroed-out statistics.
        if ($report->mutation === null) {
            return;
        }

        $mutation = $report->mutation;
        $lines[]  = sprintf(
            '**Mutation:** MSI %.2f%%, Covered MSI %.2f%%, Mutation coverage %.2f%%, survived %d/%d.',
            $mutation->report->msi(),
            $mutation->report->coveredMsi(),
            $mutation->report->coverageRate(),
            $mutation->survivedCount(),
            $mutation->report->totalMutants(),
        );
        $lines[]  = sprintf('**Mutation statuses:** %s.', $this->mutationStatusSummary($mutation->report->statusCounts()));

        $contextStatuses = $this->mutationContextSummary($mutation->report->statusCounts());
        // Some mutants landed in context-only statuses, so add the note explaining they are not survived findings.
        if ($contextStatuses !== null) {
            $lines[] = sprintf(
                '**Mutation context-only statuses:** %s. These do not create `mutation.survived-mutant` findings.',
                $contextStatuses,
            );
        }
    }

    /**
     * Renders the "Branch Review" heading and, when a review ran, the introduced/removed/unchanged
     * finding groups - the section a user opens to see exactly what their branch changed.
     *
     * @param list<string>   $lines - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void
     */
    private function appendBranchReviewSection(array &$lines, AnalysisReport $report): void
    {
        $lines[] = '';
        $lines[] = '## Branch Review';
        $lines[] = '';

        // No branch review was requested, so the section says so plainly instead of sitting empty.
        if ($report->review === null) {
            $lines[] = 'Not enabled.';
        } else {
            // A review ran, so split the branch's findings into what it introduced, removed, and left unchanged.
            $this->appendFindingGroups($lines, 'Introduced findings', $report->review->introduced);
            $this->appendFindingGroups($lines, 'Removed findings', $report->review->removed);
            $this->appendFindingGroups($lines, 'Unchanged findings', $report->review->unchanged);
        }
    }

    /**
     * Writes the seven-column Pillars table - one row per quality pillar - so the user
     * can compare naming, complexity, security, and the rest at a glance. Rows come from
     * {@see pillarSummaryRows()}: each applicable pillar shows its grade, score to two decimals,
     * findings, and per-severity counts, ordered by findings then pillar name. Counts and scores are
     * read straight from the existing {@see PillarScore} entries, never recomputed here.
     *
     * @param list<string>   $lines - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void
     */
    private function appendPillarSection(array &$lines, AnalysisReport $report): void
    {
        $rows = $this->pillarSummaryRows($report);

        array_push(
            $lines,
            '',
            '## Pillars',
            '',
            '| Pillar | Grade | Score | Findings | Advisory | Warning | Error |',
            '| --- | --- | ---: | ---: | ---: | ---: | ---: |',
        );

        // No pillar applied to the scanned code (usually an unscored run), so render a single placeholder row.
        if ($rows === []) {
            $lines[] = '| _(none)_ |  |  |  |  |  |  |';

            return;
        }

        // One table row per pillar, escaping any pipe in the text so a stray `|` can't break the Markdown columns.
        foreach ($rows as $pillar) {
            $lines[] = sprintf(
                '| %s | %s | %s | %d | %d | %d | %d |',
                str_replace('|', '\\|', $pillar->pillar),
                str_replace('|', '\\|', $pillar->grade === null ? 'n/a' : $pillar->grade->letter),
                str_replace('|', '\\|', $pillar->grade === null ? 'n/a' : sprintf('%.2f', $pillar->grade->score)),
                $pillar->findings,
                $pillar->advisory,
                $pillar->warning,
                $pillar->error,
            );
        }
    }

    /**
     * Picks just the applicable pillars for the table and orders them findings-first, name-second,
     * so the busiest pillar sits at the top of what the user reads. Reads the existing
     * {@see PillarScore} data as-is, leaving per-severity counts and scores exactly as scoring set them.
     *
     * @param AnalysisReport $report - Analysis report providing the optional score.
     *
     * @return list<PillarScore> - applicable pillars only, ordered findings DESC then pillar name ASC; empty when no score was computed
     */
    private function pillarSummaryRows(AnalysisReport $report): array
    {
        // A run with nothing to grade has no score, so hand back no pillars and let the caller show the placeholder.
        if ($report->score === null) {
            return [];
        }

        $rows = [];
        // Walk every scored pillar, keeping only the ones that actually applied to the user's code.
        foreach ($report->score->pillars as $pillar) {
            // This pillar had no rules that applied to the scanned files, so leave it out of the table.
            if (!$pillar->applicable) {
                continue;
            }

            $rows[] = $pillar;
        }

        usort($rows, static function (PillarScore $left, PillarScore $right): int {
            // Order by finding count so the busiest pillar leads; equal counts fall back to name for a stable order.
            return $right->findings <=> $left->findings ?: strcmp($left->pillar, $right->pillar);
        });

        return $rows;
    }

    /**
     * Renders the "Findings" section the user scrolls to for specifics: either the grouped list of
     * every current finding, or a plain "No findings." line when the scan came back clean.
     *
     * @param list<string>   $lines - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void
     */
    private function appendFindingsSection(array &$lines, AnalysisReport $report): void
    {
        $lines[] = '';
        $lines[] = '## Findings';
        $lines[] = '';

        // A clean scan has nothing to list, so tell the user plainly rather than print an empty section.
        if ($report->findings === []) {
            $lines[] = 'No findings.';
        } else {
            // There are findings, so group them by severity and file, reusing the section's own heading.
            $this->appendFindingGroups($lines, 'Current findings', $report->findings, hasHeading: false);
        }
    }

    /**
     * Formats a single finding as one Markdown bullet - the line a user reads to see what tripped,
     * where, and why - packing severity, rule id, location, an optional symbol, and the message.
     *
     * @param Finding $finding - Finding to format; a null line omits the line suffix and a null symbol omits its token.
     *
     * @return string - one Markdown list item packing severity, rule id, location, optional symbol, and message
     */
    private function findingLine(Finding $finding): string
    {
        // With no line number, the location the user sees is just the file path; otherwise it gains a `:line` suffix.
        $location = $finding->line === null ? $finding->filePath : $finding->filePath . ':' . $finding->line;
        // A symbol (the offending function, method, or class name) is tacked on only when the finding actually names one.
        $symbol   = $finding->symbol === null ? '' : sprintf(' `%s`', $finding->symbol);

        return sprintf(
            '- **%s** `%s` %s%s - %s',
            $finding->severity->value,
            $finding->ruleId,
            $location,
            $symbol,
            $finding->message,
        );
    }

    /**
     * Renders one collapsible group of findings - the shared block behind both the branch-review
     * lists and the main findings section. Sorts findings into error/warning/advisory `<details>`
     * blocks and then by file, so the user can expand just the severity they care about.
     *
     * @param list<string>  $lines - Markdown lines being built; mutated in place with the rendered group.
     * @param string        $title - Section heading text, emitted only when $hasHeading is true.
     * @param list<Finding> $findings - Findings to group by severity then file path; an empty list renders "None.".
     * @param bool          $hasHeading - Whether to print the section heading; false for the inline current-findings.
     *
     * @return void
     */
    private function appendFindingGroups(array &$lines, string $title, array $findings, bool $hasHeading = true): void
    {
        // The branch-review lists want their own sub-heading; the main findings block passes false to reuse its section header.
        if ($hasHeading) {
            $lines[] = sprintf('### %s', $title);
            $lines[] = '';
        }

        // This group came back empty, so mark it "None." and stop rather than open an empty details block.
        if ($findings === []) {
            $lines[] = 'None.';
            $lines[] = '';

            return;
        }

        $groups = [];
        // Bucket each finding by severity and then by file, the two axes the user browses the report along.
        foreach ($findings as $finding) {
            $groups[$finding->severity->value][$finding->filePath][] = $finding;
        }

        // Walk severities worst-first so errors sit at the top of what the user sees.
        foreach (['error', 'warning', 'advisory'] as $severity) {
            // Nothing at this severity in this group, so skip straight to the next band.
            if (!isset($groups[$severity])) {
                continue;
            }

            ksort($groups[$severity], SORT_STRING);
            $count   = array_sum(array_map('count', $groups[$severity]));
            $lines[] = sprintf('<details open><summary>%s (%d)</summary>', ucfirst($severity), $count);
            $lines[] = '';

            // One bold sub-heading per file, then its findings, so the user can scan file by file within a severity.
            foreach ($groups[$severity] as $file => $fileFindings) {
                $lines[] = sprintf('**%s**', $file);
                $lines[] = '';

                // Emit every finding for this file as its own Markdown bullet.
                foreach ($fileFindings as $finding) {
                    $lines[] = $this->findingLine($finding);
                }

                $lines[] = '';
            }

            $lines[] = '</details>';
            $lines[] = '';
        }
    }

    /**
     * Flattens the mutation status tally into the single comma-separated `status=count` line the
     * user reads under "Mutation statuses".
     *
     * @param array<string, int> $counts - Mutation status counts keyed by status label; empty means no mutants ran.
     *
     * @return string - comma-joined `status=count` pairs in the map's order; the literal "none" when no mutants ran
     */
    private function mutationStatusSummary(array $counts): string
    {
        // No mutants ran, so show the literal word rather than leave the user an empty status line.
        if ($counts === []) {
            return 'none';
        }

        $parts = [];
        // Render each status and its count in the order the mutation report recorded them.
        foreach ($counts as $status => $count) {
            $parts[] = sprintf('%s=%d', $status, $count);
        }

        return implode(', ', $parts);
    }

    /**
     * Picks out the context-only mutation statuses (not covered, errored, skipped, and the like) and
     * joins them for the note that reassures the user these do not count as survived mutants.
     *
     * @param array<string, int> $counts - Mutation status counts keyed by status label; only context-only statuses are emitted.
     *
     * @return string|null - comma-joined `status=count` pairs for context-only statuses; null tells the caller to omit the line when none occurred
     */
    private function mutationContextSummary(array $counts): ?string
    {
        $parts = [];

        // Look only at the context-only statuses, ignoring killed or escaped mutants counted elsewhere in the mutation summary.
        foreach (['not covered', 'error', 'syntax error', 'ignored', 'skipped'] as $status) {
            $count = $counts[$status] ?? 0;
            // Include a status in the note only when it actually occurred, keeping the line to what really happened.
            if ($count > 0) {
                $parts[] = sprintf('%s=%d', $status, $count);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

}
