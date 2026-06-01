<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers the analyse CLI end-to-end: single-file mode, syntax-error handling, threshold-driven exits, JSON/HTML/SARIF/GitHub outputs, profile and selection config, scoring, and editor-link options.
 */
final class AnalyseCliTest extends CliTestCase
{
    /**
     * Verify analyse command runs as no op.
     *
     * @return void
     */
    public function testAnalyseCommandRunsAsNoOp(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--fail-on',
            'error',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff-php 0.3.0', $process->getOutput());
        self::assertStringContainsString('Discovered: 2', $process->getOutput());
        self::assertStringContainsString('Ignored: 6', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/Source/mixed/vendor/ignored.php', $process->getOutput());
    }

    /**
     * Verify analyse command supports an explicit single-file option.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandSupportsSingleFileOption(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            '--file',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--no-config',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report      = $this->decodeJsonOutput($process);
        $runMetadata = $report['run'] ?? null;
        $summary     = $report['summary'] ?? null;

        self::assertIsArray($runMetadata);
        self::assertIsArray($summary);
        self::assertSame(['tests/Fixtures/Source/mixed/alpha.php'], $runMetadata['paths'] ?? null);
        self::assertSame(1, $summary['filesDiscovered'] ?? null);
        self::assertSame(1, $summary['filesParsed'] ?? null);
        self::assertSame(0, $summary['exitCode'] ?? null);
    }

    /**
     * Verify analyse command reports syntax errors without aborting.
     *
     * @return void
     */
    public function testAnalyseCommandReportsSyntaxErrorsWithoutAborting(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            'tests/Fixtures/Source/syntax-error/broken.php',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[PARSE-ERROR] tests/Fixtures/Source/syntax-error/broken.php:4', $process->getOutput());
        self::assertStringContainsString('Parse errors: 1', $process->getOutput());
        self::assertStringContainsString('Exit code: 2', $process->getOutput());
    }

    /**
     * Verify analyse command reports warning findings without failing by default.
     *
     * @return void
     */
    public function testAnalyseCommandReportsWarningFindingsWithoutFailingByDefault(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--config',
            'tests/Fixtures/Config/file-length-warning.yaml',
            '--fail-on',
            'error',
            '--no-baseline',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $expected = $this->goldenOutput('text-warning.txt');

        self::assertSame($expected, $process->getOutput());
    }

    /**
     * Verify analyse command fails when finding meets default error threshold.
     *
     * @return void
     */
    public function testAnalyseCommandFailsWhenFindingMeetsDefaultErrorThreshold(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--config',
            'tests/Fixtures/Config/file-length-error.yaml',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('[error] size.file-length', $process->getOutput());
        self::assertStringContainsString('Exit code: 1', $process->getOutput());
    }

    /**
     * Verify analyse command can fail on warning threshold.
     *
     * @return void
     */
    public function testAnalyseCommandCanFailOnWarningThreshold(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--config',
            'tests/Fixtures/Config/file-length-warning.yaml',
            '--fail-on',
            'warning',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Fail threshold: warning', $process->getOutput());
        self::assertStringContainsString('Exit code: 1', $process->getOutput());
    }

    /**
     * Verify analyse command outputs JSON report.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandOutputsJsonReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--config',
            'tests/Fixtures/Config/file-length-warning.yaml',
            '--format',
            'json',
            '--fail-on',
            'error',
            '--no-baseline',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $expected = $this->goldenOutput('json-warning.json');

        self::assertSame($expected, $process->getOutput());

        $report   = $this->decodeJsonOutput($process);
        $summary  = $report['summary'] ?? null;
        $findings = $report['findings'] ?? null;

        self::assertSame('gruff.analysis.v2', $report['schemaVersion'] ?? null);
        self::assertIsArray($summary);
        self::assertSame(1, $summary['filesDiscovered'] ?? null);
        self::assertSame(0, $summary['exitCode'] ?? null);
        self::assertIsArray($findings);
        self::assertCount(3, $findings);

        $sizeFinding = null;
        foreach ($findings as $finding) {
            if (is_array($finding) && ($finding['ruleId'] ?? null) === 'size.file-length') {
                $sizeFinding = $finding;
                break;
            }
        }
        self::assertIsArray($sizeFinding);
        self::assertSame('warning', $sizeFinding['severity'] ?? null);
    }

    /**
     * Verify analyse command outputs JSON parse errors.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandOutputsJsonParseErrors(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/syntax-error',
            '--format',
            'json',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());

        $report      = $this->decodeJsonOutput($process);
        $summary     = $report['summary'] ?? null;
        $diagnostics = $report['diagnostics'] ?? null;

        self::assertIsArray($summary);
        self::assertSame(1, $summary['parseErrors'] ?? null);
        self::assertSame(2, $summary['exitCode'] ?? null);
        self::assertIsArray($diagnostics);
        $firstDiagnostic = $diagnostics[0] ?? null;

        self::assertIsArray($firstDiagnostic);
        self::assertSame('parse-error', $firstDiagnostic['type'] ?? null);
    }

    /**
     * Verify analyse command fails invalid config.
     *
     * @return void
     */
    public function testAnalyseCommandFailsInvalidConfig(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            '--config',
            'tests/Fixtures/Config/unknown-rule.yaml',
            'tests/Fixtures/Source/mixed/alpha.php',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[CONFIG-ERROR] Unknown rule id "size.nope".', $process->getOutput());
    }

    /**
     * Verify analyse command applies configured rule selection.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandAppliesConfiguredRuleSelection(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--config',
            'tests/Fixtures/Config/only-size-rules.yaml',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report  = $this->decodeJsonOutput($process);
        $summary = $report['summary'] ?? null;
        self::assertIsArray($summary);
        $findingCounts = $summary['findings'] ?? null;
        self::assertIsArray($findingCounts);
        self::assertSame(0, $findingCounts['total'] ?? null);
    }

    /**
     * Verify security profile limits rule execution to security and sensitive-data rules.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandSecurityProfileRunsSecurityRulesOnly(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Security/cumulative-security.php',
            '--no-config',
            '--profile',
            'security',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        $score    = $report['score'] ?? null;
        self::assertIsArray($findings);
        self::assertIsArray($score);
        $composite = $score['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertNotCount(0, $findings);
        self::assertSame('F', $composite['grade'] ?? null);

        foreach ($findings as $index => $finding) {
            self::assertIsArray($finding, sprintf('Finding %d should be an array.', $index));
            $ruleId = $finding['ruleId'] ?? null;
            self::assertIsString($ruleId, sprintf('Finding %d should include a string ruleId.', $index));
            self::assertTrue(
                str_starts_with($ruleId, 'security.') || str_starts_with($ruleId, 'sensitive-data.'),
                'Unexpected rule from security profile: ' . $ruleId,
            );
        }
    }

    /**
     * Verify security profile replaces configured rule selection.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandSecurityProfileOverridesConfiguredSelection(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Security/cumulative-security.php',
            '--config',
            'tests/Fixtures/Config/only-size-rules.yaml',
            '--profile',
            'security',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        $ruleIds = array_map(
            static fn (mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
            $findings,
        );

        self::assertContains('security.dangerous-function-call', $ruleIds);
        self::assertNotContains('docs.missing-file-phpdoc', $ruleIds);
    }

    /**
     * Verify analyse command rejects unknown execution profiles.
     *
     * @return void
     */
    public function testAnalyseCommandReportsInvalidProfile(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            '--profile',
            'security-plus',
            'tests/Fixtures/Source/Code',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[USAGE-ERROR] Unsupported profile "security-plus". Use default or security.', $process->getOutput());
    }

    /**
     * Verify analyse command applies configured path ignores.
     *
     * @return void
     */
    public function testAnalyseCommandAppliesConfiguredPathIgnores(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--config',
            'tests/Fixtures/Config/ignore-alpha.yaml',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Discovered: 1', $process->getOutput());
        self::assertStringContainsString('Ignored: 7', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/Source/mixed/alpha.php', $process->getOutput());
    }

    /**
     * Verify analyse command reports invalid selection config.
     *
     * @return void
     */
    public function testAnalyseCommandReportsInvalidSelectionConfig(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            '--config',
            'tests/Fixtures/Config/invalid-selection-rule.yaml',
            'tests/Fixtures/Source/Code',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[CONFIG-ERROR] Unknown rule id "size.nope" in "selection.rules".', $process->getOutput());
    }

    /**
     * Verify analyse command applies configured secret preview allowlist.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandAppliesConfiguredSecretPreviewAllowlist(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/SensitiveData/synthetic-secrets.php',
            '--config',
            'tests/Fixtures/Config/allow-aws-preview.yaml',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        $ruleIds = array_map(
            static fn (mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
            $findings,
        );

        self::assertNotContains('sensitive-data.aws-access-key', $ruleIds);
        self::assertContains('sensitive-data.api-key-pattern', $ruleIds);
    }

    /**
     * Verify analyse command reports missing infection executable in run mode.
     *
     * @return void
     */
    public function testAnalyseCommandReportsMissingInfectionExecutableInRunMode(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--infection-run',
            '--infection-bin',
            'tests/Fixtures/Mutation/missing-infection',
            '--infection-report',
            'tests/Fixtures/Mutation/Infection/infection-clean.json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[MUTATION-TOOL-ERROR]', $process->getOutput());
        self::assertStringContainsString('Infection executable not found', $process->getOutput());
    }

    /**
     * Verify analyse command outputs scoring data in JSON report.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandOutputsScoringDataInJsonReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
        $score  = $report['score'] ?? null;
        $diff   = $report['diff'] ?? null;

        self::assertIsArray($score);
        self::assertIsArray($diff);
        $composite = $score['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertSame('A', $composite['grade'] ?? null);
        self::assertSame('full-project', $score['scope'] ?? null);
        self::assertSame(false, $diff['active'] ?? null);
    }

    /**
     * Verify analyse command outputs HTML report.
     *
     * @return void
     */
    public function testAnalyseCommandOutputsHtmlReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('<section class="verdict">', $process->getOutput());
        self::assertStringContainsString('<section class="pillars">', $process->getOutput());
        self::assertStringContainsString('<table class="pillar-list">', $process->getOutput());
        self::assertStringContainsString('score drivers', $process->getOutput());
        self::assertStringContainsString('Mutation is omitted when no Infection report is supplied.', $process->getOutput());
        self::assertStringNotContainsString('<td class="pillar-name">mutation</td>', $process->getOutput());
        self::assertStringNotContainsString('fonts.googleapis.com', $process->getOutput());
    }

    /**
     * Verify analyse command supports HTML editor links.
     *
     * @return void
     */
    public function testAnalyseCommandSupportsHtmlEditorLinks(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
            '--report-editor-link',
            'vscode',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('href="vscode://file/', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/Source/Code/OrderCalculator.php:16', $process->getOutput());
    }

    /**
     * Verify analyse command defaults HTML locations to copyable spans.
     *
     * @return void
     */
    public function testAnalyseCommandDefaultsHtmlLocationsToCopyableSpans(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
            '--report-editor-link',
            'none',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('<span class="loc-link" tabindex="0" data-path="tests/Fixtures/Source/Code/OrderCalculator.php:16">', $process->getOutput());
        self::assertStringNotContainsString('vscode://file/', $process->getOutput());
        self::assertStringNotContainsString('phpstorm://open', $process->getOutput());
    }

    /**
     * Verify analyse command supports interactive HTML report.
     *
     * @return void
     */
    public function testAnalyseCommandSupportsInteractiveHtmlReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
            '--report-interactive',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('class="finding-filters"', $process->getOutput());
        self::assertStringContainsString('<script type="module">', $process->getOutput());

        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
            '--report-interactive=false',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('class="finding-filters"', $process->getOutput());
        self::assertStringNotContainsString('<script type="module">', $process->getOutput());
    }

    /**
     * Verify analyse command reports invalid HTML report options.
     *
     * @return void
     */
    public function testAnalyseCommandReportsInvalidHtmlReportOptions(): void
    {
        $editorProcess = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
            '--report-editor-link=bad',
            '--no-config',
        ], __DIR__ . '/../..');
        $editorProcess->run();

        self::assertSame(2, $editorProcess->getExitCode());
        self::assertStringContainsString('<section class="diagnostics">', $editorProcess->getOutput());
        self::assertStringContainsString('--report-editor-link must be one of: vscode, phpstorm, none.', $editorProcess->getOutput());

        $interactiveProcess = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
            '--report-interactive=maybe',
            '--no-config',
        ], __DIR__ . '/../..');
        $interactiveProcess->run();

        self::assertSame(2, $interactiveProcess->getExitCode());
        self::assertStringContainsString('<section class="diagnostics">', $interactiveProcess->getOutput());
        self::assertStringContainsString('--report-interactive must be true or false.', $interactiveProcess->getOutput());
    }

    /**
     * Verify analyse command outputs github annotations.
     *
     * @return void
     */
    public function testAnalyseCommandOutputsGithubAnnotations(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'github',
            '--fail-on',
            'none',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('::error file=tests/Fixtures/Source/Code/OrderCalculator.php', $process->getOutput());
        self::assertStringContainsString('title=docs.missing-public-phpdoc', $process->getOutput());
    }

    /**
     * Verify analyse command reports non Git diff mode clearly.
     *
     * @return void
     */
    public function testAnalyseCommandReportsNonGitDiffModeClearly(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function run(): void {}\n}\n");

            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                '.',
                '--diff',
                'unstaged',
                '--fail-on',
                'none',
            ], $tempDir);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('[DIFF-MODE-ERROR]', $process->getOutput());
            self::assertStringContainsString('Diff mode requires a git working tree.', $process->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify changed-ranges mode reports only findings attributable to the changed symbol.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandChangedRangesUsesSymbolScopeAndSuppressedCount(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/Example.php', $this->changedRegionSource());

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                'Example.php',
                '--changed-ranges',
                '11-11',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--no-config',
            ], $tempDir);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            $report   = $this->decodeJsonOutput($process);
            $findings = $report['findings'] ?? null;

            self::assertIsArray($findings);
            self::assertGreaterThanOrEqual(1, $report['suppressedCount'] ?? null);
            self::assertContains('Example::changed()', $this->symbolsFromJsonFindings($findings));
            self::assertNotContains('Example::unchanged()', $this->symbolsFromJsonFindings($findings));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify unified diff stdin mode feeds the same changed-region filter.
     *
     * @throws JsonException
     * @return void
     */
    public function testAnalyseCommandDiffStdinParsesUnifiedDiff(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/Example.php', $this->changedRegionSource());

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                'Example.php',
                '--diff',
                '-',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--no-config',
            ], $tempDir);
            $process->setInput(<<<'PATCH'
diff --git a/Example.php b/Example.php
--- a/Example.php
+++ b/Example.php
@@ -10,0 +11,1 @@
+        echo 'new';
PATCH);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            $report = $this->decodeJsonOutput($process);
            $diff   = $report['diff'] ?? null;

            self::assertIsArray($diff);
            self::assertSame('stdin', $diff['mode'] ?? null);
            self::assertGreaterThanOrEqual(1, $report['suppressedCount'] ?? null);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Load an expected CLI golden output fixture.
     *
     * @param string $fileName Basename under tests/Fixtures/Cli/Golden whose contents are the expected output.
     * @return string Fixture contents.
     */
    private function goldenOutput(string $fileName): string
    {
        $contents = file_get_contents(self::PROJECT_ROOT . '/tests/Fixtures/Cli/Golden/' . $fileName);
        self::assertIsString($contents);

        // Hand back the fixture text verbatim to compare the CLI's actual output against.
        return $contents;
    }

    /**
     * @param array<mixed> $findings
     * @return list<string>
     */
    private function symbolsFromJsonFindings(array $findings): array
    {
        $symbols = [];

        foreach ($findings as $finding) {
            if (is_array($finding) && is_string($finding['symbol'] ?? null)) {
                $symbols[] = $finding['symbol'];
            }
        }

        // Hand back just the string symbol of each finding; entries without one are silently skipped.
        return $symbols;
    }

    /**
     * @return string PHP source with a changed method body and an unchanged sibling.
     */
    private function changedRegionSource(): string
    {
        // Hand back source with one edited and one untouched method, so diff-mode tests can target a changed region.
        return <<<'PHP'
<?php
final class Example
{
    public function unchanged(): void
    {
        echo 'old';
    }

    public function changed(): void
    {
        echo 'new';
    }
}
PHP;
    }
}
