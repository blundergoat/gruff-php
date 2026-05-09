<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Finding;

final readonly class GithubAnnotationsReporter
{
    public function render(AnalysisReport $report): string
    {
        $lines = [];

        foreach ($report->findings as $finding) {
            $lines[] = $this->annotation($finding);
        }

        return implode(PHP_EOL, $lines) . ($lines === [] ? '' : PHP_EOL);
    }

    private function annotation(Finding $finding): string
    {
        $level = match ($finding->severity->value) {
            'error' => 'error',
            'warning' => 'warning',
            default => 'notice',
        };
        $properties = [
            'file=' . $this->escapeProperty($finding->filePath),
            'title=' . $this->escapeProperty($finding->ruleId),
        ];

        if ($finding->line !== null) {
            $properties[] = 'line=' . $finding->line;
        }

        if ($finding->endLine !== null) {
            $properties[] = 'endLine=' . $finding->endLine;
        }

        return sprintf('::%s %s::%s', $level, implode(',', $properties), $this->escapeData($finding->message));
    }

    private function escapeProperty(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $value,
        );
    }

    private function escapeData(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $value,
        );
    }
}
