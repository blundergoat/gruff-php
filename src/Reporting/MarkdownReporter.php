<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Finding;

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
        $score   = $report->score;
        $counts  = $report->findingCounts();
        $pillars = $score === null ? [] : $score->pillars;
        $lines   = [
            '# gruff-php report',
            '',
            sprintf('**Grade:** %s (%s/100)', $score === null ? 'n/a' : $score->composite->letter, $score === null ? 'n/a' : sprintf('%.2f', $score->composite->score)),
            sprintf('**Scope:** %s', $score === null ? 'full-project' : $score->scope),
            sprintf('**Findings:** %d total, %d error, %d warning, %d advisory', $counts['total'], $counts['error'], $counts['warning'], $counts['advisory']),
        ];

        if ($report->filters !== null && $report->filters->active()) {
            $lines[] = sprintf('**Filters:** `%s`', json_encode($report->filters->toArray(), JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        if ($report->review !== null) {
            $lines[] = sprintf(
                '**Branch review:** base `%s`, %d introduced, %d removed, %d unchanged',
                $report->review->base,
                count($report->review->introduced),
                count($report->review->removed),
                count($report->review->unchanged),
            );
        }

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

        array_push(
            $lines,
            '',
            '## Pillars',
            '',
            '| Pillar | Grade | Score | Findings |',
            '| --- | --- | ---: | ---: |',
        );

        foreach ($pillars as $pillar) {
            $lines[] = sprintf(
                '| %s | %s | %s | %d |',
                str_replace('|', '\\|', $pillar->pillar),
                str_replace('|', '\\|', $pillar->grade === null ? 'n/a' : $pillar->grade->letter),
                str_replace('|', '\\|', $pillar->grade === null ? 'n/a' : sprintf('%.2f', $pillar->grade->score)),
                $pillar->findings,
            );
        }

        $lines[] = '';
        $lines[] = '## Findings';
        $lines[] = '';

        if ($report->findings === []) {
            $lines[] = 'No findings.';
        } else {
            $this->appendFindingGroups($lines, 'Current findings', $report->findings, includeHeading: false);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Render one finding as a Markdown list item.
     *
     * @return string Markdown finding line.
     */
    private function findingLine(Finding $finding): string
    {
        $location = $finding->line === null ? $finding->filePath : $finding->filePath . ':' . $finding->line;
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
     * @param list<string>  $lines
     * @param list<Finding> $findings
     *
     * @return void No return value.
     */
    private function appendFindingGroups(array &$lines, string $title, array $findings, bool $includeHeading = true): void
    {
        if ($includeHeading) {
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

}
