<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Scoring\PillarScore;

/**
 * Renders a completed analysis as the Markdown document requested by `gruff-php analyse --format markdown`.
 * The document contains the summary, diagnostics when present, branch review, pillar scores, and displayed findings.
 * Severity groups use expandable `<details>` blocks, making the report suitable for pull-request comments.
 */
final readonly class MarkdownReporter
{
    /**
     * Assembles the full Markdown document a user gets from --format markdown, stitching the
     * summary, diagnostics, branch review, pillars, and findings sections together in the order they read.
     *
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return string - the complete Markdown document (summary, diagnostics, branch review, pillars, findings) with a single trailing newline
     */
    public function render(AnalysisReport $report): string
    {
        $lines = [];

        $this->appendSummary($lines, $report);
        $this->appendDiagnostics($lines, $report);
        $this->appendBranchReviewSection($lines, $report);
        $this->appendPillarSection($lines, $report);
        $this->appendFindingsSection($lines, $report);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Adds every run diagnostic to the shareable document, including nonfatal bounded-scan notes.
     *
     * @param list<string> $lines - Markdown lines being built.
     * @param AnalysisReport $report - Completed report whose run diagnostics are appended.
     *
     * @return void - Appends the diagnostics section to $lines; leaves it unchanged when diagnostics are empty.
     */
    private function appendDiagnostics(array &$lines, AnalysisReport $report): void
    {
        if ($report->diagnostics === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '## Diagnostics';
        $lines[] = '';
        foreach ($report->diagnostics as $diagnostic) {
            $location = $diagnostic->filePath ?? $diagnostic->path;
            if ($location !== null && $diagnostic->filePath !== null && $diagnostic->line !== null) {
                $location .= ':' . $diagnostic->line;
            }

            $lines[] = $location === null
                ? sprintf('- `%s` %s', $diagnostic->type, $diagnostic->message)
                : sprintf('- `%s` `%s` %s', $diagnostic->type, $location, $diagnostic->message);
        }
    }

    /**
     * Writes the headline grade, scope, and finding totals, followed by the report's optional notes.
     * Includes available gate results, score drivers, diff and filter context, baseline details, mutation results, and branch review.
     *
     * @param list<string>   $lines  - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void - Appends the headline block and available run notes to $lines.
     */
    private function appendSummary(array &$lines, AnalysisReport $report): void
    {
        $score  = $report->score;
        $counts = $report->findingCounts();

        array_push(
            $lines,
            '# gruff-php report',
            '',
            sprintf('**Grade:** %s (%s/100)', $score?->composite->letter ?? 'n/a', $score?->composite === null ? 'n/a' : sprintf('%.2f', $score->composite->score)),
            sprintf('**Scope:** %s', $score === null ? 'full-project' : $score->scope),
            sprintf('**Findings:** %d total, %d error, %d warning, %d advisory', $counts['total'], $counts['error'], $counts['warning'], $counts['advisory']),
        );


        if ($report->failureReason !== null) {
            $lines[] = sprintf('**Failed:** %s.', $report->failureReason->message());
        }

        // A non-null count means the new-findings gate was active.
        if ($report->newFindingsCount !== null) {
            $lines[] = sprintf('**New findings:** %d', $report->newFindingsCount);
        }


        if ($score !== null) {
            $lines[] = sprintf('**Score drivers:** %s', $score->explanation);
        }


        if ($report->diff !== null && $report->diff->active) {
            $lines[] = sprintf('**Diff scope:** %s', $report->diff->message);
        }

        // Display filtering hides list entries without changing the findings used for the score or exit code.
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


            if ($report->shouldListAbsentBaseline && $report->baseline->staleEntries !== []) {
                $lines[] = '';
                $lines[] = '<details><summary>Resolved baseline entries</summary>';
                $lines[] = '';

                foreach ($report->baseline->staleEntries as $resolvedEntry) {
                    $lines[] = sprintf(
                        '- `%s` %s (resolved %d): %s',
                        $resolvedEntry->ruleId,
                        $resolvedEntry->path,
                        $resolvedEntry->count,
                        $resolvedEntry->subject,
                    );
                }
                $lines[] = '';
                $lines[] = '</details>';
            }
        }


        if ($report->mutation !== null) {
            $this->appendMutationSummary($lines, $report);
        }


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
     * Lists up to five rules per ranking: the largest net finding-count decreases and the largest increases.
     * Adds these rankings to the summary when a branch review has nonzero rule deltas.
     *
     * @param list<string>   $lines  - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void - Appends available improved and regressed rankings to $lines; leaves it unchanged without net deltas.
     */
    private function appendRuleDeltas(array &$lines, AnalysisReport $report): void
    {

        if ($report->review === null) {
            return;
        }

        $rows = $report->review->perRuleDelta();
        // Only net changes are ranked; offsetting additions and removals produce no row.
        if ($rows === []) {
            return;
        }

        $improved  = array_slice(array_filter($rows, static fn(array $ruleDelta): bool => $ruleDelta['net'] < 0), 0, 5);
        $regressed = array_slice(
            array_reverse(array_filter($rows, static fn(array $ruleDelta): bool => $ruleDelta['net'] > 0)),
            0,
            5,
        );


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
     * @param list<string>   $lines  - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void - Appends mutation details to $lines; leaves it unchanged when no mutation result is available.
     */
    private function appendMutationSummary(array &$lines, AnalysisReport $report): void
    {

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
        $lines[] = sprintf('**Mutation statuses:** %s.', $this->mutationStatusSummary($mutation->report->statusCounts()));

        $contextStatuses = $this->mutationContextSummary($mutation->report->statusCounts());

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
     * @param list<string>   $lines  - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void - Appends the branch-review section, using "Not enabled." when no review is available.
     */
    private function appendBranchReviewSection(array &$lines, AnalysisReport $report): void
    {
        $lines[] = '';
        $lines[] = '## Branch Review';
        $lines[] = '';


        if ($report->review === null) {
            $lines[] = 'Not enabled.';
        } else {

            $this->appendFindingGroups($lines, 'Introduced findings', $report->review->introduced);
            $this->appendFindingGroups($lines, 'Removed findings', $report->review->removed);
            $this->appendFindingGroups($lines, 'Unchanged findings', $report->review->unchanged);
        }
    }

    /**
     * Renders applicable pillar scores and per-severity counts as a Markdown table.
     *
     * @param list<string>   $lines  - Markdown lines that receive the pillar table.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void - Appends the pillar table to $lines, using a placeholder row when no applicable pillars exist.
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


        if ($rows === []) {
            $lines[] = '| _(none)_ |  |  |  |  |  |  |';

            return;
        }

        // Escape pipe characters in pillar text so they cannot split the Markdown table into extra columns.
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

        if ($report->score === null) {
            return [];
        }

        $rows = [];

        foreach ($report->score->pillars as $pillar) {

            if (!$pillar->applicable) {
                continue;
            }

            $rows[] = $pillar;
        }

        usort($rows, static function (PillarScore $left, PillarScore $right): int {

            return $right->findings <=> $left->findings ?: strcmp($left->pillar, $right->pillar);
        });

        return $rows;
    }

    /**
     * Renders the Findings section from the findings included in the report.
     * An empty list displays "No findings."; other entries are grouped by severity and file.
     *
     * @param list<string>   $lines  - Markdown lines being built.
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return void - Appends the Findings heading and either the grouped list or its empty-list message to $lines.
     */
    private function appendFindingsSection(array &$lines, AnalysisReport $report): void
    {
        $lines[] = '';
        $lines[] = '## Findings';
        $lines[] = '';


        if ($report->findings === []) {
            $lines[] = 'No findings.';
        } else {

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

        $location = $finding->line === null ? $finding->filePath : $finding->filePath . ':' . $finding->line;

        $symbol = $finding->symbol === null ? '' : sprintf(' `%s`', $finding->symbol);

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
     * lists and the main findings section. Sorts findings into error/warning/advisory collapsible
     * sections and then by file, so the user can expand just the severity they care about.
     *
     * @param list<string>  $lines      - Markdown lines being built; mutated in place with the rendered group.
     * @param string        $title      - Section heading text, emitted only when $hasHeading is true.
     * @param list<Finding> $findings   - Findings to group by severity then file path; an empty list renders "None.".
     * @param bool          $hasHeading - Whether to print the section heading; false for the inline current-findings.
     *
     * @return void - Appends the optional heading and severity groups to $lines, or "None." for an empty list.
     */
    private function appendFindingGroups(array &$lines, string $title, array $findings, bool $hasHeading = true): void
    {

        if ($hasHeading) {
            $lines[] = sprintf('### %s', $title);
            $lines[] = '';
        }


        if ($findings === []) {
            $lines[] = 'None.';
            $lines[] = '';

            return;
        }

        $groups = [];

        foreach ($findings as $finding) {
            $groups[$finding->severity->value][$finding->filePath][] = $finding;
        }


        foreach (['error', 'warning', 'advisory'] as $severity) {

            if (!isset($groups[$severity])) {
                continue;
            }

            ksort($groups[$severity], SORT_STRING);
            $count   = array_sum(array_map('count', $groups[$severity]));
            $lines[] = sprintf('<details open><summary>%s (%d)</summary>', ucfirst($severity), $count);
            $lines[] = '';


            foreach ($groups[$severity] as $file => $fileFindings) {
                $lines[] = sprintf('**%s**', $file);
                $lines[] = '';


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
     * Flattens the mutation status tally into the single comma-separated status=count line the
     * user reads under "Mutation statuses".
     *
     * @param array<string, int> $counts - Counts keyed by status label; an empty map renders "none".
     *
     * @return string - Comma-joined status=count pairs in map order, or "none" for an empty map.
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
     * Picks out the context-only mutation statuses (not covered, errored, skipped, and the like) and
     * joins them for the note that reassures the user these do not count as survived mutants.
     *
     * @param array<string, int> $counts - Mutation status counts keyed by status label; only context-only statuses are emitted.
     *
     * @return string|null - comma-joined status=count pairs for context-only statuses; null tells the caller to omit the line when none occurred
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

}
