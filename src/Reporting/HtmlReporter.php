<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Finding\Finding;
use GruffPhp\Scoring\FileScore;
use GruffPhp\Scoring\PillarScore;
use GruffPhp\Support\PathHelper;

/**
 * Builds the interactive HTML inspection report.
 */
final readonly class HtmlReporter
{
    /**
     * Build the HTML reporter with the project root and editor-link preferences.
     *
     * @param string $projectRoot Project root used to build editor links.
     * @param string $editorLink  Editor-link mode used in finding rows.
     * @param bool   $interactive Whether interactive filtering controls should be included.
     */
    public function __construct(
        private string $projectRoot = '',
        private string $editorLink = 'none',
        private bool $interactive = false,
    ) {
    }

    /**
     * Render the full inspection report as a single HTML document.
     *
     * @param AnalysisReport $report Analysis report to render.
     * @return string The rendered HTML document.
     */
    public function render(AnalysisReport $report): string
    {
        $score        = $report->score;
        $grade        = $score?->composite->letter ?? 'n/a';
        $numericScore = $score === null ? 'n/a' : sprintf('%.2f / 100', $score->composite->score);
        $counts       = $report->findingCounts();
        $title        = sprintf('gruff-php inspection report - %s', $grade);
        $script       = $this->interactive
            ? '<script type="module">' . $this->interactiveScript() . '</script>' . PHP_EOL
            : '';

        return '<!DOCTYPE html>' . PHP_EOL
            . '<html lang="en-NZ">' . PHP_EOL
            . '<head>' . PHP_EOL
            . '<meta charset="UTF-8">' . PHP_EOL
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . PHP_EOL
            . sprintf('<title>%s</title>', $this->escape($title)) . PHP_EOL
            . '<style>' . $this->css($report->diagnostics !== []) . '</style>' . PHP_EOL
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
     * @return string HTML for the report header.
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
     * @return string HTML for the diagnostics section.
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
     * @param array{advisory: int, warning: int, error: int, total: int} $counts
     * @return string HTML verdict section.
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
            . $this->stat((string) $counts['total'], 'findings', '')
            . $this->stat((string) $counts['error'], 'errors', 'fail')
            . $this->stat((string) $counts['warning'], 'warnings', 'warn')
            . $this->stat((string) $counts['advisory'], 'advisories', 'note')
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
     * @return string HTML for the pillars section.
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
     * @return string HTML for the offenders section.
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
     * @return string HTML for the chart section.
     */
    private function distribution(AnalysisReport $report): string
    {
        $distribution = $report->score === null ? [] : $report->score->complexityDistribution;
        $maximumCount = max(1, ...array_values($distribution === [] ? [0] : $distribution));
        $bars         = '';
        $axis         = '';

        foreach ($distribution as $label => $count) {
            $height = max(4, (int) round(($count / $maximumCount) * 100));
            $class  = in_array($label, ['16-20', '21+'], true) ? ' fail' : (in_array($label, ['11-15'], true) ? ' warn' : '');
            $bars .= sprintf('<div class="bar%s" style="height:%d%%;"><span class="count">%d</span></div>', $class, $height, $count);
            $axis .= sprintf('<span>%s</span>', $this->escape($label));
        }

        return '<section class="chart-section"><h2 class="section-head">distribution <span class="aside">cyclomatic complexity</span></h2>'
            . sprintf('<p class="chart-summary">%s</p>', $this->escape($this->cyclomaticSummary($distribution)))
            . '<div class="chart-card"><div class="title">cyclomatic complexity · flagged methods</div>'
            . '<div class="histogram">' . $bars . '</div><div class="histogram-axis">' . $axis . '</div></div></section>';
    }

    /**
     * Render the flagged-findings section with optional interactive filters.
     *
     * @return string HTML for the findings section.
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
     * @return string HTML for the footer.
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
     * @return string HTML for one pillar row.
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
     * @return list<PillarScore>
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
     * @return string The CSS tier class, or empty when the count is zero.
     */
    private function severityCellClass(int $count, string $tier): string
    {
        return $count > 0 ? $tier : '';
    }

    /**
     * Render a single row of the top-offenders table.
     *
     * @return string HTML for one offender row.
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
     * @return string HTML for one finding row.
     */
    private function findingRow(Finding $finding): string
    {
        $severityClass = match ($finding->severity->value) {
            'error' => 'fail',
            'warning' => 'warn',
            default => 'note',
        };
        $attributes = $this->interactive ? sprintf(
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
     * @return string HTML for one meta row.
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
     * @return string HTML for one statistic block.
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
     * @return string HTML score context, or empty when no score exists.
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
                foreach ($report->baseline->staleEntries as $resolvedEntry) {
                    $items[] = sprintf(
                        'Resolved: %s %s%s',
                        $resolvedEntry->ruleId,
                        $resolvedEntry->filePath,
                        $resolvedEntry->line !== null ? ':' . $resolvedEntry->line : '',
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
     * @return string The integer as a decimal string, or "n/a".
     */
    private function optionalInt(?int $integer): string
    {
        return $integer === null ? 'n/a' : (string) $integer;
    }

    /**
     * @param array{advisory: int, warning: int, error: int, total: int} $counts
     * @return string Human-readable verdict summary sentence.
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
     * @param array<string, int> $distribution
     * @return string Human-readable cyclomatic complexity summary.
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
     * @return string HTML for the location markup.
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
     * @return string|null The editor-protocol URL, or null when editor links are off or the editor is unsupported.
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
     * @return string VS Code file URL.
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
     * @return string The absolute filesystem path.
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
     * @return string HTML for the filter form.
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
     * @return string HTML for one diagnostic row.
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
     * @return string HTML for the location span, or an empty string when absent.
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
     * @return string The escaped value.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Inline JavaScript that powers the interactive finding filters (severity, pillar, path, text, group-by).
     *
     * @return string The inline JavaScript body.
     */
    private function interactiveScript(): string
    {
        return <<<'JS'
const form=document.querySelector('[data-finding-filters]');
const list=document.querySelector('[data-findings-list]');
if(form&&list){
const severitySelect=form.elements.severity;
const pillarSelect=form.elements.pillar;
const pathInput=form.elements.path;
const queryInput=form.elements.q;
const countOutput=form.querySelector('[data-filter-count]');
const clearButton=form.querySelector('[data-clear-filters]');
const severityOrder=Array.from(severitySelect.options).map(option=>option.value);
const pillarOrder=Array.from(pillarSelect.options).map(option=>option.value);
const groupOrder=['none','file','rule'];
const source=Array.from(list.querySelectorAll('.finding')).map((node,index)=>({index,node:node.cloneNode(true),severity:node.dataset.severity||'',pillar:node.dataset.pillar||'',file:node.dataset.file||'',rule:node.dataset.rule||'',search:(node.dataset.search||'').toLowerCase()}));
function selected(select){return Array.from(select.selectedOptions).map(option=>option.value);}
function setSelected(select,values){const allowed=new Set(values);Array.from(select.options).forEach(option=>{option.selected=allowed.has(option.value);});}
function radio(){const checked=form.querySelector('input[name="group"]:checked');return checked?checked.value:'none';}
function setRadio(value){const target=groupOrder.includes(value)?value:'none';const input=form.querySelector('input[name="group"][value="'+target+'"]');if(input){input.checked=true;}}
function parseList(value,allowed){if(!value){return [];}const allowedSet=new Set(allowed);return value.split(',').map(item=>item.trim()).filter(item=>allowedSet.has(item));}
function readHash(){const params=new URLSearchParams(window.location.hash.replace(/^#/,''));setSelected(severitySelect,parseList(params.get('severity'),severityOrder));setSelected(pillarSelect,parseList(params.get('pillar'),pillarOrder));pathInput.value=params.get('path')||'';queryInput.value=params.get('q')||'';setRadio(params.get('group')||'none');}
function filters(){return{severity:selected(severitySelect),pillar:selected(pillarSelect),path:pathInput.value.trim().toLowerCase(),q:queryInput.value.trim().toLowerCase(),group:radio()};}
function writeHash(){const state=filters();const parts=[];const orderedSeverity=severityOrder.filter(value=>state.severity.includes(value));const orderedPillar=pillarOrder.filter(value=>state.pillar.includes(value));if(orderedSeverity.length){parts.push('severity='+orderedSeverity.map(encodeURIComponent).join(','));}if(orderedPillar.length){parts.push('pillar='+orderedPillar.map(encodeURIComponent).join(','));}if(state.path){parts.push('path='+encodeURIComponent(state.path));}if(state.q){parts.push('q='+encodeURIComponent(state.q));}if(state.group!=='none'){parts.push('group='+encodeURIComponent(state.group));}const next=parts.length?'#'+parts.join('&'):window.location.pathname+window.location.search;history.replaceState(null,'',next);}
function matches(item,state){return(state.severity.length===0||state.severity.includes(item.severity))&&(state.pillar.length===0||state.pillar.includes(item.pillar))&&(state.path===''||item.file.toLowerCase().includes(state.path))&&(state.q===''||item.search.includes(state.q));}
function emptyNode(text){const node=document.createElement('div');node.className='empty';node.textContent=text;return node;}
function groupTitle(value){const node=document.createElement('h3');node.className='finding-group-title';node.textContent=value;return node;}
function render(){const state=filters();const visible=source.filter(item=>matches(item,state));list.replaceChildren();if(visible.length===0){list.append(emptyNode(source.length===0?'No findings.':'No findings match the active filters.'));}else if(state.group==='none'){visible.forEach(item=>list.append(item.node.cloneNode(true)));}else{const groups=new Map();visible.forEach(item=>{const key=state.group==='file'?item.file:item.rule;if(!groups.has(key)){groups.set(key,[]);}groups.get(key).push(item);});groups.forEach((items,key)=>{const section=document.createElement('section');section.className='finding-group';section.append(groupTitle(key));items.forEach(item=>section.append(item.node.cloneNode(true)));list.append(section);});}if(countOutput){countOutput.textContent=visible.length+' of '+source.length+' findings shown.';}}
function update(){writeHash();render();}
form.addEventListener('change',update);
form.addEventListener('input',event=>{if(event.target===pathInput||event.target===queryInput){update();}});
if(clearButton){clearButton.addEventListener('click',()=>{setSelected(severitySelect,[]);setSelected(pillarSelect,[]);pathInput.value='';queryInput.value='';setRadio('none');update();});}
window.addEventListener('hashchange',()=>{readHash();render();});
readHash();
render();
}
JS;
    }

    /**
     * Inline CSS for the report; appends diagnostic-specific rules when present.
     *
     * @return string The inline stylesheet body.
     */
    private function css(bool $hasDiagnostics = false): string
    {
        $css = <<<'CSS'
:root{--ink:#0d0c0a;--ink-2:#161412;--ink-3:#1f1c19;--paper:#f3e9d2;--paper-dim:#b5ab94;--paper-mute:#7d735f;--rule:#2a2622;--forge:#e85d04;--grade-a:#7fa15a;--grade-b:#b8b450;--grade-c:#d08c36;--grade-d:#c2552b;--grade-f:#8b2828;--advisory:#b5ab94;--serif:Georgia,'Iowan Old Style',serif;--mono:'JetBrains Mono','IBM Plex Mono',ui-monospace,monospace}*{box-sizing:border-box;margin:0;padding:0}html{background:var(--ink);scrollbar-gutter:stable}body{font-family:var(--mono);color:var(--paper);background:var(--ink);min-height:100vh;line-height:1.5;font-size:14px;padding:48px 32px}.paper{max-width:1180px;margin:0 auto 24px;background:var(--ink-2);border:1px solid var(--rule);position:relative;padding:56px 64px 48px;scrollbar-gutter:stable}.corner-tr,.corner-bl,.paper:before,.paper:after{content:'';position:absolute;width:22px;height:22px;border:1px solid var(--forge)}.paper:before{top:12px;left:12px;border-right:0;border-bottom:0}.paper:after{bottom:12px;right:12px;border-left:0;border-top:0}.corner-tr{top:12px;right:12px;border-left:0;border-bottom:0}.corner-bl{bottom:12px;left:12px;border-right:0;border-top:0}.masthead{display:grid;grid-template-columns:1fr auto;gap:32px;padding-bottom:28px;border-bottom:1px solid var(--rule);align-items:end}.wordmark{font-family:var(--serif);font-weight:900;font-size:96px;line-height:.85;color:var(--paper);font-style:italic}.wordmark:after{content:'·php';color:var(--forge);font-style:normal;font-size:.45em;margin-left:.15em;vertical-align:super}.tagline{margin-top:12px;font-size:11px;letter-spacing:.24em;color:var(--paper-mute);text-transform:uppercase}.meta{text-align:right;font-size:11px;color:var(--paper-dim);line-height:1.9}.label{color:var(--paper-mute);text-transform:uppercase;letter-spacing:.16em;margin-right:8px}.val{color:var(--paper)}.inspection-id{margin-top:10px;color:var(--forge);font-weight:700;font-size:12px;letter-spacing:.1em}.section-head{font-size:11px;letter-spacing:.32em;color:var(--paper-mute);text-transform:uppercase;padding-bottom:16px;margin-bottom:20px;border-bottom:1px solid var(--rule);display:flex;justify-content:space-between;align-items:baseline;font-family:var(--mono);font-weight:500;line-height:1.5}.section-head:before{content:'§';margin-right:10px;color:var(--forge);font-family:var(--serif);font-size:14px;font-style:italic}.aside{color:var(--paper-mute);font-size:10px;letter-spacing:.24em}.verdict{display:grid;grid-template-columns:auto 1fr;gap:56px;padding:48px 0;border-bottom:1px solid var(--rule);align-items:center}.grade-stamp{width:220px;height:220px;border:3px solid var(--grade-b);color:var(--grade-b);display:flex;flex-direction:column;align-items:center;justify-content:center;transform:rotate(-4deg)}.grade-letter{font-family:var(--serif);font-style:italic;font-weight:900;font-size:112px;line-height:1}.grade-score{font-size:13px;letter-spacing:.1em}.verdict-body{display:flex;flex-direction:column;gap:18px}.verdict-headline{font-family:var(--serif);font-style:italic;font-weight:600;font-size:38px;line-height:1.15}.verdict-headline em{color:var(--forge)}.verdict-stats{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--rule);padding-top:20px}.stat{border-right:1px solid var(--rule);padding:0 18px}.stat:first-child{padding-left:0}.stat:last-child{border-right:0}.verdict-stats .num{font-family:var(--serif);font-weight:800;font-size:32px;line-height:1}.verdict-stats .num.warn{color:var(--grade-c)}.verdict-stats .num.fail{color:var(--grade-f)}.verdict-stats .num.note{color:var(--advisory)}.lbl{font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--paper-mute);margin-top:8px}.score-context{border-top:1px solid var(--rule);padding-top:16px;color:var(--paper-dim);font-size:12px}.score-context-title{font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:var(--paper-mute);margin-bottom:8px}.score-context ul{display:grid;gap:6px;margin-left:18px}.pillars,.offenders,.chart-section{padding:48px 0;border-bottom:1px solid var(--rule)}.pillar-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--rule);border:1px solid var(--rule)}.pillar{background:var(--ink-2);padding:24px 20px;display:flex;flex-direction:column;gap:14px}.pillar .name{font-size:10px;text-transform:uppercase;letter-spacing:.24em;color:var(--paper-mute)}.pillar .grade{font-family:var(--serif);font-weight:800;font-style:italic;font-size:52px;line-height:.9}.grade.a,.grade-pill.a{color:var(--grade-a)}.grade.b,.grade-pill.b{color:var(--grade-b)}.grade.c,.grade-pill.c{color:var(--grade-c)}.grade.d,.grade-pill.d{color:var(--grade-d)}.grade.f,.grade-pill.f{color:var(--grade-f)}.breakdown{font-size:11px;color:var(--paper-dim);line-height:1.7}.row{display:flex;justify-content:space-between;gap:8px}.key{color:var(--paper-mute)}table{width:100%;border-collapse:collapse;font-size:13px;table-layout:auto;font-family:var(--mono)}th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:var(--paper-mute);font-weight:500;padding:12px 14px 12px 0;border-bottom:1px solid var(--rule)}th:last-child,td:last-child{padding-right:0}th.num,td.num{text-align:right;padding-left:18px}td{padding:14px 14px 14px 0;border-bottom:1px solid var(--ink-3);color:var(--paper-dim);font-size:13px;font-family:var(--mono);font-weight:500;line-height:1.4}td.num{color:var(--paper);font-variant-numeric:tabular-nums}.file-path{color:var(--paper);font-weight:500}.grade-pill{display:inline-block;font-family:var(--serif);font-style:italic;font-weight:800;font-size:18px;line-height:1;padding:4px 10px;border:1.5px solid currentColor;min-width:36px;text-align:center}.chart-summary{color:var(--paper-dim);font-size:12px;margin:-6px 0 18px}.chart-card{border:1px solid var(--rule);padding:24px;background:var(--ink-3)}.title{font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--paper-mute);margin-bottom:24px}.histogram{display:flex;align-items:flex-end;gap:6px;height:180px;padding-bottom:20px;border-bottom:1px solid var(--rule)}.bar{flex:1;background:var(--forge);position:relative;min-height:4px}.bar.warn{background:var(--grade-c)}.bar.fail{background:var(--grade-f)}.bar .count{position:absolute;top:-22px;left:50%;transform:translateX(-50%);font-size:11px}.histogram-axis{display:flex;gap:6px;margin-top:8px;font-size:10px;color:var(--paper-mute)}.histogram-axis span{flex:1;text-align:center}.findings{padding:48px 0}.finding{display:grid;grid-template-columns:auto 1fr auto;gap:24px;padding:18px 0;border-bottom:1px solid var(--ink-3);align-items:start}.severity{font-size:9px;text-transform:uppercase;letter-spacing:.24em;padding:4px 10px;border:1px solid currentColor;margin-top:2px;min-width:76px;text-align:center}.severity.fail{color:var(--grade-f)}.severity.warn{color:var(--grade-c)}.severity.note{color:var(--paper-mute)}.rule{font-size:10px;color:var(--forge);text-transform:uppercase;letter-spacing:.16em;margin-bottom:6px;font-family:var(--mono);font-weight:700;line-height:1.5}.msg{font-family:var(--serif);font-weight:500;font-size:17px;color:var(--paper);line-height:1.4}.loc{font-size:11px;color:var(--paper-mute);margin-top:8px}.loc code{color:var(--paper-dim);background:var(--ink-3);padding:1px 6px;border:1px solid var(--rule)}.loc-link{color:inherit;text-decoration:none}.loc-link[href]{text-decoration:underline;text-decoration-color:var(--rule);text-underline-offset:3px}.loc-link:focus-visible{outline:2px solid var(--forge);outline-offset:3px}.points{font-size:10px;color:var(--paper-mute);text-align:right;letter-spacing:.1em;min-width:96px;padding-left:12px}.empty{color:var(--paper-dim);font-size:12px}.footer{margin-top:48px;padding-top:24px;border-top:1px solid var(--rule);display:grid;grid-template-columns:1fr auto 1fr;gap:24px;align-items:center;font-size:10px;color:var(--paper-mute);letter-spacing:.12em;text-transform:uppercase}.center{font-family:var(--serif);font-style:italic;font-size:13px;color:var(--paper-dim);text-transform:none;letter-spacing:0}.right{text-align:right}@media(max-width:900px){body{padding:16px}.paper{padding:28px 20px}.wordmark{font-size:64px}.masthead,.verdict{grid-template-columns:1fr}.meta{text-align:left}.grade-stamp{margin:0 auto}.pillar-grid{grid-template-columns:repeat(2,1fr)}.verdict-stats{grid-template-columns:repeat(2,1fr);gap:16px}.stat{border-right:0;padding:0}.verdict-headline{font-size:28px}}
CSS;

        if ($hasDiagnostics) {
            $css .= <<<'CSS'
.diagnostics{padding:28px 0 0}.diagnostic-list{display:grid;gap:10px}.diagnostic{display:grid;grid-template-columns:auto 1fr;gap:10px 14px;border:1px solid var(--rule);background:var(--ink-3);padding:12px 14px;color:var(--paper-dim);font-size:12px}.diagnostic-type{text-transform:uppercase;letter-spacing:.14em;color:var(--forge);font-size:10px}.diagnostic-location{grid-column:2;color:var(--paper-mute);font-size:11px}
CSS;
        }

        if (!$this->interactive) {
            return $css;
        }

        return $css . <<<'CSS'
.finding-filters{border:1px solid var(--rule);background:var(--ink-3);padding:18px;margin:0 0 22px;display:grid;gap:16px}.filter-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.finding-filters label,.filter-group legend{display:flex;flex-direction:column;gap:7px;color:var(--paper-mute);font-size:10px;text-transform:uppercase;letter-spacing:.14em}.finding-filters input,.finding-filters select{width:100%;border:1px solid var(--rule);background:var(--ink);color:var(--paper);padding:8px 10px;font:12px var(--mono)}.finding-filters select{min-height:96px}.finding-filters input:focus-visible,.finding-filters select:focus-visible,.finding-filters button:focus-visible{outline:2px solid var(--forge);outline-offset:3px}.filter-group{border:0;display:flex;align-items:center;gap:14px;flex-wrap:wrap}.filter-group legend{margin-right:4px}.filter-group .radio{flex-direction:row;align-items:center;text-transform:none;letter-spacing:0;font-size:12px;color:var(--paper-dim)}.filter-group input{width:auto}.filter-actions{display:flex;justify-content:space-between;align-items:center;gap:16px}.filter-actions button{border:1px solid var(--forge);background:var(--forge);color:var(--ink);padding:9px 12px;font:700 12px var(--mono);cursor:pointer}.filter-count{color:var(--paper-dim);font-size:12px}.finding-group{border-top:1px solid var(--rule);padding-top:10px}.finding-group-title{font:700 11px var(--mono);letter-spacing:.14em;text-transform:uppercase;color:var(--paper-dim);margin:12px 0 2px}@media(max-width:900px){.filter-grid{grid-template-columns:1fr 1fr}.filter-actions{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.filter-grid{grid-template-columns:1fr}.finding{grid-template-columns:1fr}.points{text-align:left;padding-left:0}}
CSS;
    }
}
