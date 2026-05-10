<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Finding;
use GruffPhp\Scoring\FileScore;
use GruffPhp\Scoring\PillarScore;

final readonly class HtmlReporter
{
    public function render(AnalysisReport $report): string
    {
        $score = $report->score;
        $grade = $score?->composite->letter ?? 'n/a';
        $numericScore = $score === null ? 'n/a' : sprintf('%.2f / 100', $score->composite->score);
        $counts = $report->findingCounts();
        $title = sprintf('gruff-php inspection report - %s', $grade);

        return '<!DOCTYPE html>' . PHP_EOL
            . '<html lang="en-NZ">' . PHP_EOL
            . '<head>' . PHP_EOL
            . '<meta charset="UTF-8">' . PHP_EOL
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . PHP_EOL
            . sprintf('<title>%s</title>', $this->escape($title)) . PHP_EOL
            . '<style>' . $this->css() . '</style>' . PHP_EOL
            . '</head>' . PHP_EOL
            . '<body>' . PHP_EOL
            . '<div class="paper"><span class="corner-tr"></span><span class="corner-bl"></span>'
            . $this->masthead($report)
            . $this->verdict($grade, $numericScore, $counts)
            . $this->pillars($report)
            . $this->offenders($report)
            . $this->distribution($report)
            . $this->findings($report)
            . $this->footer($report)
            . '</div>' . PHP_EOL
            . '</body>' . PHP_EOL
            . '</html>' . PHP_EOL;
    }

    private function masthead(AnalysisReport $report): string
    {
        $paths = $report->requestedPaths === [] ? ['.'] : $report->requestedPaths;
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
            . sprintf('<div class="inspection-id">%s</div>', $this->escape('gruff ' . $report->toolVersion))
            . '</div></header>';
    }

    /**
     * @param array{advisory: int, warning: int, error: int, total: int} $counts
     */
    private function verdict(string $grade, string $numericScore, array $counts): string
    {
        return '<section class="verdict">'
            . '<div class="grade-stamp">'
            . sprintf('<div class="grade-letter">%s</div>', $this->escape($grade))
            . sprintf('<div class="grade-score">%s</div>', $this->escape($numericScore))
            . '</div>'
            . '<div class="verdict-body">'
            . '<div class="verdict-headline">Inspection complete.<br><em>Quality signal is scored from current findings.</em></div>'
            . '<div class="verdict-stats">'
            . $this->stat((string) $counts['total'], 'findings', '')
            . $this->stat((string) $counts['error'], 'blocked', 'fail')
            . $this->stat((string) $counts['warning'], 'warned', 'warn')
            . $this->stat((string) $counts['advisory'], 'noted', 'ok')
            . '</div></div></section>';
    }

    private function pillars(AnalysisReport $report): string
    {
        $items = $report->score === null ? [] : $report->score->pillars;
        $html = '<section class="pillars"><div class="section-head">pillar grades <span class="aside">weighted composite</span></div><div class="pillar-grid">';

        foreach ($items as $pillar) {
            if (strtolower($pillar->pillar) === 'mutation') {
                continue;
            }

            $html .= $this->pillarCard($pillar);
        }

        return $html . '</div></section>';
    }

    private function offenders(AnalysisReport $report): string
    {
        $items = $report->score === null ? [] : $report->score->topOffenders;
        $html = '<section class="offenders"><div class="section-head">top offenders <span class="aside">sorted by score</span></div>'
            . '<table class="offender-list"><thead><tr><th>file</th><th class="num">cyclo</th><th class="num">cognit.</th><th class="num">LOC</th><th class="num">findings</th><th class="num">grade</th></tr></thead><tbody>';

        if ($items === []) {
            $html .= '<tr><td colspan="6">No offenders found.</td></tr>';
        }

        foreach ($items as $item) {
            $html .= $this->offenderRow($item);
        }

        return $html . '</tbody></table></section>';
    }

    private function distribution(AnalysisReport $report): string
    {
        $distribution = $report->score === null ? [] : $report->score->complexityDistribution;
        $max = max(1, ...array_values($distribution === [] ? [0] : $distribution));
        $bars = '';
        $axis = '';

        foreach ($distribution as $label => $count) {
            $height = max(4, (int) round(($count / $max) * 100));
            $class = in_array($label, ['16-20', '21+'], true) ? ' fail' : (in_array($label, ['11-15'], true) ? ' warn' : '');
            $bars .= sprintf('<div class="bar%s" style="height:%d%%;"><span class="count">%d</span></div>', $class, $height, $count);
            $axis .= sprintf('<span>%s</span>', $this->escape($label));
        }

        return '<section class="chart-section"><div class="section-head">distribution <span class="aside">cyclomatic complexity</span></div>'
            . '<div class="chart-card"><div class="title">cyclomatic complexity · flagged methods</div>'
            . '<div class="histogram">' . $bars . '</div><div class="histogram-axis">' . $axis . '</div></div></section>';
    }

    private function findings(AnalysisReport $report): string
    {
        $html = sprintf(
            '<section class="findings"><div class="section-head">flagged findings <span class="aside">%d shown</span></div><div class="findings-list">',
            count($report->findings),
        );

        if ($report->findings === []) {
            $html .= '<div class="empty">No findings.</div>';
        }

        foreach ($report->findings as $finding) {
            $html .= $this->findingRow($finding);
        }

        return $html . '</div></section>';
    }

    private function footer(AnalysisReport $report): string
    {
        return '<footer class="footer">'
            . sprintf('<div class="left">gruff-php · v%s</div>', $this->escape($report->toolVersion))
            . '<div class="center">strong opinions, opinionated defaults</div>'
            . sprintf('<div class="right">schema · %s</div>', $this->escape(AnalysisReport::SCHEMA_VERSION))
            . '</footer>';
    }

    private function pillarCard(PillarScore $pillar): string
    {
        $grade = $pillar->grade === null ? 'n/a' : $pillar->grade->letter;
        $gradeClass = strtolower($grade[0] ?? 'n');
        $score = $pillar->grade === null ? 'not applicable' : sprintf('%.2f', $pillar->grade->score);

        return '<div class="pillar">'
            . sprintf('<div class="name">%s</div>', $this->escape($pillar->pillar))
            . sprintf('<div class="grade %s">%s</div>', $this->escape($gradeClass), $this->escape($grade))
            . '<div class="breakdown">'
            . sprintf('<div class="row"><span class="key">score</span><span class="val">%s</span></div>', $this->escape($score))
            . sprintf('<div class="row"><span class="key">findings</span><span class="val">%d</span></div>', $pillar->findings)
            . sprintf('<div class="row"><span class="key">errors/warnings</span><span class="val">%d / %d</span></div>', $pillar->errors, $pillar->warnings)
            . '</div></div>';
    }

    private function offenderRow(FileScore $file): string
    {
        return '<tr>'
            . sprintf('<td class="file-path">%s</td>', $this->escape($file->filePath))
            . sprintf('<td class="num">%s</td>', $this->escape($this->optionalInt($file->maxCyclomatic)))
            . sprintf('<td class="num">%s</td>', $this->escape($this->optionalInt($file->maxCognitive)))
            . sprintf('<td class="num">%s</td>', $this->escape($this->optionalInt($file->maxLines)))
            . sprintf('<td class="num">%d</td>', $file->findings)
            . sprintf('<td class="num"><span class="grade-pill %s">%s</span></td>', strtolower($file->grade->letter), $this->escape($file->grade->letter))
            . '</tr>';
    }

    private function findingRow(Finding $finding): string
    {
        $severityClass = match ($finding->severity->value) {
            'error' => 'fail',
            'warning' => 'warn',
            default => 'note',
        };
        $location = $finding->line === null ? $finding->filePath : $finding->filePath . ':' . $finding->line;

        return '<div class="finding">'
            . sprintf('<div class="severity %s">%s</div>', $severityClass, $this->escape($finding->severity->value))
            . '<div class="finding-body">'
            . sprintf('<div class="rule">%s</div>', $this->escape($finding->ruleId))
            . sprintf('<div class="msg">%s</div>', $this->escape($finding->message))
            . sprintf('<div class="loc"><code>%s</code></div>', $this->escape($location))
            . '</div>'
            . sprintf('<div class="points"><b>%s</b></div>', $this->escape($finding->pillar->value))
            . '</div>';
    }

    private function metaRow(string $label, string $value): string
    {
        return sprintf(
            '<div><span class="label">%s</span><span class="val">%s</span></div>',
            $this->escape($label),
            $this->escape($value),
        );
    }

    private function stat(string $number, string $label, string $class): string
    {
        return sprintf(
            '<div class="stat"><div class="num %s">%s</div><div class="lbl">%s</div></div>',
            $this->escape($class),
            $this->escape($number),
            $this->escape($label),
        );
    }

    private function optionalInt(?int $value): string
    {
        return $value === null ? 'n/a' : (string) $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function css(): string
    {
        return <<<'CSS'
:root{--ink:#0d0c0a;--ink-2:#161412;--ink-3:#1f1c19;--paper:#f3e9d2;--paper-dim:#b5ab94;--paper-mute:#7d735f;--rule:#2a2622;--forge:#e85d04;--grade-a:#7fa15a;--grade-b:#b8b450;--grade-c:#d08c36;--grade-d:#c2552b;--grade-f:#8b2828;--serif:Georgia,'Iowan Old Style',serif;--mono:'JetBrains Mono','IBM Plex Mono',ui-monospace,monospace}*{box-sizing:border-box;margin:0;padding:0}html{background:var(--ink);scrollbar-gutter:stable}body{font-family:var(--mono);color:var(--paper);background:var(--ink);min-height:100vh;line-height:1.5;font-size:14px;padding:48px 32px}.paper{max-width:1180px;margin:0 auto 24px;background:var(--ink-2);border:1px solid var(--rule);position:relative;padding:56px 64px 48px;scrollbar-gutter:stable}.corner-tr,.corner-bl,.paper:before,.paper:after{content:'';position:absolute;width:22px;height:22px;border:1px solid var(--forge)}.paper:before{top:12px;left:12px;border-right:0;border-bottom:0}.paper:after{bottom:12px;right:12px;border-left:0;border-top:0}.corner-tr{top:12px;right:12px;border-left:0;border-bottom:0}.corner-bl{bottom:12px;left:12px;border-right:0;border-top:0}.masthead{display:grid;grid-template-columns:1fr auto;gap:32px;padding-bottom:28px;border-bottom:1px solid var(--rule);align-items:end}.wordmark{font-family:var(--serif);font-weight:900;font-size:96px;line-height:.85;color:var(--paper);font-style:italic}.wordmark:after{content:'·php';color:var(--forge);font-style:normal;font-size:.45em;margin-left:.15em;vertical-align:super}.tagline{margin-top:12px;font-size:11px;letter-spacing:.24em;color:var(--paper-mute);text-transform:uppercase}.meta{text-align:right;font-size:11px;color:var(--paper-dim);line-height:1.9}.label{color:var(--paper-mute);text-transform:uppercase;letter-spacing:.16em;margin-right:8px}.val{color:var(--paper)}.inspection-id{margin-top:10px;color:var(--forge);font-weight:700;font-size:12px;letter-spacing:.1em}.section-head{font-size:11px;letter-spacing:.32em;color:var(--paper-mute);text-transform:uppercase;padding-bottom:16px;margin-bottom:20px;border-bottom:1px solid var(--rule);display:flex;justify-content:space-between;align-items:baseline}.section-head:before{content:'§';margin-right:10px;color:var(--forge);font-family:var(--serif);font-size:14px;font-style:italic}.aside{color:var(--paper-mute);font-size:10px;letter-spacing:.24em}.verdict{display:grid;grid-template-columns:auto 1fr;gap:56px;padding:48px 0;border-bottom:1px solid var(--rule);align-items:center}.grade-stamp{width:220px;height:220px;border:3px solid var(--grade-b);color:var(--grade-b);display:flex;flex-direction:column;align-items:center;justify-content:center;transform:rotate(-4deg)}.grade-letter{font-family:var(--serif);font-style:italic;font-weight:900;font-size:112px;line-height:1}.grade-score{font-size:13px;letter-spacing:.1em}.verdict-body{display:flex;flex-direction:column;gap:18px}.verdict-headline{font-family:var(--serif);font-style:italic;font-weight:600;font-size:38px;line-height:1.15}.verdict-headline em{color:var(--forge)}.verdict-stats{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--rule);padding-top:20px}.stat{border-right:1px solid var(--rule);padding:0 18px}.stat:first-child{padding-left:0}.stat:last-child{border-right:0}.verdict-stats .num{font-family:var(--serif);font-weight:800;font-size:32px;line-height:1}.verdict-stats .num.warn{color:var(--grade-c)}.verdict-stats .num.fail{color:var(--grade-f)}.verdict-stats .num.ok{color:var(--grade-a)}.lbl{font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--paper-mute);margin-top:8px}.pillars,.offenders,.chart-section{padding:48px 0;border-bottom:1px solid var(--rule)}.pillar-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--rule);border:1px solid var(--rule)}.pillar{background:var(--ink-2);padding:24px 20px;display:flex;flex-direction:column;gap:14px}.pillar .name{font-size:10px;text-transform:uppercase;letter-spacing:.24em;color:var(--paper-mute)}.pillar .grade{font-family:var(--serif);font-weight:800;font-style:italic;font-size:52px;line-height:.9}.grade.a,.grade-pill.a{color:var(--grade-a)}.grade.b,.grade-pill.b{color:var(--grade-b)}.grade.c,.grade-pill.c{color:var(--grade-c)}.grade.d,.grade-pill.d{color:var(--grade-d)}.grade.f,.grade-pill.f{color:var(--grade-f)}.breakdown{font-size:11px;color:var(--paper-dim);line-height:1.7}.row{display:flex;justify-content:space-between;gap:8px}.key{color:var(--paper-mute)}table{width:100%;border-collapse:collapse;font-size:13px;table-layout:auto;font-family:var(--mono)}th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:var(--paper-mute);font-weight:500;padding:12px 14px 12px 0;border-bottom:1px solid var(--rule)}th:last-child,td:last-child{padding-right:0}th.num,td.num{text-align:right;padding-left:18px}td{padding:14px 14px 14px 0;border-bottom:1px solid var(--ink-3);color:var(--paper-dim);font-size:13px;font-family:var(--mono);font-weight:500;line-height:1.4}td.num{color:var(--paper);font-variant-numeric:tabular-nums}.file-path{color:var(--paper);font-weight:500}.grade-pill{display:inline-block;font-family:var(--serif);font-style:italic;font-weight:800;font-size:18px;line-height:1;padding:4px 10px;border:1.5px solid currentColor;min-width:36px;text-align:center}.chart-card{border:1px solid var(--rule);padding:24px;background:var(--ink-3)}.title{font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--paper-mute);margin-bottom:24px}.histogram{display:flex;align-items:flex-end;gap:6px;height:180px;padding-bottom:20px;border-bottom:1px solid var(--rule)}.bar{flex:1;background:var(--forge);position:relative;min-height:4px}.bar.warn{background:var(--grade-c)}.bar.fail{background:var(--grade-f)}.bar .count{position:absolute;top:-22px;left:50%;transform:translateX(-50%);font-size:11px}.histogram-axis{display:flex;gap:6px;margin-top:8px;font-size:10px;color:var(--paper-mute)}.histogram-axis span{flex:1;text-align:center}.findings{padding:48px 0}.finding{display:grid;grid-template-columns:auto 1fr auto;gap:24px;padding:18px 0;border-bottom:1px solid var(--ink-3);align-items:start}.severity{font-size:9px;text-transform:uppercase;letter-spacing:.24em;padding:4px 10px;border:1px solid currentColor;margin-top:2px;min-width:76px;text-align:center}.severity.fail{color:var(--grade-f)}.severity.warn{color:var(--grade-c)}.severity.note{color:var(--paper-mute)}.rule{font-size:10px;color:var(--forge);text-transform:uppercase;letter-spacing:.16em;margin-bottom:6px}.msg{font-family:var(--serif);font-weight:500;font-size:17px;color:var(--paper);line-height:1.4}.loc{font-size:11px;color:var(--paper-mute);margin-top:8px}.loc code{color:var(--paper-dim);background:var(--ink-3);padding:1px 6px;border:1px solid var(--rule)}.points{font-size:10px;color:var(--paper-mute);text-align:right;letter-spacing:.1em;min-width:96px;padding-left:12px}.empty{color:var(--paper-dim);font-size:12px}.footer{margin-top:48px;padding-top:24px;border-top:1px solid var(--rule);display:grid;grid-template-columns:1fr auto 1fr;gap:24px;align-items:center;font-size:10px;color:var(--paper-mute);letter-spacing:.12em;text-transform:uppercase}.center{font-family:var(--serif);font-style:italic;font-size:13px;color:var(--paper-dim);text-transform:none;letter-spacing:0}.right{text-align:right}@media(max-width:900px){body{padding:16px}.paper{padding:28px 20px}.wordmark{font-size:64px}.masthead,.verdict{grid-template-columns:1fr}.meta{text-align:left}.grade-stamp{margin:0 auto}.pillar-grid{grid-template-columns:repeat(2,1fr)}.verdict-stats{grid-template-columns:repeat(2,1fr);gap:16px}.stat{border-right:0;padding:0}.verdict-headline{font-size:28px}}
CSS;
    }
}
