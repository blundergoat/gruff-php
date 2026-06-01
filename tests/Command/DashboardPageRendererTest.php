<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Command\DashboardPageRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Covers dashboard HTML rendering: escaping, checkbox state markers, metadata payload injection around the body tag, JSON-encoding fallback, and
 * error-page formatting.
 */
final class DashboardPageRendererTest extends TestCase
{
    /** Scan duration used when testing dashboard metadata injection. */
    private const SCAN_DURATION_MS = 345;

    /**
     * Verify dashboard HTML preserves shell structure and escaped form state.
     *
     * @return void
     */
    public function testDashboardHtmlRendersEscapedControlsInOrder(): void
    {
        $html = $this->renderer()->dashboardHtml($this->state([
                                                                  'project'           => '/tmp/gruff <root>',
                                                                  'paths'             => 'src tests',
                                                                  'scanScope'         => 'diff',
                                                                  'failOn'            => 'warning',
                                                                  'config'            => '.gruff "quoted".yaml',
                                                                  'baseline'          => 'base&line.json',
                                                                  'noBaseline'        => '1',
                                                                  'includeIgnored'    => '',
                                                                  'reportInteractive' => '1',
                                                              ]));

        self::assertStringStartsWith('<!DOCTYPE html><html lang="en-NZ"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>gruff-php dashboard</title><style>:root{', $html);
        self::assertStringContainsString('</style></head><body><button type="button" id="controls-toggle" class="controls-toggle" aria-haspopup="dialog" aria-expanded="false" aria-controls="controls-panel" title="Dashboard controls">&#9881;</button>', $html);
        self::assertStringContainsString('<section id="controls-panel" class="controls-panel" role="dialog" aria-label="Dashboard controls" hidden><div class="panel-head"><div><strong>Dashboard controls</strong><span>local scan settings</span></div><button type="button" id="controls-close" aria-label="Close dashboard controls">&times;</button></div>', $html);
        self::assertStringContainsString('<div class="scan-summary" aria-label="Scan status"><div class="scan-status"><span>Status</span><strong id="scan-status" aria-live="polite">Ready</strong></div><div class="scan-command"><span>Last scan</span><div class="scan-meta-line"><code id="scan-meta">Not run</code><button type="button" id="copy-scan-meta">Copy</button></div></div></div>', $html);
        self::assertStringContainsString('<form id="scan-form" method="get" action="/"><div class="field-stack"><label>Project root<input name="project" value="/tmp/gruff &lt;root&gt;" placeholder=""></label><label>Paths<input name="paths" value="src tests" placeholder=""></label></div>', $html);
        self::assertStringContainsString('<div class="field-grid"><label>Config path<input name="config" value=".gruff &quot;quoted&quot;.yaml" placeholder=".gruff-php.yaml"></label><label>Baseline<input name="baseline" value="base&amp;line.json" placeholder="gruff-baseline.json"></label></div>', $html);
        self::assertStringContainsString('<div class="field-grid"><label>Scan scope<select name="scanScope"><option value="full">whole branch</option><option value="diff" selected>diff only</option></select></label>', $html);
        self::assertStringContainsString('<label>Fail on<select name="failOn"><option value="none">none</option><option value="advisory">advisory</option><option value="warning" selected>warning</option><option value="error">error</option></select></label></div><div class="option-grid">', $html);
        self::assertStringContainsString('<div class="option-grid"><label class="check"><input type="checkbox" name="noConfig" value="1"><span>skip config</span></label><label class="check"><input type="checkbox" name="noBaseline" value="1" checked><span>skip baseline</span></label><label class="check"><input type="checkbox" name="includeIgnored" value="1"><span>include ignored</span></label><label class="check"><input type="checkbox" name="reportInteractive" value="1" checked><span>interactive findings</span></label></div>', $html);
        self::assertStringContainsString('</label></div><div class="panel-actions"><button type="button" id="refresh">Refresh</button><button type="submit" id="run-scan">Run scan</button></div></form></section><iframe id="report-frame"', $html);
        self::assertStringContainsString('data-initial-src="/scan?project=%2Ftmp%2Fgruff%20%3Croot%3E&amp;paths=src%20tests&amp;scanScope=diff&amp;failOn=warning&amp;config=.gruff%20%22quoted%22.yaml&amp;baseline=base%26line.json&amp;noBaseline=1&amp;noConfig=&amp;includeIgnored=&amp;reportInteractive=1"', $html);
        self::assertStringContainsString('srcdoc="&lt;!DOCTYPE html&gt;&lt;html lang=&quot;en-NZ&quot;&gt;&lt;head&gt;&lt;meta charset=&quot;UTF-8&quot;&gt;&lt;style&gt;body{margin:0;background:#0d0c0a;color:#f3e9d2;', $html);
        self::assertStringEndsWith('copyScanMeta.addEventListener(\'click\',copyMeta);setTimeout(run,0);</script></body></html>', $html);
    }

    /**
     * Verify include-ignored checkbox state is rendered when selected.
     *
     * @return void
     */
    public function testDashboardHtmlMarksIncludeIgnoredWhenSelected(): void
    {
        $html = $this->renderer()->dashboardHtml($this->state(['includeIgnored' => '1']));

        self::assertStringContainsString('<label class="check"><input type="checkbox" name="includeIgnored" value="1" checked><span>include ignored</span></label>', $html);
    }

    /**
     * Verify no-config checkbox state is rendered when selected.
     *
     * @return void
     */
    public function testDashboardHtmlMarksNoConfigWhenSelected(): void
    {
        $html = $this->renderer()->dashboardHtml($this->state(['noConfig' => '1']));

        self::assertStringContainsString('<label class="check"><input type="checkbox" name="noConfig" value="1" checked><span>skip config</span></label>', $html);
    }

    /**
     * Verify scan metadata is injected after the body tag with a complete payload.
     *
     * @return void
     */
    public function testInjectDashboardMetadataEmbedsPayloadAfterBodyTag(): void
    {
        $html = $this->renderer()->injectDashboardMetadata(
            html:        '<!doctype html><html><body><main>scan</main></body></html>',
            projectRoot: '/tmp/<project>&"quoted"',
            command:     [PHP_BINARY, 'bin/gruff-php', 'analyse', 'src/File.php', '--name', 'value with spaces', "quote'arg"],
            exitCode:    2,
            durationMs:  self::SCAN_DURATION_MS,
        );

        $payload = $this->metadataPayload($html);

        self::assertStringContainsString('<body><script id="gruff-dashboard-meta" type="application/json">', $html);
        self::assertStringContainsString('/tmp/\u003Cproject\u003E\u0026\u0022quoted\u0022', $html);
        self::assertStringContainsString('window.parent.postMessage(JSON.parse(el.textContent),window.location.origin);', $html);
        self::assertSame('gruff-scan-complete', $payload['type']);
        self::assertSame(2, $payload['exitCode']);
        self::assertSame(self::SCAN_DURATION_MS, $payload['durationMs']);
        self::assertSame('/tmp/<project>&"quoted"', $payload['projectRoot']);
        self::assertSame('php bin/gruff-php analyse src/File.php --name ' . escapeshellarg('value with spaces') . ' ' . escapeshellarg("quote'arg"), $payload['command']);
    }

    /**
     * Verify scan metadata is prepended when a report body tag is unavailable.
     *
     * @return void
     */
    public function testInjectDashboardMetadataPrependsPayloadWithoutBodyTag(): void
    {
        $html = $this->renderer()->injectDashboardMetadata(
            html:        '<main>scan</main>',
            projectRoot: '/repo',
            command:     [PHP_BINARY, 'bin/gruff-php'],
            exitCode:    0,
            durationMs:  1,
        );

        self::assertStringStartsWith('<script id="gruff-dashboard-meta" type="application/json">', $html);
        self::assertStringEndsWith('<main>scan</main>', $html);
        self::assertSame('php bin/gruff-php', $this->metadataPayload($html)['command']);
    }

    /**
     * Verify invalid metadata strings fall back to the minimal completion payload.
     *
     * @return void
     */
    public function testInjectDashboardMetadataFallsBackWhenJsonEncodingFails(): void
    {
        $html = $this->renderer()->injectDashboardMetadata(
            html:        '<main>scan</main>',
            projectRoot: "\xB1",
            command:     [PHP_BINARY, 'bin/gruff-php'],
            exitCode:    1,
            durationMs:  2,
        );

        self::assertStringContainsString('{"type":"gruff-scan-complete"}', $html);
        self::assertSame(['type' => 'gruff-scan-complete'], $this->metadataPayload($html));
    }

    /**
     * Verify dashboard error HTML escapes untrusted message and detail text.
     *
     * @return void
     */
    public function testErrorHtmlEscapesMessageDetailAndReportsTiming(): void
    {
        $html = $this->renderer()->errorHtml('Failed <scan> "now"', "line & detail\nsecond line", 7, 1234);

        self::assertStringStartsWith('<!DOCTYPE html><html lang="en-NZ"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>gruff-php dashboard error</title>', $html);
        self::assertStringContainsString('<style>body{font:14px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;background:#161412;color:#f3e9d2;padding:32px}main{max-width:920px;margin:0 auto}pre{white-space:pre-wrap;background:#0d0c0a;border:1px solid #2a2622;padding:16px;overflow:auto}</style></head><body><main>', $html);
        self::assertStringContainsString('<h1>gruff-php dashboard</h1><p>Failed &lt;scan&gt; &quot;now&quot;</p>', $html);
        self::assertStringContainsString("<p>Exit code: 7 · Duration: 1234ms</p><pre>line &amp; detail\nsecond line</pre>", $html);
        self::assertStringEndsWith('</main></body></html>', $html);
    }

    /**
     * Build a renderer fixture.
     *
     * @return DashboardPageRenderer - a fresh, collaborator-free renderer instance for each test invocation
     */
    private function renderer(): DashboardPageRenderer
    {
        // Plain renderer under test; it takes no collaborators.
        return new DashboardPageRenderer();
    }

    /**
     * Build complete dashboard state with targeted overrides.
     *
     * @param array<string, string> $overrides - Values to override.
     *
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string,
     *                        noConfig: string, includeIgnored: string, reportInteractive: string} - every form field defaulted, with the caller's
     *                        overrides merged on top so each test asserts on a fully populated state
     */
    private function state(array $overrides = []): array
    {
        // Full default state so each test only spells out the field it cares about.
        return array_merge([
                               'project' => '/repo',
                                                                                                                                                                                                                      'paths' => '',
                                                                                                                                                                                                                      'scanScope' => 'full',
                                                                                                                                                                                                                                                          'failOn' => 'none',
                                                                                                                                                                                                                                                          'config' => '.gruff-php.yaml',
                                                                                                                                                                                                                                                          'baseline' => '',
                                                                                                                                                                                                                                                                   'noBaseline' => '',
                                                                                                                                                                                                                                                                                                                            'noConfig' => '',
                                                                                                                                                                                                                                                                                                                                                 'includeIgnored' => '',
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          'reportInteractive' => '',
                           ], $overrides);
    }

    /**
     * Extract the embedded dashboard metadata payload.
     *
     * @param string $html - HTML containing the metadata script.
     *
     * @return array<string, int|string> - the JSON payload pulled from the meta script tag, decoded and asserted to contain only string keys mapping
     *                       to int or string values
     */
    private function metadataPayload(string $html): array
    {
        $pattern = '~<script id="gruff-dashboard-meta" type="application/json">(?P<payload>.*?)</script>~';
        // Extract the dashboard metadata JSON payload from the rendered script tag.
        $matched = preg_match($pattern, $html, $matches);

        self::assertSame(1, $matched);

        $payload = json_decode($matches['payload'], true);

        self::assertIsArray($payload);

        $metadata = [];
        foreach ($payload as $key => $value) {
            self::assertIsString($key);
            self::assertTrue(is_int($value) || is_string($value));

            $metadata[$key] = $value;
        }

        return $metadata;
    }
}
