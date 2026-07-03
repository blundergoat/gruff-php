<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Scoring\FileScore;
use GruffPhp\Results\Scoring\PillarScore;
use GruffPhp\Support\PathHelper;

/**
 * Builds the interactive HTML inspection report.
 */
final readonly class HtmlReporter
{
    /**
     * Build the HTML reporter with the project root and editor-link preferences.
     *
     * @param string $projectRoot - Project root used to build editor links.
     * @param string $editorLink - Editor-link mode used in finding rows.
     * @param bool   $interactive - Whether interactive filtering controls should be included.
     */
    public function __construct(
        private string $projectRoot = '',
        private string $editorLink = 'none',
        private bool   $interactive = false,
    ) {
    }

    /**
     * Render the full inspection report as a single HTML document.
     *
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return string - a complete standalone HTML document (doctype through closing html), inlining the styles and the filter script in interactive
     *                mode
     */
    public function render(AnalysisReport $report): string
    {
        $score        = $report->score;
        $grade        = $score?->composite->letter ?? 'n/a';
        $numericScore = $score === null ? 'n/a' : sprintf('%.2f / 100', $score->composite->score);
        $counts       = $report->findingCounts();
        $title        = sprintf('gruff-php inspection report - %s', $grade);
        $script       = $this->interactive
            ? '<script type="module">' . HtmlReportAssets::interactiveScript() . '</script>' . PHP_EOL
            : '';

        return '<!DOCTYPE html>' . PHP_EOL
               . '<html lang="en-NZ">' . PHP_EOL
               . '<head>' . PHP_EOL
               . '<meta charset="UTF-8">' . PHP_EOL
               . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . PHP_EOL
               . sprintf('<title>%s</title>', $this->escape($title)) . PHP_EOL
               . '<style>' . HtmlReportAssets::css($report->diagnostics !== [], $this->interactive) . '</style>' . PHP_EOL
               . '</head>' . PHP_EOL
               . '<body>' . PHP_EOL
               . '<div class="paper"><span class="corner-tr"></span><span class="corner-bl"></span>'
               . $this->masthead($report)
               . $this->diagnostics($report)
               . $this->verdict($grade, $numericScore, $counts, $report)
               . $this->pillars($report)
               . $this->offenders($report)
               . $this->distribution($report)
               . $this->findings($report)
               . $this->footer($report)
               . '</div>' . PHP_EOL
               . $script
               . '</body>' . PHP_EOL
               . '</html>' . PHP_EOL;
    }

    /**
     * Render the report masthead (brand, paths, scope, format).
     *
     * @param AnalysisReport $report - Report whose requested paths, diff scope, format, and tool version label the header.
     *
     * @return string - the masthead header fragment carrying the brand, the resolved paths/scope/format meta panel, and the tool version
     */
    private function masthead(AnalysisReport $report): string
    {
        $paths     = $report->requestedPaths === [] ? ['.'] : $report->requestedPaths;
        $diffLabel = $report->diff !== null && $report->diff->active
            ? sprintf('%s · %d changed files', $report->diff->mode, count($report->diff->changedFiles))
            : 'full project';

        return '<header class="masthead">'
               . '<div class="brand"><div class="wordmark">gruff</div><div class="tagline">php code quality · inspection report</div></div>'
               . '<div class="meta">'
               . $this->metaRow('paths', implode(', ', $paths))
               . $this->metaRow('scope', $diffLabel)
               . $this->metaRow('format', $report->format)
               . $this->metaRow('fail', $report->failOn)
               . sprintf('<div class="inspection-id">%s</div>', $this->escape(AnalysisReport::TOOL_NAME . ' ' . $report->toolVersion))
               . '</div></header>';
    }

    /**
     * Render the diagnostics section listing run messages, or empty when there are none.
     *
     * @param AnalysisReport $report - Report whose run diagnostics drive the section; an empty list omits it entirely.
     *
     * @return string - the diagnostics section wrapping one row per run message, or an empty string when there are no diagnostics
     */
    private function diagnostics(AnalysisReport $report): string
    {
        if ($report->diagnostics === []) {
            return '';
        }

        $html = '<section class="diagnostics"><h2 class="section-head">diagnostics <span class="aside">run messages</span></h2><div class="diagnostic-list">';

        foreach ($report->diagnostics as $diagnostic) {
            $html .= $this->diagnosticRow($diagnostic);
        }

        return $html . '</div></section>';
    }

    /**
     * @param string                                                     $grade - Composite letter grade already resolved by the caller;
     *                                                                                 rendered into the grade stamp.
     * @param string                                                     $numericScore - Pre-formatted "NN.NN / 100" score string, or "n/a" when no
     *                                                                                 score was computed.
     * @param array{advisory: int, warning: int, error: int, total: int} $counts - Severity tallies for the visible findings in this report.
     * @param AnalysisReport                                             $report - Report supplying the per-pillar context for the verdict
     *                                                                                 summary sentence.
     *
     * @return string - the verdict section pairing the grade stamp with the summary headline, severity tallies, and score-driver context
     */
    private function verdict(string $grade, string $numericScore, array $counts, AnalysisReport $report): string
    {
        $summary = $this->verdictSummary($report, $counts);

        return '<section class="verdict">'
               . '<div class="grade-stamp">'
               . sprintf('<div class="grade-letter">%s</div>', $this->escape($grade))
               . sprintf('<div class="grade-score">%s</div>', $this->escape($numericScore))
               . '</div>'
               . '<div class="verdict-body">'
               . sprintf('<div class="verdict-headline">Inspection complete.<br><em>%s</em></div>', $this->escape($summary))
               . '<div class="verdict-stats">'
               . $this->stat((string)$counts['total'], 'findings', '')
               . $this->stat((string)$counts['error'], 'errors', 'fail')
               . $this->stat((string)$counts['warning'], 'warnings', 'warn')
               . $this->stat((string)$counts['advisory'], 'advisories', 'note')
               . '</div>'
               . $this->scoreContext($report)
               . '</div></section>';
    }

    /**
     * Render the canonical Pillars table. Rows mirror the text/JSON summary
     * shape produced by {@see pillarSummaryRows()}: every applicable pillar
     * (excluding mutation, which the HTML keeps in findings) is shown with
     * grade, score (2dp), findings, and per-severity counts, sorted by
     * findings DESC then pillar ASC.
     *
     * @param AnalysisReport $report - Report whose applicable pillar scores populate the table (mutation excluded).
     *
     * @return string - the pillars section table, one row per applicable pillar, or a "No pillars." placeholder row when none apply
     */
    private function pillars(AnalysisReport $report): string
    {
        $rows = $this->pillarSummaryRows($report);
        $html = '<section class="pillars"><h2 class="section-head">pillars <span class="aside">weighted composite</span></h2>'
                . '<table class="pillar-list"><thead><tr>'
                . '<th scope="col">pillar</th>'
                . '<th scope="col" class="num">grade</th>'
                . '<th scope="col" class="num">score</th>'
                . '<th scope="col" class="num">findings</th>'
                . '<th scope="col" class="num">advisory</th>'
                . '<th scope="col" class="num">warning</th>'
                . '<th scope="col" class="num">error</th>'
                . '</tr></thead><tbody>';

        if ($rows === []) {
            $html .= '<tr><td colspan="7">No pillars.</td></tr>';
        }

        foreach ($rows as $pillar) {
            $html .= $this->pillarRow($pillar);
        }

        return $html . '</tbody></table></section>';
    }

    /**
     * Render the top-offenders table sorted by score.
     *
     * @param AnalysisReport $report - Report whose score supplies the ranked offender files; no score yields an empty table.
     *
     * @return string - the top-offenders section table, one row per ranked file, or a "No offenders found." placeholder when there are none
     */
    private function offenders(AnalysisReport $report): string
    {
        $items = $report->score === null ? [] : $report->score->topOffenders;
        $html  = '<section class="offenders"><h2 class="section-head">top offenders <span class="aside">sorted by score</span></h2>'
                 . '<table class="offender-list"><thead><tr><th scope="col">file</th><th scope="col" class="num">cyclo</th><th scope="col" class="num">cognit.</th><th scope="col" class="num">LOC</th><th scope="col" class="num">findings</th><th scope="col" class="num">grade</th></tr></thead><tbody>';

        if ($items === []) {
            $html .= '<tr><td colspan="6">No offenders found.</td></tr>';
        }

        foreach ($items as $item) {
            $html .= $this->offenderRow($item);
        }

        return $html . '</tbody></table></section>';
    }

    /**
     * Render the cyclomatic-complexity distribution histogram.
     *
     * @param AnalysisReport $report - Report whose complexity distribution buckets become histogram bars; empty renders a flat chart.
     *
     * @return string - the distribution chart section: the summary sentence, one histogram bar per CC bucket, and the bucket-label axis
     */
    private function distribution(AnalysisReport $report): string
    {
        $distribution = $report->score === null ? [] : $report->score->complexityDistribution;
        $maximumCount = max(1, ...array_values($distribution === [] ? [0] : $distribution));
        $bars         = '';
        $axis         = '';

        foreach ($distribution as $label => $count) {
            $height = max(4, (int)round(($count / $maximumCount) * 100));
            $class  = in_array($label, ['16-20', '21+'], true) ? ' fail' : (in_array($label, ['11-15'], true) ? ' warn' : '');
            $bars   .= sprintf('<div class="bar%s" style="height:%d%%;"><span class="count">%d</span></div>', $class, $height, $count);
            $axis   .= sprintf('<span>%s</span>', $this->escape($label));
        }

        return '<section class="chart-section"><h2 class="section-head">distribution <span class="aside">cyclomatic complexity</span></h2>'
               . sprintf('<p class="chart-summary">%s</p>', $this->escape($this->cyclomaticSummary($distribution)))
               . '<div class="chart-card"><div class="title">cyclomatic complexity · flagged methods</div>'
               . '<div class="histogram">' . $bars . '</div><div class="histogram-axis">' . $axis . '</div></div></section>';
    }

    /**
     * Render the flagged-findings section with optional interactive filters.
     *
     * @param AnalysisReport $report - Report whose findings become rows; the filter form is added only in interactive mode.
     *
     * @return string - the flagged-findings section: the optional filter form, one card per finding, or a "No findings." placeholder when empty
     */
    private function findings(AnalysisReport $report): string
    {
        $listAttributes = $this->interactive ? ' data-findings-list' : '';
        $html           = sprintf(
            '<section class="findings"><h2 class="section-head">flagged findings <span class="aside">%d shown</span></h2>%s<div class="findings-list"%s>',
            count($report->findings),
            $this->interactive ? $this->findingFilters($report) : '',
            $listAttributes,
        );

        if ($report->findings === []) {
            $html .= '<div class="empty">No findings.</div>';
        }

        foreach ($report->findings as $finding) {
            $html .= $this->findingRow($finding);
        }

        return $html . '</div></section>';
    }

    /**
     * Render the report footer with tool version and schema id.
     *
     * @param AnalysisReport $report - Report supplying the tool version shown in the footer (schema id is a class constant).
     *
     * @return string - the footer band carrying the tool version, the tagline, and the schema id
     */
    private function footer(AnalysisReport $report): string
    {
        return '<footer class="footer">'
               . sprintf('<div class="left">gruff-php · v%s</div>', $this->escape($report->toolVersion))
               . '<div class="center">strong opinions, opinionated defaults</div>'
               . sprintf('<div class="right">schema · %s</div>', $this->escape(AnalysisReport::SCHEMA_VERSION))
               . '</footer>';
    }

    /**
     * Render a single row of the canonical pillars table.
     *
     * @param PillarScore $pillar - Pillar score for this row; a null grade renders "n/a" and a neutral grade pill.
     *
     * @return string - one table row pairing the pillar name and grade pill with its score, finding total, and per-severity counts
     */
    private function pillarRow(PillarScore $pillar): string
    {
        $grade      = $pillar->grade === null ? 'n/a' : $pillar->grade->letter;
        $gradeClass = $pillar->grade === null ? 'n' : strtolower($grade[0] ?? 'n');
        $score      = $pillar->grade === null ? 'n/a' : sprintf('%.2f', $pillar->grade->score);

        return '<tr>'
               . sprintf('<td class="pillar-name">%s</td>', $this->escape($pillar->pillar))
               . sprintf('<td class="num"><span class="grade-pill %s">%s</span></td>', $this->escape($gradeClass), $this->escape($grade))
               . sprintf('<td class="num">%s</td>', $this->escape($score))
               . sprintf('<td class="num">%d</td>', $pillar->findings)
               . sprintf('<td class="num %s">%d</td>', $this->severityCellClass($pillar->advisory, 'note'), $pillar->advisory)
               . sprintf('<td class="num %s">%d</td>', $this->severityCellClass($pillar->warning, 'warn'), $pillar->warning)
               . sprintf('<td class="num %s">%d</td>', $this->severityCellClass($pillar->error, 'fail'), $pillar->error)
               . '</tr>';
    }

    /**
     * Return the applicable pillar scores for the canonical table, sorted by
     * findings DESC then pillar ASC. Sourced from the existing PillarScore
     * data without recomputing severity counts or scores. The mutation pillar
     * is excluded so the HTML keeps mutation details in findings.
     *
     * @param AnalysisReport $report - Report whose pillar scores are filtered and sorted; a null score yields no rows.
     *
     * @return list<PillarScore> - applicable, mutation-excluded pillars in table display order (findings DESC, then pillar ASC); empty when no score
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

            if (strtolower($pillar->pillar) === 'mutation') {
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
     * Return the CSS tier class for a per-severity count cell. Zero-valued
     * cells stay neutral so a clean pillar reads as visually quiet.
     *
     * @param int    $count - Number of findings at this severity; zero suppresses the colour class.
     * @param string $tier - CSS class applied when the count is positive (for example "note", "warn", "fail").
     *
     * @return string - the tier class when the count is positive, or an empty string for a zero count so the cell stays neutral
     */
    private function severityCellClass(int $count, string $tier): string
    {
        return $count > 0 ? $tier : '';
    }

    /**
     * Render a single row of the top-offenders table.
     *
     * @param FileScore $file - Per-file score for this row; complexity and LOC metrics may be null and render "n/a".
     *
     * @return string - one table row pairing the file path and grade pill with its cyclomatic, cognitive, LOC, and finding metrics
     */
    private function offenderRow(FileScore $file): string
    {
        return '<tr>'
               . sprintf('<td class="file-path">%s</td>', $this->locationMarkup($file->filePath, null))
               . sprintf('<td class="num">%s</td>', $this->escape($this->optionalInt($file->maxCyclomatic)))
               . sprintf('<td class="num">%s</td>', $this->escape($this->optionalInt($file->maxCognitive)))
               . sprintf('<td class="num">%s</td>', $this->escape($this->optionalInt($file->maxLines)))
               . sprintf('<td class="num">%d</td>', $file->findings)
               . sprintf('<td class="num"><span class="grade-pill %s">%s</span></td>', strtolower($file->grade->letter), $this->escape($file->grade->letter))
               . '</tr>';
    }

    /**
     * Render a single flagged-finding row with severity, rule, and location.
     *
     * @param Finding $finding - Finding to render; its fields also become data-* filter attributes in interactive mode.
     *
     * @return string - one finding card: the severity badge, rule id, message, location, and pillar, plus filter data-* attributes in interactive
     *                mode
     */
    private function findingRow(Finding $finding): string
    {
        $severityClass = match ($finding->severity->value) {
            'error' => 'fail',
            'warning' => 'warn',
            default => 'note',
        };
        $attributes    = $this->interactive ? sprintf(
            ' data-severity="%s" data-pillar="%s" data-file="%s" data-rule="%s" data-search="%s"',
            $this->escape($finding->severity->value),
            $this->escape($finding->pillar->value),
            $this->escape($finding->filePath),
            $this->escape($finding->ruleId),
            $this->escape($finding->ruleId . ' ' . $finding->message),
        ) : '';

        return '<div class="finding"' . $attributes . '>'
               . sprintf('<div class="severity %s">%s</div>', $severityClass, $this->escape($finding->severity->value))
               . '<div class="finding-body">'
               . sprintf('<h3 class="rule">%s</h3>', $this->escape($finding->ruleId))
               . sprintf('<div class="msg">%s</div>', $this->escape($finding->message))
               . sprintf('<div class="loc"><code>%s</code></div>', $this->locationMarkup($finding->filePath, $finding->line))
               . '</div>'
               . sprintf('<div class="points"><b>%s</b></div>', $this->escape($finding->pillar->value))
               . '</div>';
    }

    /**
     * Render a label-value pair for the masthead meta panel.
     *
     * @param string $label - Short uppercase key shown on the left (for example "paths", "scope", "format").
     * @param string $displayText - Already-resolved value text shown on the right; escaped here, not by the caller.
     *
     * @return string - one meta-panel row: the label and its value, both HTML-escaped here
     */
    private function metaRow(string $label, string $displayText): string
    {
        return sprintf(
            '<div><span class="label">%s</span><span class="val">%s</span></div>',
            $this->escape($label),
            $this->escape($displayText),
        );
    }

    /**
     * Render a single statistic block inside the verdict stats grid.
     *
     * @param string $number - Pre-stringified count shown large (the caller casts the integer total/severity tally).
     * @param string $label - Lowercase caption under the number (for example "findings", "errors").
     * @param string $class - Severity colour class for the number ("fail", "warn", "note"), or empty for the neutral total.
     *
     * @return string - one stat tile: the large count, its caption, and the optional severity colour class on the number
     */
    private function stat(string $number, string $label, string $class): string
    {
        return sprintf(
            '<div class="stat"><div class="num %s">%s</div><div class="lbl">%s</div></div>',
            $this->escape($class),
            $this->escape($number),
            $this->escape($label),
        );
    }

    /**
     * Render concise score-context notes for the HTML report.
     *
     * @param AnalysisReport $report - Report whose score, diff, baseline, filters, and mutation inputs become driver notes.
     *
     * @return string - the "score drivers" list of explanation, diff, baseline-movement, filter, and mutation notes, or an empty string when no
     *                score exists
     */
    private function scoreContext(AnalysisReport $report): string
    {
        if ($report->score === null) {
            return '';
        }

        $items = [$report->score->explanation];

        if ($report->diff !== null && $report->diff->active) {
            $items[] = $report->diff->message;
        }

        if ($report->baseline !== null) {
            $items[] = sprintf(
                'Baseline movement: %d new, %d unchanged, %d resolved; unchanged findings are accepted debt removed before scoring.',
                $report->baseline->newCount,
                $report->baseline->unchangedCount,
                $report->baseline->absentCount,
            );

            if ($report->shouldListAbsentBaseline) {
                // One score-driver note per fixed group so the HTML report credits the cleanup work.
                foreach ($report->baseline->staleEntries as $resolvedEntry) {
                    $items[] = sprintf(
                        'Resolved: %s %s (resolved %d): %s',
                        $resolvedEntry->ruleId,
                        $resolvedEntry->filePath,
                        $resolvedEntry->count,
                        $resolvedEntry->message,
                    );
                }
            }
        }

        if ($report->filters !== null && $report->filters->isActive()) {
            $items[] = 'Display filters affect rendered findings only; score and exit code use the scored finding set.';
        }

        if ($report->mutation !== null) {
            $items[] = sprintf(
                'Mutation input from %s contributes MSI to the score; HTML keeps mutation details in findings rather than a separate mutation visualization.',
                $report->mutation->report->reportPath,
            );
        }

        $list = '';
        foreach ($items as $item) {
            $list .= sprintf('<li>%s</li>', $this->escape($item));
        }

        return '<div class="score-context"><div class="score-context-title">score drivers</div><ul>' . $list . '</ul></div>';
    }

    /**
     * Stringify an optional integer; null renders as "n/a".
     *
     * @param int|null $integer - Metric value, or null when the offender row has no measurement for that column.
     *
     * @return string - the integer as a decimal string, or "n/a" when the value is null so empty cells read explicitly
     */
    private function optionalInt(?int $integer): string
    {
        return $integer === null ? 'n/a' : (string)$integer;
    }

    /**
     * @param AnalysisReport                                             $report - Report whose findings are scanned to count the distinct pillars
     *                                                                           carrying warnings/errors.
     * @param array{advisory: int, warning: int, error: int, total: int} $counts - Severity tallies used to decide whether the summary is clean or thresholded.
     *
     * @return string - a one-line summary: the reassuring no-findings sentence, or the warning/error count and the number of pillars they span
     */
    private function verdictSummary(AnalysisReport $report, array $counts): string
    {
        $thresholdFindings = $counts['warning'] + $counts['error'];

        if ($thresholdFindings === 0) {
            return 'No warning or error findings flagged.';
        }

        $pillars = [];
        foreach ($report->findings as $finding) {
            if (!in_array($finding->severity->value, ['warning', 'error'], true)) {
                continue;
            }

            $pillars[$finding->pillar->value] = true;
        }

        return sprintf(
            '%d %s at warning or error severity across %d %s.',
            $thresholdFindings,
            $thresholdFindings === 1 ? 'finding' : 'findings',
            count($pillars),
            count($pillars) === 1 ? 'pillar' : 'pillars',
        );
    }

    /**
     * @param array<string, int> $distribution - Complexity bucket counts keyed by range label; absent buckets are treated as zero.
     *
     * @return string - a one-line summary of how many methods exceed CC 10, with the per-bucket split across 11-15, 16-20, and 21+
     */
    private function cyclomaticSummary(array $distribution): string
    {
        $moderate = $distribution['11-15'] ?? 0;
        $high     = $distribution['16-20'] ?? 0;
        $severe   = $distribution['21+'] ?? 0;
        $exceeds  = $moderate + $high + $severe;

        return sprintf(
            '%d %s %s CC 10 (%d in 11-15, %d in 16-20, %d at 21+).',
            $exceeds,
            $exceeds === 1 ? 'method' : 'methods',
            $exceeds === 1 ? 'exceeds' : 'exceed',
            $moderate,
            $high,
            $severe,
        );
    }

    /**
     * Render a clickable file-and-line span; emits an editor-link anchor when configured.
     *
     * @param string   $filePath - Report-relative or absolute path shown to the reader and carried in data-path.
     * @param int|null $line - Line number appended after a colon, or null to show the path alone (used for file-level rows).
     *
     * @return string - the path (with optional line) as a clickable editor-link anchor when one is configured, otherwise an inert focusable span
     *                that still copies via data-path
     */
    private function locationMarkup(string $filePath, ?int $line): string
    {
        $text              = $line === null ? $filePath : sprintf('%s:%d', $filePath, $line);
        $locationAttribute = sprintf(' data-path="%s"', $this->escape($text));
        $href              = $this->editorHref($filePath, $line);

        if ($href !== null) {
            return sprintf(
                '<a class="loc-link" href="%s"%s>%s</a>',
                $this->escape($href),
                $locationAttribute,
                $this->escape($text),
            );
        }

        return sprintf('<span class="loc-link" tabindex="0"%s>%s</span>', $locationAttribute, $this->escape($text));
    }

    /**
     * Build the editor-link URL for the configured editor, or null when disabled.
     *
     * @param string   $filePath - Path to open; resolved to absolute before being encoded into the editor URL.
     * @param int|null $line - Target line for the editor to jump to, or null to open the file without a line anchor.
     *
     * @return string|null - the vscode:// or phpstorm:// URL opening the file at the line, or null when editor links are off or the configured
     *                     editor is unsupported
     */
    private function editorHref(string $filePath, ?int $line): ?string
    {
        if ($this->editorLink === 'none') {
            return null;
        }

        $absolutePath = $this->absolutePath($filePath);

        return match ($this->editorLink) {
            'vscode' => $this->vscodeHref($absolutePath, $line),
            'phpstorm' => 'phpstorm://open?file=' . rawurlencode($absolutePath) . ($line === null ? '' : '&line=' . $line),
            default => null,
        };
    }

    /**
     * Build a VS Code file protocol URL for Unix, Windows drive, and UNC paths.
     *
     * @param string   $absolutePath - Absolute filesystem path; separators are normalised and each segment URL-encoded.
     * @param int|null $line - Line to open at, appended as ":line", or null to open the file without a line anchor.
     *
     * @return string - a vscode://file URL with each path segment URL-encoded (Windows drive colon preserved) and the optional ":line" anchor
     *                appended
     */
    private function vscodeHref(string $absolutePath, ?int $line): string
    {
        $path            = PathHelper::normalizeSeparators($absolutePath);
        $segments        = explode('/', $path);
        $encodedSegments = [];

        foreach ($segments as $index => $segment) {
            // Match a Windows drive segment so the drive colon remains usable by VS Code.
            $encodedSegments[] = $index === 0 && preg_match('/^[A-Za-z]:$/', $segment) === 1
                ? $segment
                : rawurlencode($segment);
        }

        $encodedPath = implode('/', $encodedSegments);
        if (!str_starts_with($encodedPath, '/')) {
            $encodedPath = '/' . $encodedPath;
        }

        return 'vscode://file' . $encodedPath . ($line === null ? '' : ':' . $line);
    }

    /**
     * Resolve the absolute path of a report-relative file path, using the configured project root.
     *
     * @param string $filePath - Path from a finding; already-absolute paths pass through, relative ones join the project root.
     *
     * @return string - the path unchanged when already absolute, otherwise the relative path joined onto the project root (falling back to cwd)
     */
    private function absolutePath(string $filePath): string
    {
        if (PathHelper::isAbsolute($filePath)) {
            return $filePath;
        }

        $projectRoot = $this->projectRoot;
        if ($projectRoot === '') {
            $cwd         = getcwd();
            $projectRoot = is_string($cwd) ? $cwd : '';
        }

        return rtrim($projectRoot, '/') . '/' . ltrim($filePath, '/');
    }

    /**
     * Render the interactive filter form for findings (severity, pillar, path, text, group-by).
     *
     * @param AnalysisReport $report - Report whose findings supply the distinct pillar options and the total finding count.
     *
     * @return string - the filter form: the severity and pillar multi-selects, path and search inputs, group-by radios, and the live finding count
     */
    private function findingFilters(AnalysisReport $report): string
    {
        $pillars = [];

        foreach ($report->findings as $finding) {
            $pillars[$finding->pillar->value] = true;
        }

        ksort($pillars);

        $pillarOptions = '';
        foreach (array_keys($pillars) as $pillar) {
            $pillarOptions .= sprintf('<option value="%s">%s</option>', $this->escape($pillar), $this->escape($pillar));
        }

        return '<form class="finding-filters" data-finding-filters aria-label="Filter flagged findings">'
               . '<div class="filter-grid">'
               . '<label>Severity<select name="severity" multiple size="3">'
               . sprintf('<option value="%s">%s</option>', $this->escape('error'), $this->escape('error'))
               . sprintf('<option value="%s">%s</option>', $this->escape('warning'), $this->escape('warning'))
               . sprintf('<option value="%s">%s</option>', $this->escape('advisory'), $this->escape('advisory'))
               . '</select></label>'
               . sprintf('<label>Pillar<select name="pillar" multiple size="%d">%s</select></label>', max(2, min(6, count($pillars))), $pillarOptions)
               . '<label>Path<input name="path" type="search" autocomplete="off"></label>'
               . '<label>Search<input name="q" type="search" autocomplete="off"></label>'
               . '</div>'
               . '<fieldset class="filter-group"><legend>Group by</legend>'
               . '<label class="radio"><input type="radio" name="group" value="none" checked> none</label>'
               . '<label class="radio"><input type="radio" name="group" value="file"> file</label>'
               . '<label class="radio"><input type="radio" name="group" value="rule"> rule</label>'
               . '</fieldset>'
               . '<div class="filter-actions"><button type="button" data-clear-filters>Clear all</button>'
               . sprintf('<output class="filter-count" data-filter-count aria-live="polite">%d of %d findings shown.</output></div>', count($report->findings), count($report->findings))
               . '</form>';
    }

    /**
     * Render a single row of the diagnostics list.
     *
     * @param RunDiagnostic $diagnostic - Diagnostic whose type, message, and optional location populate the row.
     *
     * @return string - one diagnostic line: the type label, the message, and (when present) the file/line it points at
     */
    private function diagnosticRow(RunDiagnostic $diagnostic): string
    {
        return sprintf(
            '<div class="diagnostic"><span class="diagnostic-type">%s</span><span class="diagnostic-message">%s</span>%s</div>',
            $this->escape($diagnostic->type),
            $this->escape($diagnostic->message),
            $this->diagnosticLocation($diagnostic),
        );
    }

    /**
     * Render the file-and-line span for a diagnostic, or empty when no location is set.
     *
     * @param RunDiagnostic $diagnostic - Diagnostic whose filePath or path locates it; a line is appended only with a filePath.
     *
     * @return string - the location span (filePath or path, with a line appended only alongside a filePath), or an empty string when no location is
     *                attached
     */
    private function diagnosticLocation(RunDiagnostic $diagnostic): string
    {
        $location = $diagnostic->filePath ?? $diagnostic->path;

        if ($location === null) {
            return '';
        }

        if ($diagnostic->filePath !== null && $diagnostic->line !== null) {
            $location .= ':' . $diagnostic->line;
        }

        return sprintf('<span class="diagnostic-location">%s</span>', $this->escape($location));
    }

    /**
     * Escape a value for safe insertion into HTML attribute or text content.
     *
     * @param string $text - Untrusted text (path, message, label) to neutralise before it reaches the document.
     *
     * @return string - the text with HTML special characters and quotes escaped (and invalid bytes substituted) so it is safe in both attribute and
     *                text contexts
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
