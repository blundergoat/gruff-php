<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Finding\Finding;

/**
 * Renders findings and run diagnostics as GitHub Actions workflow commands, including available file and line properties.
 * Findings use error, warning or notice levels; diagnostics use error when fatal and notice otherwise.
 * Findings precede diagnostics; a report with neither produces no output.
 */
final readonly class GithubAnnotationsReporter
{
    /**
     * Renders one workflow command per finding, followed by one per run diagnostic.
     *
     * @param AnalysisReport $report - Analysis whose findings and diagnostics become annotation commands.
     *
     * @return string - Newline-terminated commands; empty only when both findings and diagnostics are empty.
     */
    public function render(AnalysisReport $report): string
    {
        $lines = [];


        foreach ($report->findings as $finding) {
            $lines[] = $this->annotation($finding);
        }

        foreach ($report->diagnostics as $diagnostic) {
            $lines[] = $this->diagnosticAnnotation($diagnostic);
        }


        return implode(PHP_EOL, $lines) . ($lines === [] ? '' : PHP_EOL);
    }

    /**
     * Encodes a run diagnostic as a workflow annotation so non-finding scan notes remain visible.
     *
     * @param RunDiagnostic $diagnostic - Run-level note to encode; a fatal one raises the annotation level.
     *
     * @return string - One error or notice command with a diagnostic title, message and any available location.
     */
    private function diagnosticAnnotation(RunDiagnostic $diagnostic): string
    {
        $properties = ['title=' . $this->escapeProperty($diagnostic->type)];
        $filePath   = $diagnostic->filePath ?? $diagnostic->path;
        if ($filePath !== null) {
            $properties[] = 'file=' . $this->escapeProperty($filePath);
        }
        if ($diagnostic->line !== null) {
            $properties[] = 'line=' . $diagnostic->line;
        }

        return sprintf(
            '::%s %s::%s',
            $diagnostic->isFatal ? 'error' : 'notice',
            implode(',', $properties),
            $this->escapeData($diagnostic->message),
        );
    }

    /**
     * Encodes one finding with its severity level, file, rule ID and message for a GitHub workflow runner.
     *
     * @param Finding $finding - Finding to encode; null line or endLine values omit the corresponding location properties.
     *
     * @return string - One workflow command with escaped properties and message.
     */
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

        // GitHub requires properties and message data in the ::level properties::data workflow-command shape.
        return sprintf('::%s %s::%s', $level, implode(',', $properties), $this->escapeData($finding->message));
    }

    /**
     * Percent-escapes a property value such as a file path or rule ID so delimiter characters cannot
     * split the command or attach the annotation to the wrong location.
     *
     * @param string $text - Raw property value such as a file path or rule id; an empty value passes through as an empty string.
     *
     * @return string - Escaped property value.
     */
    private function escapeProperty(string $text): string
    {
        // Property values also escape colons and commas because both delimit the property list.
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $text,
        );
    }

    /**
     * Percent-escapes annotation message text so percent signs and line breaks remain data within a workflow command.
     *
     * @param string $text - Raw finding or diagnostic message; an empty message stays empty.
     *
     * @return string - Message text with percent signs, carriage returns and newlines escaped.
     */
    private function escapeData(string $text): string
    {
        // The data segment reserves percent signs and line breaks, while colons and commas remain literal.
        return str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $text,
        );
    }
}
