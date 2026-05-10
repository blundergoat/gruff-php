<?php

declare(strict_types=1);

namespace GruffPhp\Command;

final readonly class DashboardPageRenderer
{
    /**
     * @param array{project: string, paths: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string} $state
     */
    public function dashboardHtml(array $state): string
    {
        $scanUrl = '/scan?' . http_build_query($state, '', '&', PHP_QUERY_RFC3986);

        return '<!DOCTYPE html><html lang="en-NZ"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>gruff dashboard</title><style>' . $this->dashboardCss() . '</style></head><body>'
            . '<button type="button" id="controls-toggle" class="controls-toggle" aria-haspopup="dialog" aria-expanded="false" aria-controls="controls-panel" title="Dashboard controls">&#9881;</button>'
            . '<section id="controls-panel" class="controls-panel" role="dialog" aria-label="Dashboard controls" hidden>'
            . '<div class="panel-head"><strong>Dashboard controls</strong><button type="button" id="controls-close" aria-label="Close dashboard controls">&times;</button></div>'
            . '<div class="scan-summary"><span>Status</span><strong id="scan-status" aria-live="polite">Ready</strong><span>Last scan</span><code id="scan-meta">Not run</code></div>'
            . '<form id="scan-form" method="get" action="/">'
            . $this->field('Project root', 'project', $state['project'])
            . $this->field('Paths', 'paths', $state['paths'])
            . $this->field('Config', 'config', $state['config'])
            . $this->field('Baseline', 'baseline', $state['baseline'])
            . '<label>Fail on<select name="failOn">'
            . $this->option('none', $state['failOn'])
            . $this->option('advisory', $state['failOn'])
            . $this->option('warning', $state['failOn'])
            . $this->option('error', $state['failOn'])
            . '</select></label>'
            . '<label class="check"><input type="checkbox" name="noBaseline" value="1"' . ($state['noBaseline'] === '1' ? ' checked' : '') . '> skip baseline</label>'
            . '<label class="check"><input type="checkbox" name="includeIgnored" value="1"' . ($state['includeIgnored'] === '1' ? ' checked' : '') . '> include ignored</label>'
            . '<div class="panel-actions"><button type="button" id="refresh">Refresh</button><button type="submit" id="run-scan">Run scan</button></div></form></section>'
            . sprintf('<iframe id="report-frame" title="gruff report" data-initial-src="%s" srcdoc="%s"></iframe>', $this->escape($scanUrl), $this->escape($this->loadingFrame()))
            . '<script>' . $this->dashboardJs() . '</script></body></html>';
    }

    /**
     * @param list<string> $command
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

        if (!is_string($payload)) {
            $payload = '{"type":"gruff-scan-complete"}';
        }

        $metadata = '<script id="gruff-dashboard-meta" type="application/json">' . $payload . '</script>'
            . '<script>(()=>{const el=document.getElementById("gruff-dashboard-meta");if(window.parent&&el){window.parent.postMessage(JSON.parse(el.textContent),window.location.origin);}})();</script>';

        if (str_contains($html, '<body>')) {
            return str_replace('<body>', '<body>' . $metadata, $html);
        }

        return $metadata . $html;
    }

    /**
     * Renders an iframe-safe dashboard error document.
     */
    public function errorHtml(string $message, string $detail, int $exitCode, int $durationMs): string
    {
        return '<!DOCTYPE html><html lang="en-NZ"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>gruff dashboard error</title>'
            . '<style>body{font:14px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;background:#161412;color:#f3e9d2;padding:32px}main{max-width:920px;margin:0 auto}pre{white-space:pre-wrap;background:#0d0c0a;border:1px solid #2a2622;padding:16px;overflow:auto}</style></head><body><main>'
            . '<h1>gruff dashboard</h1>'
            . sprintf('<p>%s</p>', $this->escape($message))
            . sprintf('<p>Exit code: %d · Duration: %dms</p>', $exitCode, $durationMs)
            . sprintf('<pre>%s</pre>', $this->escape($detail))
            . '</main></body></html>';
    }

    private function field(string $label, string $name, string $value): string
    {
        return sprintf(
            '<label>%s<input name="%s" value="%s"></label>',
            $this->escape($label),
            $this->escape($name),
            $this->escape($value),
        );
    }

    private function option(string $value, string $selected): string
    {
        return sprintf(
            '<option value="%s"%s>%s</option>',
            $this->escape($value),
            $value === $selected ? ' selected' : '',
            $this->escape($value),
        );
    }

    private function dashboardCss(): string
    {
        return ":root{color-scheme:dark;--paper:#f3e9d2;--ink:#11100e;--panel:#1b1815;--line:#332d27;--accent:#e85d04}*{box-sizing:border-box}body{margin:0;background:var(--ink);color:var(--paper);font:14px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;height:100vh;overflow:hidden}.controls-toggle{position:fixed;top:14px;right:24px;z-index:20;width:44px;height:44px;border:1px solid var(--accent);border-radius:8px;background:var(--accent);color:#120f0d;font:700 24px/1 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;cursor:pointer;display:grid;place-items:center;box-shadow:0 4px 14px rgba(0,0,0,.45)}.controls-toggle.busy:after{content:'';position:absolute;right:5px;bottom:5px;width:9px;height:9px;border-radius:50%;background:#f3e9d2;border:1px solid #120f0d}.controls-panel{position:fixed;top:66px;right:24px;z-index:21;width:min(430px,calc(100vw - 48px));max-height:calc(100vh - 86px);overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:8px;box-shadow:0 18px 50px rgba(0,0,0,.45);padding:16px}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-bottom:12px;border-bottom:1px solid var(--line)}.panel-head strong{font:italic 22px Georgia,Iowan Old Style,serif}.panel-head button{width:34px;height:34px;padding:0;border:1px solid var(--line);background:#0d0c0a;color:var(--paper);font-size:22px;line-height:1}.scan-summary{display:grid;grid-template-columns:auto 1fr;gap:8px 12px;margin:14px 0;padding:12px;background:#0d0c0a;border:1px solid var(--line);font-size:12px}.scan-summary span{color:#b5ab94;text-transform:uppercase;letter-spacing:.08em}.scan-summary strong,.scan-summary code{min-width:0;color:var(--paper);font:13px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}.scan-summary code{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}form{display:grid;grid-template-columns:1fr;gap:12px}label{display:flex;flex-direction:column;gap:6px;color:#b5ab94;font-size:11px;text-transform:uppercase;letter-spacing:.08em}.check{flex-direction:row;align-items:center;text-transform:none;letter-spacing:0;font-size:12px;color:var(--paper)}input,select{width:100%;border:1px solid var(--line);background:#0d0c0a;color:var(--paper);padding:9px 10px;font:13px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}button{border:1px solid var(--accent);background:var(--accent);color:#120f0d;padding:10px 12px;font:700 13px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;cursor:pointer}button:disabled{opacity:.6;cursor:wait}.panel-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}iframe{width:100vw;height:100vh;border:0;background:#0d0c0a;display:block}@media(max-width:640px){.controls-toggle{top:10px;right:18px}.controls-panel{top:60px;right:18px;width:calc(100vw - 36px)}}";
    }

    private function dashboardJs(): string
    {
        return "const form=document.getElementById('scan-form');const frame=document.getElementById('report-frame');const refresh=document.getElementById('refresh');const runButton=document.getElementById('run-scan');const status=document.getElementById('scan-status');const scanMeta=document.getElementById('scan-meta');const toggle=document.getElementById('controls-toggle');const panel=document.getElementById('controls-panel');const close=document.getElementById('controls-close');let scans=0;let busyTimer=null;let busyStarted=0;function params(){return new URLSearchParams(new FormData(form));}function setOpen(open){panel.hidden=!open;toggle.setAttribute('aria-expanded',open?'true':'false');if(open){form.elements.project.focus();}}function stopBusyTimer(){if(busyTimer!==null){clearInterval(busyTimer);busyTimer=null;}}function renderBusy(){status.textContent='Scanning... '+Math.floor((Date.now()-busyStarted)/1000)+'s';}function setBusy(busy){refresh.disabled=busy;runButton.disabled=busy;toggle.classList.toggle('busy',busy);toggle.setAttribute('aria-label',busy?'Scanning':'Dashboard controls');stopBusyTimer();if(busy){busyStarted=Date.now();renderBusy();busyTimer=setInterval(renderBusy,1000);}else{status.textContent='Scan loaded';}}function updateMeta(data){if(!data||data.type!=='gruff-scan-complete'){return;}const exit=Number.isInteger(data.exitCode)?data.exitCode:'?';const duration=Number.isInteger(data.durationMs)?data.durationMs+'ms':'duration n/a';const command=typeof data.command==='string'?data.command:'';scanMeta.textContent='exit '+exit+' · '+duration+(command===''?'':' · '+command);}function run(){const qs=params();const visible=new URLSearchParams(qs);qs.set('_run',Date.now().toString()+'-'+(++scans));setBusy(true);frame.removeAttribute('srcdoc');frame.src='/scan?'+qs.toString();history.replaceState(null,'','/?'+visible.toString());}toggle.addEventListener('click',event=>{event.stopPropagation();setOpen(panel.hidden);});close.addEventListener('click',()=>setOpen(false));document.addEventListener('click',event=>{if(!panel.hidden&&!panel.contains(event.target)&&event.target!==toggle){setOpen(false);}});document.addEventListener('keydown',event=>{if(event.key==='Escape'){setOpen(false);}});window.addEventListener('message',event=>{if(event.origin!==window.location.origin)return;updateMeta(event.data);});frame.addEventListener('load',()=>{setBusy(false);try{const el=frame.contentDocument&&frame.contentDocument.getElementById('gruff-dashboard-meta');if(el){updateMeta(JSON.parse(el.textContent||'{}'));}}catch(error){}});form.addEventListener('submit',event=>{event.preventDefault();run();});refresh.addEventListener('click',run);setTimeout(run,0);";
    }

    private function loadingFrame(): string
    {
        return '<!DOCTYPE html><html lang="en-NZ"><head><meta charset="UTF-8"><style>body{margin:0;background:#0d0c0a;color:#f3e9d2;font:14px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;display:grid;place-items:center;min-height:100vh}</style></head><body>Ready to scan.</body></html>';
    }

    /**
     * @param list<string> $command
     */
    private function displayCommand(array $command): string
    {
        $display = ['php', 'bin/gruff', ...array_slice($command, 2)];

        return implode(' ', array_map($this->quoteArgument(...), $display));
    }

    private function quoteArgument(string $argument): string
    {
        return preg_match('~^[A-Za-z0-9_@%+=:,./-]+$~', $argument) === 1 ? $argument : escapeshellarg($argument);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
