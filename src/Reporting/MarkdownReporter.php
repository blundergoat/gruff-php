<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Finding;

final readonly class MarkdownReporter
{
    public function render(AnalysisReport $report): string
    {
        $score = $report->score;
        $counts = $report->findingCounts();
        $pillars = $score === null ? [] : $score->pillars;
        $lines = [
            '# gruff-php report',
            '',
            sprintf('**Grade:** %s (%s/100)', $score === null ? 'n/a' : $score->composite->letter, $score === null ? 'n/a' : sprintf('%.2f', $score->composite->score)),
            sprintf('**Scope:** %s', $score === null ? 'full-project' : $score->scope),
            sprintf('**Findings:** %d total, %d error, %d warning, %d advisory', $counts['total'], $counts['error'], $counts['warning'], $counts['advisory']),
            '',
            '## Pillars',
            '',
            '| Pillar | Grade | Score | Findings |',
            '| --- | --- | ---: | ---: |',
        ];

        foreach ($pillars as $pillar) {
            $lines[] = sprintf(
                '| %s | %s | %s | %d |',
                $this->escapeTable($pillar->pillar),
                $this->escapeTable($pillar->grade === null ? 'n/a' : $pillar->grade->letter),
                $this->escapeTable($pillar->grade === null ? 'n/a' : sprintf('%.2f', $pillar->grade->score)),
                $pillar->findings,
            );
        }

        $lines[] = '';
        $lines[] = '## Findings';
        $lines[] = '';

        if ($report->findings === []) {
            $lines[] = 'No findings.';
        } else {
            foreach (array_slice($report->findings, 0, 20) as $finding) {
                $lines[] = $this->findingLine($finding);
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function findingLine(Finding $finding): string
    {
        $location = $finding->line === null ? $finding->filePath : $finding->filePath . ':' . $finding->line;

        return sprintf(
            '- **%s** `%s` %s - %s',
            $finding->severity->value,
            $finding->ruleId,
            $location,
            $finding->message,
        );
    }

    private function escapeTable(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
