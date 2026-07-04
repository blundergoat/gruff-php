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
 * Turns a finished analysis run into the standalone HTML inspection report - the browser-opened view of
 * a scan produced by `gruff-php analyse --format html`, `gruff-php report`, or the dashboard's scan
 * button. Lays out the masthead, grade-stamp verdict, pillar and offender tables, complexity histogram,
 * and findings list into one self-contained document, HTML-escaping every untrusted value on the way in.
 */
final readonly class HtmlReporter
{
    /**
     * Captures the render choices made on the command line before any report is built: where the
     * project lives (for editor links) and whether findings should be clickable and filterable.
     *
     * @param string $projectRoot - Absolute project root used to turn report-relative paths into editor links; empty falls back to the current working directory.
     * @param string $editorLink - Editor-link mode for file:line references (`vscode`, `phpstorm`, or `none`); `none` renders plain, unclickable locations.
     * @param bool   $interactive - When true, include the live filter form and its script; false renders a static report with no controls.
     */
    public function __construct(
        private string $projectRoot = '',
        private string $editorLink = 'none',
        private bool   $interactive = false,
    ) {
    }

    /**
     * Renders a whole analysis run into one self-contained HTML document - the report the user opens in a browser.
     *
     * @param AnalysisReport $report - The finished analysis run whose score, findings, and diagnostics fill the page.
     *
     * @return string - A complete standalone HTML document (doctype through closing `html`), with styles inlined and, in interactive mode, the filter script inlined too.
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
     * Builds the header the reader sees first: the gruff wordmark plus a meta panel echoing the paths,
     * scope, format, and fail threshold this run used, and the tool version that produced the report.
     *
     * @param AnalysisReport $report - Report whose requested paths, diff scope, format, and tool version label the header.
     *
     * @return string - The masthead header fragment: the brand, the resolved paths/scope/format/fail meta panel, and the tool version stamp.
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
     * Builds the diagnostics band of run messages (parse failures, skipped files) above the findings, or nothing when the run was clean.
     *
     * @param AnalysisReport $report - Report whose run diagnostics drive the section; an empty list omits it entirely.
     *
     * @return string - The diagnostics section wrapping one row per run message, or an empty string when there were no diagnostics to report.
     */
    private function diagnostics(AnalysisReport $report): string
    {
        // A clean run logged no messages, so omit the whole band rather than show an empty box.
        if ($report->diagnostics === []) {
            return '';
        }

        $html = '<section class="diagnostics"><h2 class="section-head">diagnostics <span class="aside">run messages</span></h2><div class="diagnostic-list">';

        // One row per run message (parse errors, skipped files) so the reader sees what the scan couldn't reach.
        foreach ($report->diagnostics as $diagnostic) {
            $html .= $this->diagnosticRow($diagnostic);
        }

        return $html . '</div></section>';
    }

    /**
     * Builds the verdict banner - the big grade stamp, the one-line headline, the severity stat tiles, and the score-driver notes.
     *
     * @param string                                                     $grade - Composite letter grade already resolved by the caller; rendered into the grade stamp.
     * @param string                                                     $numericScore - Pre-formatted "NN.NN / 100" score string, or "n/a" when the run computed no score.
     * @param array{advisory: int, warning: int, error: int, total: int} $counts - Severity tallies for the visible findings, filling the stat tiles.
     * @param AnalysisReport                                             $report - Report supplying the per-pillar context for the one-line verdict sentence and the score drivers.
     *
     * @return string - The verdict section pairing the grade stamp with the summary headline, the severity stat tiles, and the score-driver notes.
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
     * Builds the canonical pillars table - one graded row per quality pillar, worst-first, with mutation kept in the findings list instead.
     *
     * @param AnalysisReport $report - Report whose applicable pillar scores populate the table (mutation excluded).
     *
     * @return string - The pillars section table, one row per applicable pillar, or a "No pillars." placeholder row when none apply.
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

        // No pillar rows to show (no score, or every pillar was non-applicable or excluded) - show one placeholder row so the table isn't left headless.
        if ($rows === []) {
            $html .= '<tr><td colspan="7">No pillars.</td></tr>';
        }

        // One row per applicable pillar, already ordered worst-first, so the reader scans the trouble spots top-down.
        foreach ($rows as $pillar) {
            $html .= $this->pillarRow($pillar);
        }

        return $html . '</tbody></table></section>';
    }

    /**
     * Builds the top-offenders table: the files dragging the grade down the most, ranked by score so
     * the reader knows exactly where to start cleaning up.
     *
     * @param AnalysisReport $report - Report whose score supplies the ranked offender files; a null score yields an empty table.
     *
     * @return string - The top-offenders section table, one row per ranked file, or a "No offenders found." placeholder when there are none.
     */
    private function offenders(AnalysisReport $report): string
    {
        $items = $report->score === null ? [] : $report->score->topOffenders;
        $html  = '<section class="offenders"><h2 class="section-head">top offenders <span class="aside">sorted by score</span></h2>'
                 . '<table class="offender-list"><thead><tr><th scope="col">file</th><th scope="col" class="num">cyclo</th><th scope="col" class="num">cognit.</th><th scope="col" class="num">LOC</th><th scope="col" class="num">findings</th><th scope="col" class="num">grade</th></tr></thead><tbody>';

        // Nothing ranked (a clean or unscored run) still needs a row, so show a "none found" placeholder.
        if ($items === []) {
            $html .= '<tr><td colspan="6">No offenders found.</td></tr>';
        }

        // One row per ranked file, worst score first, so the messiest files sit at the top of the list.
        foreach ($items as $item) {
            $html .= $this->offenderRow($item);
        }

        return $html . '</tbody></table></section>';
    }

    /**
     * Builds the cyclomatic-complexity histogram - one bar per complexity bucket, its height set by how many methods fall in that bucket and its colour reddening for the higher-complexity buckets.
     *
     * @param AnalysisReport $report - Report whose complexity distribution buckets become histogram bars; empty renders a flat chart.
     *
     * @return string - The distribution chart section: the summary sentence, one histogram bar per CC bucket, and the bucket-label axis.
     */
    private function distribution(AnalysisReport $report): string
    {
        $distribution = $report->score === null ? [] : $report->score->complexityDistribution;
        $maximumCount = max(1, ...array_values($distribution === [] ? [0] : $distribution));
        $bars         = '';
        $axis         = '';

        // Scale each bucket into a bar height and tint the higher-complexity buckets warn/fail, so hot spots pop out.
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
     * Builds the flagged-findings section - one card per issue - prepending the live filter form in interactive mode.
     *
     * @param AnalysisReport $report - Report whose findings become rows; the filter form is added only in interactive mode.
     *
     * @return string - The flagged-findings section: the optional filter form, one card per finding, or a "No findings." placeholder when empty.
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

        // A clean pass has nothing to list, so show a reassuring "No findings." note instead of a blank panel.
        if ($report->findings === []) {
            $html .= '<div class="empty">No findings.</div>';
        }

        // One card per finding, in report order, so the reader can walk each flagged issue in turn.
        foreach ($report->findings as $finding) {
            $html .= $this->findingRow($finding);
        }

        return $html . '</div></section>';
    }

    /**
     * Builds the closing footer band - the tool version, the tagline, and the report schema id - so a
     * saved report always records which gruff build and schema shape produced it.
     *
     * @param AnalysisReport $report - Report supplying the tool version shown in the footer (the schema id is a class constant).
     *
     * @return string - The footer band carrying the tool version, the tagline, and the schema id.
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
     * Renders one row of the pillars table: the pillar name and a colour-coded grade pill, then its
     * score, finding total, and per-severity counts.
     *
     * @param PillarScore $pillar - Pillar score for this row; a null grade renders "n/a" and a neutral grade pill.
     *
     * @return string - One table row pairing the pillar name and grade pill with its score, finding total, and per-severity counts.
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
     * Selects and orders the pillar rows for the table: only applicable pillars, worst-first, always without mutation, reusing the existing scores.
     *
     * @param AnalysisReport $report - Report whose pillar scores are filtered and sorted; a null score yields no rows.
     *
     * @return list<PillarScore> - Applicable, mutation-excluded pillars in display order (findings DESC, then pillar ASC); empty when the run had no score.
     */
    private function pillarSummaryRows(AnalysisReport $report): array
    {
        // No score means the run never produced pillar grades, so there are simply no rows to show.
        if ($report->score === null) {
            return [];
        }

        $rows = [];
        // Sift the scored pillars down to the ones worth putting in the table.
        foreach ($report->score->pillars as $pillar) {
            // A pillar with no rules in play this run isn't graded, so leave it out of the table.
            if (!$pillar->applicable) {
                continue;
            }

            // Mutation is deliberately kept out of this table - its detail lives in the findings list instead.
            if (strtolower($pillar->pillar) === 'mutation') {
                continue;
            }

            $rows[] = $pillar;
        }

        // Order the rows worst-first (most findings), breaking ties by pillar name so the layout is stable run to run.
        usort($rows, static function (PillarScore $left, PillarScore $right): int {
            return $right->findings <=> $left->findings ?: strcmp($left->pillar, $right->pillar);
        });

        return $rows;
    }

    /**
     * Picks the colour class for a per-severity count cell, keeping zero-valued cells neutral so a clean
     * pillar reads as visually quiet instead of lit up with colour.
     *
     * @param int    $count - Number of findings at this severity; zero suppresses the colour class.
     * @param string $tier - CSS class applied when the count is positive (for example "note", "warn", "fail").
     *
     * @return string - The tier class when the count is positive, or an empty string for a zero count so the cell stays neutral.
     */
    private function severityCellClass(int $count, string $tier): string
    {
        return $count > 0 ? $tier : '';
    }

    /**
     * Renders one row of the top-offenders table: the file path as a clickable location and a grade
     * pill, alongside its cyclomatic, cognitive, LOC, and finding metrics.
     *
     * @param FileScore $file - Per-file score for this row; complexity and LOC metrics may be null and render "n/a".
     *
     * @return string - One table row pairing the file path and grade pill with its cyclomatic, cognitive, LOC, and finding metrics.
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
     * Renders one finding as a card (severity, rule, message, location, pillar); its fields also become filter data-* attributes when interactive.
     *
     * @param Finding $finding - Finding to render; its fields also become data-* filter attributes in interactive mode.
     *
     * @return string - One finding card: the severity badge, rule id, message, location, and pillar, plus filter data-* attributes in interactive mode.
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
     * Renders one label-and-value pair for the masthead meta panel (for example "paths" beside the
     * resolved path list). Escapes both halves here so callers can hand in raw text safely.
     *
     * @param string $label - Short uppercase key shown on the left (for example "paths", "scope", "format").
     * @param string $displayText - Already-resolved value text shown on the right; escaped here, not by the caller.
     *
     * @return string - One meta-panel row: the label and its value, both HTML-escaped here.
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
     * Renders one stat tile in the verdict grid: a big number over a caption, tinted by severity. These
     * are the totals the reader eyeballs first - findings, errors, warnings, advisories.
     *
     * @param string $number - Pre-stringified count shown large (the caller casts the integer total/severity tally).
     * @param string $label - Lowercase caption under the number (for example "findings", "errors").
     * @param string $class - Severity colour class for the number ("fail", "warn", "note"), or empty for the neutral total.
     *
     * @return string - One stat tile: the large count, its caption, and the optional severity colour class on the number.
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
     * Gathers the plain-English "score drivers" notes shown under the verdict - why the grade landed where it did.
     *
     * @param AnalysisReport $report - Report whose score, diff, baseline, filters, and mutation inputs become driver notes.
     *
     * @return string - The "score drivers" list of explanation, diff, baseline-movement, filter, and mutation notes, or an empty string when there was no score.
     */
    private function scoreContext(AnalysisReport $report): string
    {
        // No score means there are no drivers to explain, so drop the whole notes block.
        if ($report->score === null) {
            return '';
        }

        $items = [$report->score->explanation];

        // A changed-files run (say `analyse --diff`) adds a note explaining that only the diff was scored.
        if ($report->diff !== null && $report->diff->active) {
            $items[] = $report->diff->message;
        }

        // With a baseline in play, spell out how many findings are new, unchanged, or resolved against it.
        if ($report->baseline !== null) {
            $items[] = sprintf(
                'Baseline movement: %d new, %d unchanged, %d resolved; unchanged findings are accepted debt removed before scoring.',
                $report->baseline->newCount,
                $report->baseline->unchangedCount,
                $report->baseline->absentCount,
            );

            // Only when the reader asked to see resolved items do we list each cleaned-up group by name.
            if ($report->shouldListAbsentBaseline) {
                // One "Resolved:" note per fixed finding group, so the report visibly credits the cleanup work.
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

        // If display filters are active, warn that they only thin what's shown - the score still uses every finding.
        if ($report->filters !== null && $report->filters->isActive()) {
            $items[] = 'Display filters affect rendered findings only; score and exit code use the scored finding set.';
        }

        // A mutation-testing input adds a note that its MSI fed the score, since the HTML shows no separate mutation panel.
        if ($report->mutation !== null) {
            $items[] = sprintf(
                'Mutation input from %s contributes MSI to the score; HTML keeps mutation details in findings rather than a separate mutation visualization.',
                $report->mutation->report->reportPath,
            );
        }

        $list = '';
        // Wrap each collected note as a list item for the "score drivers" panel.
        foreach ($items as $item) {
            $list .= sprintf('<li>%s</li>', $this->escape($item));
        }

        return '<div class="score-context"><div class="score-context-title">score drivers</div><ul>' . $list . '</ul></div>';
    }

    /**
     * Stringifies an optional metric for an offender-table cell, turning a missing measurement into
     * "n/a" so an empty cell reads explicitly rather than looking blank.
     *
     * @param int|null $integer - Metric value, or null when the offender row has no measurement for that column.
     *
     * @return string - The integer as a decimal string, or "n/a" when the value is null so empty cells read explicitly.
     */
    private function optionalInt(?int $integer): string
    {
        return $integer === null ? 'n/a' : (string)$integer;
    }

    /**
     * Writes the one-line verdict headline: "all clear", or a count of serious findings and how many pillars they span.
     *
     * @param AnalysisReport                                             $report - Report whose findings are scanned to count the distinct pillars carrying warnings or errors.
     * @param array{advisory: int, warning: int, error: int, total: int} $counts - Severity tallies used to decide whether the summary is clean or thresholded.
     *
     * @return string - A one-line summary: the reassuring no-findings sentence, or the warning/error count and the number of pillars they span.
     */
    private function verdictSummary(AnalysisReport $report, array $counts): string
    {
        $thresholdFindings = $counts['warning'] + $counts['error'];

        // Nothing at warning or error severity is the good outcome, so say so plainly and stop.
        if ($thresholdFindings === 0) {
            return 'No warning or error findings flagged.';
        }

        $pillars = [];
        // Otherwise tally how many distinct pillars the serious findings span, to size the problem in one sentence.
        foreach ($report->findings as $finding) {
            // Advisory-only findings don't count toward this headline, so skip anything below warning severity.
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
     * Writes the one-line caption above the histogram: how many methods exceed CC 10 and how that splits across buckets.
     *
     * @param array<string, int> $distribution - Complexity bucket counts keyed by range label; absent buckets are treated as zero.
     *
     * @return string - A one-line summary of how many methods exceed CC 10, with the per-bucket split across 11-15, 16-20, and 21+.
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
     * Renders a file:line reference - a clickable editor anchor when links are on, otherwise an inert span that still copies the path.
     *
     * @param string   $filePath - Report-relative or absolute path shown to the reader and carried in data-path.
     * @param int|null $line - Line number appended after a colon, or null to show the path alone (used for file-level rows).
     *
     * @return string - The path (with optional line) as a clickable editor-link anchor when one is configured, otherwise an inert focusable span that still copies via data-path.
     */
    private function locationMarkup(string $filePath, ?int $line): string
    {
        $text              = $line === null ? $filePath : sprintf('%s:%d', $filePath, $line);
        $locationAttribute = sprintf(' data-path="%s"', $this->escape($text));
        $href              = $this->editorHref($filePath, $line);

        // With editor links on, make the location a real anchor the reader can click straight into their editor.
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
     * Builds the editor deep-link URL for a finding's file, honouring `--report-editor-link`; null when links are off or the editor is unsupported.
     *
     * @param string   $filePath - Path to open; resolved to absolute before being encoded into the editor URL.
     * @param int|null $line - Target line for the editor to jump to, or null to open the file without a line anchor.
     *
     * @return string|null - The `vscode://` or `phpstorm://` URL opening the file at the line, or null when editor links are off or the configured editor is unsupported.
     */
    private function editorHref(string $filePath, ?int $line): ?string
    {
        // The reader opted out of editor links (the default, or `--report-editor-link=none`), so emit no href at all.
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
     * Assembles a `vscode://file` URL that survives Unix, Windows-drive, and UNC paths, opening VS Code on the flagged line.
     *
     * @param string   $absolutePath - Absolute filesystem path; separators are normalised and each segment URL-encoded.
     * @param int|null $line - Line to open at, appended as ":line", or null to open the file without a line anchor.
     *
     * @return string - A `vscode://file` URL with each path segment URL-encoded (Windows drive colon preserved) and the optional ":line" anchor appended.
     */
    private function vscodeHref(string $absolutePath, ?int $line): string
    {
        $path            = PathHelper::normalizeSeparators($absolutePath);
        $segments        = explode('/', $path);
        $encodedSegments = [];

        // URL-encode each path segment so odd characters survive in the link, walking them one at a time.
        foreach ($segments as $index => $segment) {
            // Match a Windows drive segment so the drive colon remains usable by VS Code.
            $encodedSegments[] = $index === 0 && preg_match('/^[A-Za-z]:$/', $segment) === 1
                ? $segment
                : rawurlencode($segment);
        }

        $encodedPath = implode('/', $encodedSegments);
        // A Windows drive path has no leading slash, so add one to keep the `vscode://file` URL well-formed.
        if (!str_starts_with($encodedPath, '/')) {
            $encodedPath = '/' . $encodedPath;
        }

        return 'vscode://file' . $encodedPath . ($line === null ? '' : ':' . $line);
    }

    /**
     * Resolves a report-relative path to an absolute one for an editor link, anchoring it to the project root (or cwd).
     *
     * @param string $filePath - Path from a finding; already-absolute paths pass through, relative ones join the project root.
     *
     * @return string - The path unchanged when already absolute, otherwise the relative path joined onto the project root (falling back to cwd).
     */
    private function absolutePath(string $filePath): string
    {
        // An already-absolute path needs no rooting, so hand it straight back.
        if (PathHelper::isAbsolute($filePath)) {
            return $filePath;
        }

        $projectRoot = $this->projectRoot;
        // No project root was configured, so fall back to the current working directory to anchor the path.
        if ($projectRoot === '') {
            $cwd         = getcwd();
            $projectRoot = is_string($cwd) ? $cwd : '';
        }

        return rtrim($projectRoot, '/') . '/' . ltrim($filePath, '/');
    }

    /**
     * Builds the interactive filter form above the findings (severity, pillar, path, text, group-by), wired to the inline script.
     *
     * @param AnalysisReport $report - Report whose findings supply the distinct pillar options and the total finding count.
     *
     * @return string - The filter form: the severity and pillar multi-selects, path and search inputs, group-by radios, and the live finding count.
     */
    private function findingFilters(AnalysisReport $report): string
    {
        $pillars = [];

        // Collect the distinct pillars present in this run, so the filter only offers ones that can actually match.
        foreach ($report->findings as $finding) {
            $pillars[$finding->pillar->value] = true;
        }

        ksort($pillars);

        $pillarOptions = '';
        // Turn each present pillar into a dropdown option the reader can filter the findings by.
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
     * Renders one line of the diagnostics list: the message type, its text, and (when known) the
     * file:line it points at, so the reader can jump to whatever the scan flagged.
     *
     * @param RunDiagnostic $diagnostic - Diagnostic whose type, message, and optional location populate the row.
     *
     * @return string - One diagnostic line: the type label, the message, and (when present) the file/line it points at.
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
     * Renders the file:line span for a diagnostic, or nothing when the message isn't tied to a spot in
     * the code - so a general run note doesn't show a misleading location.
     *
     * @param RunDiagnostic $diagnostic - Diagnostic whose filePath or path locates it; a line is appended only with a filePath.
     *
     * @return string - The location span (filePath or path, with a line appended only alongside a filePath), or an empty string when no location is attached.
     */
    private function diagnosticLocation(RunDiagnostic $diagnostic): string
    {
        $location = $diagnostic->filePath ?? $diagnostic->path;

        // A diagnostic with no location at all (a general run note) shows no file span.
        if ($location === null) {
            return '';
        }

        // Only a real file gets a line number appended; a bare path is shown without one.
        if ($diagnostic->filePath !== null && $diagnostic->line !== null) {
            $location .= ':' . $diagnostic->line;
        }

        return sprintf('<span class="diagnostic-location">%s</span>', $this->escape($location));
    }

    /**
     * The one choke point every value passes through: HTML-escapes untrusted paths and messages so a crafted finding can't inject markup.
     *
     * @param string $text - Untrusted text (path, message, label) to neutralise before it reaches the document.
     *
     * @return string - The text with HTML special characters and quotes escaped (and invalid bytes substituted) so it is safe in both attribute and text contexts.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
