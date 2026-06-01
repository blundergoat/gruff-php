<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Finding;
use GruffPhp\Scoring\PillarScore;

/**
 * Renders analysis reports as Markdown.
 */
final readonly class MarkdownReporter
{
    /**
     * Render an analysis report as Markdown.
     *
     * @param AnalysisReport $report Analysis report to render.
     * @return string Markdown report output.
     */
    public function render(AnalysisReport $report): string
    {
        $lines = [];

        $this->appendSummary($lines, $report);
        $this->appendBranchReviewSection($lines, $report);
        $this->appendPillarSection($lines, $report);
        $this->appendFindingsSection($lines, $report);

        // Sections are accumulated line-by-line, then joined with a single trailing newline for clean file output.
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Append report-level summary lines.
     *
     * @param list<string>   $lines  Markdown lines being built.
     * @param AnalysisReport $report Analysis report to render.
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

        if ($report->failureReason !== null) {
            $lines[] = sprintf('**Failed:** %s.', $report->failureReason->message());
        }

        if ($report->newFindingsCount !== null) {
            $lines[] = sprintf('**New findings:** %d', $report->newFindingsCount);
        }

        if ($score !== null) {
            $lines[] = sprintf('**Score drivers:** %s', $score->explanation);
        }

        if ($report->diff !== null && $report->diff->active) {
            $lines[] = sprintf('**Diff scope:** %s', $report->diff->message);
        }

        if ($report->filters !== null && $report->filters->isActive()) {
            $lines[] = sprintf(
                '**Display filters:** `%s`; score and exit code use the scored finding set.',
                json_encode($report->filters->toArray(), JSON_UNESCAPED_SLASHES) ?: '{}',
            );
        }

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
                        '- `%s` %s%s',
                        $resolvedEntry->ruleId,
                        $resolvedEntry->filePath,
                        $resolvedEntry->line !== null ? ':' . $resolvedEntry->line : '',
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
     * Render the top-5 most-improved and most-regressed rules from a branch-review comparison.
     * Surfaced before the per-pillar score so the rule-level shift is visible alongside
     * the composite (which can mask churn). See M06 / ADR-016.
     *
     * @param list<string>   $lines  Markdown lines being built.
     * @param AnalysisReport $report Analysis report to render.
     * @return void
     */
    private function appendRuleDeltas(array &$lines, AnalysisReport $report): void
    {
        if ($report->review === null) {
            // No branch review means no rule deltas to surface; leave the lines untouched.
            return;
        }

        $rows = $report->review->perRuleDelta();
        if ($rows === []) {
            // No rule moved between base and head, so emit no improved/regressed block at all.
            return;
        }

        $improved  = array_slice(array_filter($rows, static fn (array $ruleDelta): bool => $ruleDelta['net'] < 0), 0, 5);
        $regressed = array_slice(
            array_reverse(array_filter($rows, static fn (array $ruleDelta): bool => $ruleDelta['net'] > 0)),
            0,
            5,
        );

        if ($improved !== []) {
            $lines[] = sprintf(
                '**Top %d improved:** %s',
                count($improved),
                implode(', ', array_map(
                    static fn (array $ruleDelta): string => sprintf('`%d %s`', $ruleDelta['net'], $ruleDelta['ruleId']),
                    $improved,
                )),
            );
        }

        if ($regressed !== []) {
            $lines[] = sprintf(
                '**Top %d regressed:** %s',
                count($regressed),
                implode(', ', array_map(
                    static fn (array $ruleDelta): string => sprintf('`+%d %s`', $ruleDelta['net'], $ruleDelta['ruleId']),
                    $regressed,
                )),
            );
        }
    }

    /**
     * Append mutation summary lines.
     *
     * @param list<string>   $lines  Markdown lines being built.
     * @param AnalysisReport $report Analysis report to render.
     * @return void
     */
    private function appendMutationSummary(array &$lines, AnalysisReport $report): void
    {
        if ($report->mutation === null) {
            // Mutation testing did not run; skip the block rather than print zeroed-out statistics.
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
     * Append the branch-review section.
     *
     * @param list<string>   $lines  Markdown lines being built.
     * @param AnalysisReport $report Analysis report to render.
     * @return void
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
     * Append the canonical 7-column Pillars table shared across the cross-port
     * summary harmonisation. Rows are sourced from {@see pillarSummaryRows()}:
     * every applicable pillar is shown with grade, score (2dp), findings, and
     * per-severity counts, sorted by findings DESC then pillar ASC. Pillar
     * data is sourced from the existing {@see PillarScore} entries without
     * recomputing severity counts or scores.
     *
     * @param list<string>   $lines  Markdown lines being built.
     * @param AnalysisReport $report Analysis report to render.
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

        if ($rows === []) {
            $lines[] = '| _(none)_ |  |  |  |  |  |  |';
            // Header is already emitted, so a placeholder row keeps the table well-formed when no pillars apply.
            return;
        }

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
     * Return the applicable pillar scores for the canonical Pillars table,
     * sorted by findings DESC then pillar ASC. Sourced from the existing
     * {@see PillarScore} data so per-severity counts and scores are never
     * recomputed by the markdown reporter.
     *
     * @param AnalysisReport $report Analysis report providing the optional score.
     * @return list<PillarScore>
     */
    private function pillarSummaryRows(AnalysisReport $report): array
    {
        if ($report->score === null) {
            // No score computed (e.g. empty scope), so there are no pillar rows to show.
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
            // Findings DESC is the primary key; pillar name ASC breaks ties so ordering is deterministic.
            return $right->findings <=> $left->findings ?: strcmp($left->pillar, $right->pillar);
        });

        // Hand back the applicable pillars in the canonical table order built above.
        return $rows;
    }

    /**
     * Append current findings.
     *
     * @param list<string>   $lines  Markdown lines being built.
     * @param AnalysisReport $report Analysis report to render.
     * @return void
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
     * Render one finding as a Markdown list item.
     *
     * @param Finding $finding Finding to format; a null line omits the line suffix and a null symbol omits its token.
     * @return string Markdown finding line.
     */
    private function findingLine(Finding $finding): string
    {
        $location = $finding->line === null ? $finding->filePath : $finding->filePath . ':' . $finding->line;
        $symbol   = $finding->symbol === null ? '' : sprintf(' `%s`', $finding->symbol);

        // Single bullet packing severity, rule id, location, optional symbol, and message into one list item.
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
     * Append finding groups details to report output.
     *
     * @param list<string>  $lines     Markdown lines being built; mutated in place with the rendered group.
     * @param string        $title     Section heading text, emitted only when $hasHeading is true.
     * @param list<Finding> $findings  Findings to group by severity then file path; an empty list renders "None.".
     * @param bool          $hasHeading Whether to print the section heading; false for the inline current-findings.
     *
     * @return void
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
            // Empty group still prints a "None." marker so an absent section is explicit rather than missing.
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
     * @param array<string, int> $counts
     * @return string Human-readable mutation status summary.
     */
    private function mutationStatusSummary(array $counts): string
    {
        if ($counts === []) {
            // No mutants ran, so report the literal word rather than an empty status string.
            return 'none';
        }

        $parts = [];
        foreach ($counts as $status => $count) {
            $parts[] = sprintf('%s=%d', $status, $count);
        }

        // Comma-joined `status=count` pairs in the map's own order, mirroring the upstream Infection report.
        return implode(', ', $parts);
    }

    /**
     * @param array<string, int> $counts
     * @return string|null Context-only status summary, or null when absent.
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

        // Null signals the caller to omit the context-only line entirely when none of these statuses occurred.
        return $parts === [] ? null : implode(', ', $parts);
    }

}
