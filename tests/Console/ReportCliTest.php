<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers the report CLI command: static and JSON output, HTML report flag forwarding, repeated rule filter forwarding, dash-prefixed path preservation, baseline flag, and no-write-on-invalid-analyse behaviour.
 */
final class ReportCliTest extends CliTestCase
{
    /**
     * Raw sensitive snippets that report surfaces must never render.
     *
     * @var list<string>
     */
    private const SENSITIVE_SNIPPETS = ['MIIBVgIBADANBgkqhkiG', 's3cr3tValue', 'Tok3nXyZ9'];

    /**
     * Verify report command outputs static HTML report.
     *
     * @return void
     */
    public function testReportCommandOutputsStaticHtmlReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('<section class="verdict">', $process->getOutput());
        self::assertStringContainsString('inspection report', $process->getOutput());
        self::assertStringNotContainsString('gruff-dashboard-toolbar', $process->getOutput());
    }

    /**
     * Verify report command forwards HTML report flags.
     *
     * @return void
     */
    public function testReportCommandForwardsHtmlReportFlags(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--fail-on',
            'none',
            '--report-editor-link',
            'vscode',
            '--report-interactive',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('href="vscode://file/', $process->getOutput());
        self::assertStringContainsString('class="finding-filters"', $process->getOutput());

        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--fail-on',
            'none',
            '--report-interactive=false',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('class="finding-filters"', $process->getOutput());
    }

    /**
     * Verify report command outputs JSON report.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testReportCommandOutputsJsonReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--format',
            'json',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
        self::assertSame('gruff.analysis.v3', $report['schemaVersion'] ?? null);
    }

    /** Verify report forwards the CLI budget and preserves its diagnostic. */
    public function testReportCommandForwardsDeepScanBudget(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            '--format',
            'json',
            '--fail-on',
            'none',
            '--no-config',
            '--no-cache',
            '--deep-scan-budget',
            '1:1',
            'tests/Fixtures/Source/mixed/alpha.php',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report      = $this->decodeJsonOutput($process);
        $diagnostics = $report['diagnostics'] ?? null;
        self::assertIsArray($diagnostics);
        $diagnostic = $diagnostics[0] ?? null;
        self::assertIsArray($diagnostic);
        self::assertSame('bounded-deep-scan', $diagnostic['type'] ?? null);
        $message = $diagnostic['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('override=cli', $message);
    }

    /**
     * Verify report accepts and forwards the newer analyse workflows it used to reject.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testReportCommandForwardsChangedScopeGatingAndCacheOptions(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--format',
            'json',
            '--fail-on',
            'none',
            '--no-config',
            '--no-baseline',
            '--no-cache',
            '--since',
            'HEAD',
            '--changed-scope',
            'file',
            '--baseline-include-absent',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $report = $this->decodeJsonOutput($process);
        self::assertSame('gruff.analysis.v3', $report['schemaVersion'] ?? null);
        // --since forwarded: the child records an active diff block for the changed-region scan.
        $diff = $report['diff'] ?? null;
        self::assertIsArray($diff);
        self::assertTrue($diff['enabled'] ?? null);
    }

    /**
     * Verify --fail-on-new forwards with gating semantics: a real introduced finding trips exit 1
     * while the artifact is still written.
     *
     * @return void
     */
    public function testReportCommandForwardsFailOnNewAndStillWritesArtifact(): void
    {
        $repo       = $this->tempDir();
        $outputPath = $repo . '/gruff-report.html';

        try {
            // A one-commit repo whose working tree then gains an error-severity finding: if the
            // forwarded --fail-on-new were silently dropped, the run would exit 0 and this test fails.
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            file_put_contents(
                $repo . '/src/Documented.php',
                "<?php\n\nclass Documented\n{\n    /**\n     * Say hello to the caller.\n     */\n    public function greet(): string\n    {\n        return 'hello';\n    }\n}\n",
            );
            $this->runGit($repo, 'init', '-q');
            $this->runGit($repo, 'config', 'user.email', 'test@example.com');
            $this->runGit($repo, 'config', 'user.name', 'Gruff Test');
            $this->runGit($repo, 'add', 'src/Documented.php');
            $this->runGit($repo, 'commit', '-qm', 'base');

            file_put_contents(
                $repo . '/src/Newcomer.php',
                "<?php\n\nclass Newcomer\n{\n    public function arrive(): string\n    {\n        return 'new';\n    }\n}\n",
            );

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'report',
                'src',
                '--format',
                'html',
                '--output',
                $outputPath,
                '--fail-on',
                'none',
                '--fail-on-new',
                '--diff-vs',
                'HEAD',
                '--no-config',
                '--no-baseline',
            ], $repo);
            $process->run();

            self::assertSame(1, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
            self::assertFileExists($outputPath);
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify the incoherent profile/include combination is rejected before any prompt or subprocess.
     *
     * @return void
     */
    public function testReportCommandRejectsOutOfProfileIncludeBeforeSpawningAnalyse(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--format',
            'json',
            '--fail-on',
            'none',
            '--profile',
            'security',
            '--include-rule',
            'docs.missing-public-phpdoc',
            '--no-config',
            '--no-baseline',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('selects a documentation rule', $process->getOutput());
        // No report body means report itself rejected the run before spawning the analyse child.
        self::assertStringNotContainsString('schemaVersion', $process->getOutput());
    }

    /**
     * Verify unsupported profiles are rejected before report can offer or write config.
     *
     * @return void
     */
    public function testReportCommandRejectsUnknownProfileBeforeConfigPrompt(): void
    {
        $repo = $this->createBaselineProject();

        try {
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'report',
                'src',
                '--profile',
                'security-plus',
                '--fail-on',
                'none',
            ], $repo);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('Unsupported profile "security-plus". Use default or security.', $process->getOutput());
            self::assertFileDoesNotExist($repo . '/.gruff-php.yaml');
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Run git inside a fixture repository.
     *
     * @param string $repo         - Fixture repository root.
     * @param string ...$arguments - Git arguments.
     *
     * @return void
     */
    private function runGit(string $repo, string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments], $repo);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Verify the curated analyse option surface is exposed by report, minus the documented analyse-only flags.
     *
     * @return void
     */
    public function testReportCommandOptionParityWithAnalyse(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            '--help',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $helpText = $process->getOutput();

        $reportSupportedOptions = [
            '--profile',
            '--fail-on-new',
            '--no-cache',
            '--since',
            '--changed-ranges',
            '--changed-scope',
            '--baseline-include-absent',
            '--infection-run',
            '--infection-bin',
            '--infection-config',
            '--infection-test-framework-options',
            '--print-runtime',
            '--runtime-mode',
        ];
        foreach ($reportSupportedOptions as $optionName) {
            self::assertStringContainsString($optionName, $helpText, sprintf('report --help must document %s.', $optionName));
        }

        // Analyse-only by decision: baseline writing stays with analyse, and the paths argument
        // already covers explicit file selection for report.
        self::assertStringNotContainsString('--generate-baseline', $helpText);
        self::assertStringNotContainsString('--file ', $helpText);
    }

    /**
     * Verify report command does not leak raw sensitive-data snippets.
     *
     * @return void
     */
    public function testReportCommandDoesNotLeakSensitiveDataSecrets(): void
    {
        foreach (['html', 'json'] as $format) {
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'report',
                'tests/Fixtures/SensitiveData/gcp-service-account-key.json',
                'tests/Fixtures/SensitiveData/url-credentials.php',
                '--format',
                $format,
                '--fail-on',
                'none',
                '--no-config',
            ], self::PROJECT_ROOT);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
            foreach (self::SENSITIVE_SNIPPETS as $sensitiveSnippet) {
                self::assertStringNotContainsString($sensitiveSnippet, $process->getOutput(), sprintf('%s report leaked a raw secret.', $format));
            }
            self::assertStringContainsString('redacted', $process->getOutput(), sprintf('%s report should show a redacted preview.', $format));
        }
    }

    /**
     * Verify report command forwards repeated rule filters.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testReportCommandForwardsRepeatedRuleFilters(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--format',
            'json',
            '--fail-on',
            'none',
            '--no-config',
            '--include-rule',
            'docs.missing-public-phpdoc',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        self::assertCount(1, $findings);
        $firstFinding = $findings[0] ?? null;
        self::assertIsArray($firstFinding);
        self::assertSame('docs.missing-public-phpdoc', $firstFinding['ruleId'] ?? null);
    }

    /**
     * Verify report command writes static HTML report.
     *
     * @return void
     */
    public function testReportCommandWritesStaticHtmlReport(): void
    {
        $tempDir    = $this->tempDir();
        $reportPath = $tempDir . '/gruff-report.html';

        try {
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'report',
                'tests/Fixtures/Source/Code',
                '--output',
                $reportPath,
            ], self::PROJECT_ROOT);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('Report written to', $process->getOutput());
            self::assertFileExists($reportPath);

            $html = file_get_contents($reportPath);
            self::assertIsString($html);
            self::assertStringContainsString('<section class="verdict">', $html);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify report command preserves dash-prefixed path arguments when delegating to analyse.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testReportCommandPreservesDashPrefixedPaths(): void
    {
        $project = $this->tempDir();

        try {
            file_put_contents($project . '/--dash.php', "<?php\nfinal class Dash { public function run(): void {} }\n");

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'report',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--no-config',
                '--no-baseline',
                '--',
                '--dash.php',
            ], $project);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('"file": "--dash.php"', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify report command does not overwrite output files when analyse exits invalid.
     *
     * @return void
     */
    public function testReportCommandDoesNotWriteOutputAfterInvalidAnalyseRun(): void
    {
        $tempDir    = $this->tempDir();
        $reportPath = $tempDir . '/gruff-report.json';

        try {
            file_put_contents($reportPath, 'existing report');

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'report',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--config',
                'missing.yaml',
                '--output',
                $reportPath,
            ], self::PROJECT_ROOT);
            $process->run();

            self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            self::assertSame('existing report', file_get_contents($reportPath));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify report command forwards baseline flag.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testReportCommandForwardsBaselineFlag(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generateProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generateProcess->run();
            self::assertSame(0, $generateProcess->getExitCode(), $generateProcess->getErrorOutput());

            $reportProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'report',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--baseline=gruff-baseline.json',
            ], $project);
            $reportProcess->run();

            self::assertSame(0, $reportProcess->getExitCode(), $reportProcess->getErrorOutput());
            $decoded  = $this->decodeJsonOutput($reportProcess);
            $baseline = $decoded['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame(1, $baseline['suppressedFindings'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }
}
