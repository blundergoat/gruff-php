<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

/**
 * Produces every HTML document the local `gruff-php dashboard` server hands back to the browser:
 * the controls-panel shell, a finished scan's report wrapped with completion metadata, an error
 * page when a scan cannot run, and the "Ready to scan" holding frame shown before the first run.
 *
 * Reach for this when a user runs `gruff-php dashboard` and opens the localhost page to configure
 * and trigger scans by clicking instead of retyping flags. The request handler builds the controls
 * page from here, and the scan runner uses it to wrap each finished report with the exit code,
 * duration, and command the status bar shows. It only assembles markup; running the scan lives elsewhere.
 */
final readonly class DashboardPageRenderer
{
    /**
     * Builds the whole dashboard page served at `/`: the controls panel pre-filled with the user's
     * current settings, plus the iframe that will hold each scan's report. This is the first thing a
     * user sees after running `gruff-php dashboard` and opening the localhost URL.
     *
     * @param array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string} $state - Current form state echoed back into the controls so the panel reopens on the user's last choices.
     *
     * @return string - Complete dashboard shell HTML.
     */
    public function dashboardHtml(array $state): string
    {
        $scanUrl = '/scan?' . http_build_query($state, '', '&', PHP_QUERY_RFC3986);

        return '<!DOCTYPE html><html lang="en-NZ"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>gruff-php dashboard</title><style>' . $this->dashboardCss() . '</style></head><body>'
            . '<button type="button" id="controls-toggle" class="controls-toggle" aria-haspopup="dialog" aria-expanded="false" aria-controls="controls-panel" title="Dashboard controls">&#9881;</button>'
            . '<section id="controls-panel" class="controls-panel" role="dialog" aria-label="Dashboard controls" hidden>'
            . '<div class="panel-head"><div><strong>Dashboard controls</strong><span>local scan settings</span></div><button type="button" id="controls-close" aria-label="Close dashboard controls">&times;</button></div>'
            . '<div class="scan-summary" aria-label="Scan status">'
            . '<div class="scan-status"><span>Status</span><strong id="scan-status" aria-live="polite">Ready</strong></div>'
            . '<div class="scan-command"><span>Last scan</span><div class="scan-meta-line"><code id="scan-meta">Not run</code><button type="button" id="copy-scan-meta">Copy</button></div></div>'
            . '</div>'
            . '<form id="scan-form" method="get" action="/">'
            . '<div class="field-stack">'
            . $this->field('Project root', 'project', $state['project'])
            . $this->field('Paths', 'paths', $state['paths'])
            . '</div>'
            . '<div class="field-grid">'
            . $this->field('Config path', 'config', $state['config'], '.gruff-php.yaml')
            . $this->field('Baseline', 'baseline', $state['baseline'], 'gruff-baseline.json')
            . '</div>'
            . '<div class="field-grid">'
            . '<label>Scan scope<select name="scanScope">'
            . $this->option('full', $state['scanScope'], 'whole branch')
            . $this->option('diff', $state['scanScope'], 'diff only')
            . '</select></label>'
            . '<label>Fail on<select name="failOn">'
            . $this->option('none', $state['failOn'])
            . $this->option('advisory', $state['failOn'])
            . $this->option('warning', $state['failOn'])
            . $this->option('error', $state['failOn'])
            . '</select></label>'
            . '</div>'
            . '<div class="option-grid">'
            . '<label class="check"><input type="checkbox" name="noConfig" value="1"' . ($state['noConfig'] === '1' ? ' checked' : '') . '><span>skip config</span></label>'
            . '<label class="check"><input type="checkbox" name="noBaseline" value="1"' . ($state['noBaseline'] === '1' ? ' checked' : '') . '><span>skip baseline</span></label>'
            . '<label class="check"><input type="checkbox" name="includeIgnored" value="1"' . ($state['includeIgnored'] === '1' ? ' checked' : '') . '><span>include ignored</span></label>'
            . '<label class="check"><input type="checkbox" name="reportInteractive" value="1"' . ($state['reportInteractive'] === '1' ? ' checked' : '') . '><span>interactive findings</span></label>'
            . '</div>'
            . '<div class="panel-actions"><button type="button" id="refresh">Refresh</button><button type="submit" id="run-scan">Run scan</button></div></form></section>'
            . sprintf('<iframe id="report-frame" title="gruff-php report" data-initial-src="%s" srcdoc="%s"></iframe>', $this->escape($scanUrl), $this->escape($this->loadingFrame()))
            . '<script>' . $this->dashboardJs() . '</script></body></html>';
    }

    /**
     * Wraps a finished scan's report HTML with a small metadata script so the dashboard shell can
     * fill its status line with the run's exit code, duration, and command. Called once per scan the
     * user triggers, just before the report is streamed into the iframe.
     *
     * @param string       $html - Report HTML produced by the scan subprocess.
     * @param string       $projectRoot - Project root the scan ran against; included in the metadata payload posted to the parent frame, though the panel does not surface it today.
     * @param list<string> $command - Full scan argv; normalised to a copy-pasteable command for display.
     * @param int          $exitCode - Scan process exit code the status line reports to the user.
     * @param int          $durationMs - Wall-clock scan time in milliseconds shown beside the exit code.
     *
     * @return string - Report HTML with the parent-frame scan-metadata script injected.
     */
    public function injectDashboardMetadata(
        string $html,
        string $projectRoot,
        array $command,
        int $exitCode,
        int $durationMs,
    ): string {
        $payload = json_encode(
            [
                'type' => 'gruff-scan-complete',
                'exitCode' => $exitCode,
                'durationMs' => $durationMs,
                'projectRoot' => $projectRoot,
                'command' => $this->displayCommand($command),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );

        // json_encode only fails on genuinely unencodable data; fall back to a bare message so the panel still receives valid JSON.
        if (!is_string($payload)) {
            $payload = '{"type":"gruff-scan-complete"}';
        }

        $metadata = '<script id="gruff-dashboard-meta" type="application/json">' . $payload . '</script>'
            . '<script>(()=>{const el=document.getElementById("gruff-dashboard-meta");if(window.parent&&el){window.parent.postMessage(JSON.parse(el.textContent),window.location.origin);}})();</script>';

        // A normal report carries a <body>, so splice the metadata script in right after it and let it run inside the document.
        if (str_contains($html, '<body>')) {
            return str_replace('<body>', '<body>' . $metadata, $html);
        }

        // An error page or bare fragment has no <body> to anchor to, so prepend the script and still deliver the report.
        return $metadata . $html;
    }

    /**
     * Renders the self-contained page the dashboard shows inside its iframe when a scan cannot run or
     * returns no report - a project path that does not resolve, or a scan that produced no output. It
     * gives the user the message, the exit code and duration, and the raw detail instead of a blank frame.
     * (A subprocess that exits non-zero but still emits HTML is shown as a normal report, not this page.)
     *
     * @param string $message - Short, human-readable summary of what went wrong.
     * @param string $detail - Fuller error text (often captured stderr) shown in the page's detail block.
     * @param int    $exitCode - Scan process exit code, surfaced so the user can tell a crash from a clean failure.
     * @param int    $durationMs - Wall-clock scan time in milliseconds shown alongside the exit code.
     *
     * @return string - Complete dashboard error HTML.
     */
    public function errorHtml(string $message, string $detail, int $exitCode, int $durationMs): string
    {
        return '<!DOCTYPE html><html lang="en-NZ"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>gruff-php dashboard error</title>'
            . '<style>body{font:14px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;background:#161412;color:#f3e9d2;padding:32px}main{max-width:920px;margin:0 auto}pre{white-space:pre-wrap;background:#0d0c0a;border:1px solid #2a2622;padding:16px;overflow:auto}</style></head><body><main>'
            . '<h1>gruff-php dashboard</h1>'
            . sprintf('<p>%s</p>', $this->escape($message))
            . sprintf('<p>Exit code: %d · Duration: %dms</p>', $exitCode, $durationMs)
            . sprintf('<pre>%s</pre>', $this->escape($detail))
            . '</main></body></html>';
    }

    /**
     * Renders one labelled text input for the controls panel, pre-filled with the user's current value.
     * Used to build the project, paths, config, and baseline fields the user edits before a scan.
     *
     * @param string $label - Human-readable control label shown above the input.
     * @param string $name - Form field name bound to a scan query parameter.
     * @param string $fieldValue - Current value to pre-fill; reflected user input, so it is escaped here; empty when the user has not filled it in.
     * @param string $placeholder - Greyed-out hint shown while the field is empty; an empty string means show no hint at all.
     *
     * @return string - Escaped label-and-input HTML for one control.
     */
    private function field(string $label, string $name, string $fieldValue, string $placeholder = ''): string
    {
        // Every interpolated value is HTML-escaped so reflected form state cannot break out of the attribute context.
        return sprintf(
            '<label>%s<input name="%s" value="%s" placeholder="%s"></label>',
            $this->escape($label),
            $this->escape($name),
            $this->escape($fieldValue),
            $this->escape($placeholder),
        );
    }

    /**
     * Renders one dropdown option, pre-selecting it when it matches the current choice. Used to build
     * the "scan scope" and "fail on" selects so they reopen on the setting the user last picked.
     *
     * @param string      $optionValue - Value submitted when the user picks this option.
     * @param string      $selected - The dropdown's current value; this option is marked selected when it matches.
     * @param string|null $label - Visible option text; null means fall back to showing the raw $optionValue.
     *
     * @return string - Escaped option HTML.
     */
    private function option(string $optionValue, string $selected, ?string $label = null): string
    {
        // The selected attribute is fixed markup, not user data; only the value and label are escaped.
        return sprintf(
            '<option value="%s"%s>%s</option>',
            $this->escape($optionValue),
            $optionValue === $selected ? ' selected' : '',
            $this->escape($label ?? $optionValue),
        );
    }

    /**
     * Returns the inline stylesheet embedded in the dashboard shell, so the page renders with its dark
     * control-panel styling from one self-contained response and never waits on an external CSS file.
     *
     * @return string - The dashboard's inline CSS.
     */
    private function dashboardCss(): string
    {
        // Single-quoted heredoc (nowdoc) so PHP performs no variable interpolation on the CSS literal.
        return <<<'CSS'
:root{color-scheme:dark;--paper:#f3e9d2;--ink:#11100e;--panel:#1b1815;--field:#0d0c0a;--line:#332d27;--muted:#b5ab94;--accent:#e85d04;--accent-dark:#120f0d}*{box-sizing:border-box}body{margin:0;background:var(--ink);color:var(--paper);font:14px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;height:100vh;overflow:hidden}.controls-toggle{position:fixed;top:14px;right:24px;z-index:20;width:44px;height:44px;border:1px solid var(--accent);border-radius:6px;background:var(--accent);color:var(--accent-dark);font:700 24px/1 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;cursor:pointer;display:grid;place-items:center;box-shadow:0 4px 14px rgba(0,0,0,.45)}.controls-toggle.busy:after{content:'';position:absolute;right:5px;bottom:5px;width:9px;height:9px;border-radius:50%;background:var(--paper);border:1px solid var(--accent-dark)}.controls-panel{position:fixed;top:66px;right:24px;z-index:21;width:min(560px,calc(100vw - 48px));max-height:calc(100vh - 86px);overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:8px;box-shadow:0 18px 50px rgba(0,0,0,.45);padding:18px}.panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding-bottom:14px;border-bottom:1px solid var(--line)}.panel-head strong{display:block;font:italic 30px Georgia,Iowan Old Style,serif;letter-spacing:0}.panel-head span{display:block;margin-top:4px;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.18em}.panel-head button{width:44px;height:44px;padding:0;border:1px solid var(--line);background:var(--field);color:var(--paper);font-size:24px;line-height:1}.scan-summary{display:grid;grid-template-columns:minmax(108px,auto) 1fr;gap:10px 18px;margin:18px 0 16px;padding:14px 16px;background:var(--field);border:1px solid var(--line);font-size:12px}.scan-status,.scan-command{display:contents}.scan-summary span{color:var(--muted);text-transform:uppercase;letter-spacing:.12em}.scan-summary strong,.scan-summary code{min-width:0;color:var(--paper);font:13px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}.scan-meta-line{min-width:0;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:start}.scan-summary code{white-space:normal;overflow:visible;overflow-wrap:anywhere;word-break:break-word}.scan-meta-line button{align-self:start;min-width:68px;padding:7px 9px;font-size:11px}form{display:grid;grid-template-columns:1fr;gap:14px}.field-stack,.field-grid,.option-grid{display:grid;gap:12px}.field-grid{grid-template-columns:1fr 1fr}.option-grid{grid-template-columns:repeat(4,minmax(0,1fr));padding:4px 0}label{display:flex;flex-direction:column;gap:7px;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.1em}.check{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:10px;text-transform:none;letter-spacing:0;font-size:12px;color:var(--paper);line-height:1.35}.check span{min-width:0}input,select{width:100%;min-height:46px;border:1px solid var(--line);background:var(--field);color:var(--paper);padding:10px 12px;font:14px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}input[type=checkbox]{width:18px;min-height:18px;height:18px;margin:0;accent-color:var(--accent)}button{border:1px solid var(--accent);background:var(--accent);color:var(--accent-dark);padding:12px 14px;font:700 13px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;cursor:pointer}button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--paper);outline-offset:2px}button:disabled{opacity:.6;cursor:wait}.panel-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding-top:2px}.panel-actions button{min-height:48px}iframe{width:100vw;height:100vh;border:0;background:var(--field);display:block}@media(max-width:700px){.controls-toggle{top:10px;right:18px}.controls-panel{top:60px;right:18px;width:calc(100vw - 36px);padding:16px}.field-grid,.option-grid,.scan-summary,.scan-meta-line{grid-template-columns:1fr}.panel-head strong{font-size:28px}}
CSS;
    }

    /**
     * Returns the inline script embedded in the dashboard shell - the client-side glue that opens the
     * controls panel, submits scans into the iframe, ticks the elapsed timer, and copies the last command.
     *
     * @return string - The dashboard's inline JavaScript.
     */
    private function dashboardJs(): string
    {
        // Single-quoted heredoc (nowdoc) so PHP performs no variable interpolation on the JS literal.
        return <<<'JS'
const form=document.getElementById('scan-form');const frame=document.getElementById('report-frame');const refresh=document.getElementById('refresh');const runButton=document.getElementById('run-scan');const status=document.getElementById('scan-status');const scanMeta=document.getElementById('scan-meta');const copyScanMeta=document.getElementById('copy-scan-meta');const toggle=document.getElementById('controls-toggle');const panel=document.getElementById('controls-panel');const close=document.getElementById('controls-close');let scans=0;let busyTimer=null;let busyStarted=0;function params(){return new URLSearchParams(new FormData(form));}function setOpen(open){panel.hidden=!open;toggle.setAttribute('aria-expanded',open?'true':'false');if(open){form.elements.project.focus();}}function stopBusyTimer(){if(busyTimer!==null){clearInterval(busyTimer);busyTimer=null;}}function renderBusy(){status.textContent='Scanning... '+Math.floor((Date.now()-busyStarted)/1000)+'s';}function setBusy(busy){refresh.disabled=busy;runButton.disabled=busy;toggle.classList.toggle('busy',busy);toggle.setAttribute('aria-label',busy?'Scanning':'Dashboard controls');stopBusyTimer();if(busy){busyStarted=Date.now();renderBusy();busyTimer=setInterval(renderBusy,1000);}else{status.textContent='Scan loaded';}}function updateMeta(data){if(!data||data.type!=='gruff-scan-complete'){return;}const exit=Number.isInteger(data.exitCode)?data.exitCode:'?';const duration=Number.isInteger(data.durationMs)?data.durationMs+'ms':'duration n/a';const command=typeof data.command==='string'?data.command:'';scanMeta.textContent='exit '+exit+' · '+duration+(command===''?'':' · '+command);}async function copyMeta(){const text=scanMeta.textContent||'';try{if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(text);}else{const range=document.createRange();range.selectNodeContents(scanMeta);const selection=window.getSelection();if(selection){selection.removeAllRanges();selection.addRange(range);document.execCommand('copy');selection.removeAllRanges();}}copyScanMeta.textContent='Copied';setTimeout(()=>{copyScanMeta.textContent='Copy';},1200);}catch(error){copyScanMeta.textContent='Copy failed';setTimeout(()=>{copyScanMeta.textContent='Copy';},1200);}}function run(){const qs=params();const visible=new URLSearchParams(qs);qs.set('_run',Date.now().toString()+'-'+(++scans));setBusy(true);frame.removeAttribute('srcdoc');frame.src='/scan?'+qs.toString();history.replaceState(null,'','/?'+visible.toString());}toggle.addEventListener('click',event=>{event.stopPropagation();setOpen(panel.hidden);});close.addEventListener('click',()=>setOpen(false));document.addEventListener('click',event=>{if(!panel.hidden&&!panel.contains(event.target)&&event.target!==toggle){setOpen(false);}});document.addEventListener('keydown',event=>{if(event.key==='Escape'){setOpen(false);}});window.addEventListener('message',event=>{if(event.origin!==window.location.origin)return;updateMeta(event.data);});frame.addEventListener('load',()=>{setBusy(false);try{const el=frame.contentDocument&&frame.contentDocument.getElementById('gruff-dashboard-meta');if(el){updateMeta(JSON.parse(el.textContent||'{}'));}}catch(error){}});form.addEventListener('submit',event=>{event.preventDefault();run();});refresh.addEventListener('click',run);copyScanMeta.addEventListener('click',copyMeta);setTimeout(run,0);
JS;
    }

    /**
     * Returns the small "Ready to scan." document seeded into the iframe on load, so the user sees a
     * calm placeholder rather than a blank frame until their first scan finishes and replaces it.
     *
     * @return string - Complete loading-frame HTML.
     */
    private function loadingFrame(): string
    {
        return '<!DOCTYPE html><html lang="en-NZ"><head><meta charset="UTF-8"><style>body{margin:0;background:#0d0c0a;color:#f3e9d2;font:14px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;display:grid;place-items:center;min-height:100vh}</style></head><body>Ready to scan.</body></html>';
    }

    /**
     * Turns the scan's raw argv into a tidy, copy-pasteable command line for the dashboard's status
     * bar: it normalises the interpreter-and-binary prefix to `php bin/gruff-php` and quotes only the
     * arguments that need it, so the user can lift the exact command back into a terminal.
     *
     * @param list<string> $command - Raw scan argv; the interpreter and binary prefix are normalised to `php bin/gruff-php` for display.
     *
     * @return string - Shell-safe, copy-pasteable command string for the dashboard metadata.
     */
    private function displayCommand(array $command): string
    {
        $display = ['php', 'bin/gruff-php', ...array_slice($command, 2)];

        return implode(' ', array_map(
            // Keep an argument bare when it is only safe, portable token characters; shell-quote anything riskier so the command the user copies can't misbehave.
            static fn (string $argument): string => preg_match('~^[A-Za-z0-9_@%+=:,./-]+$~', $argument) === 1 ? $argument : escapeshellarg($argument),
            $display,
        ));
    }

    /**
     * Escapes a string for safe inclusion in HTML - the single choke point every user-supplied value
     * passes through before it lands in the dashboard markup, so reflected form input can't inject HTML.
     *
     * @param string $text - Untrusted value, typically reflected form state, that a caller is binding into HTML markup.
     *
     * @return string - The HTML-escaped value, safe in both attribute and element contexts.
     */
    private function escape(string $text): string
    {
        // ENT_QUOTES escapes both quote styles so the result is safe in attribute and element contexts alike.
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
