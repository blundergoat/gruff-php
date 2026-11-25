<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Exercise report output on stdout and disk, including HTML options, JSON and baseline suppression.
 *
 * Cover selected analyse options, new-findings failure status, dash-prefixed paths and sensitive-snippet redaction.
 * Check invalid profile and configuration cases, including preservation of existing output files.
 */
final class ReportCliTest extends CliTestCase
{
    /**
     * Raw sensitive snippets that report surfaces must never render.
     *
     * @var list<string> - Synthetic fixture fragments checked for absence in HTML and JSON output.
     */
    private const SENSITIVE_SNIPPETS = ['MIIBVgIBADANBgkqhkiG', 's3cr3tValue', 'Tok3nXyZ9'];

    /**
     * Verify report command outputs static HTML report.
     *
     * @return void - Asserts exit 0, report markers and absence of the dashboard toolbar.
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
     * @return void - Asserts editor links and filters appear when enabled, and filters disappear when disabled.
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
     * @throws JsonException - If the CLI output cannot be decoded as JSON.
     *
     * @return void - Asserts exit 0 and schemaVersion gruff.analysis.v3 in decoded output.
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

    /**
     * Checks that reporting a sibling project reads its exit threshold.
     *
     * @return void - Asserts the target configuration trips its advisory gate.
     */
    public function testReportUsesTargetProjectGateFromSibling(): void
    {
        $workspace = $this->tempDir();

        try {
            self::assertTrue(mkdir($workspace . '/launch'));
            self::assertTrue(mkdir($workspace . '/target'));
            file_put_contents($workspace . '/target/.gruff-php.yaml', "schemaVersion: gruff-php.config.v0.1\nfailOn: {report: advisory}\n");
            file_put_contents($workspace . '/target/Example.php', '<?php class Example { public function emptyMethod(): void {} }');

            $process = new Process([
                PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'report', '../target',
                '--format=json', '--no-cache', '--include-rule=waste.empty-method',
            ], $workspace . '/launch');
            self::assertSame(1, $process->run(), $process->getOutput() . $process->getErrorOutput());
            $report = $this->decodeJsonOutput($process);
            self::assertSame('advisory', $this->decodedJsonObject($report['run'] ?? null)['failOn'] ?? null);
        } finally {
            $this->removeDir($workspace);
        }
    }

    /**
     * Verify report forwards the CLI budget and preserves its diagnostic.
     *
     * @return void - Asserts exit 0 and a bounded-deep-scan diagnostic identifying the CLI override.
     */
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
     * Verify report accepts the supplied analyse options and exposes an active diff in JSON output.
     *
     * @throws JsonException - If the CLI output cannot be decoded as JSON.
     *
     * @return void - Asserts exit 0, schemaVersion gruff.analysis.v3 and an enabled diff section.
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
     * Verify the report command returns exit 1 with --fail-on-new and still writes the requested artifact.
     *
     * @return void - Asserts exit 1 and creation of the requested HTML output file.
     */
    public function testReportCommandForwardsFailOnNewAndStillWritesArtifact(): void
    {
        $repo       = $this->tempDir();
        $outputPath = $repo . '/gruff-report.html';

        try {
            // Commit the documented source first so Newcomer.php is the only source addition compared with HEAD.
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
     * Verify report rejects a documentation rule included under the security profile.
     *
     * @return void - Asserts exit 2, the profile-selection diagnostic and no schemaVersion in stdout.
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
            'docs.missing-phpdoc',
            '--no-config',
            '--no-baseline',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('selects a documentation rule', $process->getOutput());

        self::assertStringNotContainsString('schemaVersion', $process->getOutput());
    }

    /**
     * Verify an unsupported report profile fails without creating project configuration.
     *
     * @return void - Asserts exit 2, the unsupported-profile diagnostic and absence of .gruff-php.yaml.
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
     * @return void - Asserts successful completion of Git in the supplied fixture repository.
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
     * @return void - Asserts selected help options are present and --generate-baseline and the --file help entry are absent.
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
            '--diff-base',
            '--min-confidence',
            '--show-rule',
            '--hide-pillar',
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
        // Check each option a report caller can use so the help stays aligned with forwarding.
        foreach ($reportSupportedOptions as $optionName) {
            self::assertStringContainsString($optionName, $helpText, sprintf('report --help must document %s.', $optionName));
        }

        // Analyse-only by decision: baseline writing stays with analyse, and the paths argument
        // already covers explicit file selection for report.
        self::assertStringNotContainsString('--generate-baseline', $helpText);
        self::assertStringNotContainsString('--file ', $helpText);
    }

    /**
     * Verify HTML and JSON report output omit the configured sensitive fixture snippets.
     *
     * @return void - Asserts exit 0, absence of each listed snippet and a redacted marker for both formats.
     */
    public function testReportCommandDoesNotLeakSensitiveDataSecrets(): void
    {
        // Test both saved-report formats for accidental exposure of a raw sensitive match.
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
                '--config',
                'tests/Fixtures/Config/enable-default-off-rules.yaml',
            ], self::PROJECT_ROOT);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
            // Search for every fixture value that a report must redact before it reaches the caller.
            foreach (self::SENSITIVE_SNIPPETS as $sensitiveSnippet) {
                self::assertStringNotContainsString($sensitiveSnippet, $process->getOutput(), sprintf('%s report leaked a raw secret.', $format));
            }
            self::assertStringContainsString('redacted', $process->getOutput(), sprintf('%s report should show a redacted preview.', $format));
        }
    }

    /**
     * Verify report applies a single --include-rule filter to JSON findings.
     *
     * @throws JsonException - If the CLI output cannot be decoded as JSON.
     *
     * @return void - Asserts exit 0 and exactly one finding with ruleId docs.missing-phpdoc.
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
            'docs.missing-phpdoc',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        self::assertCount(1, $findings);
        $firstFinding = $findings[0] ?? null;
        self::assertIsArray($firstFinding);
        self::assertSame('docs.missing-phpdoc', $firstFinding['ruleId'] ?? null);
    }

    /**
     * Verify report command writes static HTML report.
     *
     * @return void - Asserts exit 0, the write confirmation and an output file containing the verdict section.
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
     * @return void - Asserts exit 0 and an output substring naming the file --dash.php.
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
     * @return void - Asserts exit 2 and preservation of the existing report file contents.
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
     * @throws JsonException - If the CLI output cannot be decoded as JSON.
     *
     * @return void - Asserts exit 0 and one suppressed finding after loading the generated baseline.
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

    /**
     * Verify an explicit empty --fail-on= fails validation with exit 2 instead of silently disabling the gate.
     *
     * @return void - Asserts exit code 2 and the unsupported fail-threshold diagnostic.
     */
    public function testReportCommandRejectsEmptyFailOnOption(): void
    {
        $project = $this->createBaselineProject();

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'report',
                'src',
                '--no-config',
                '--format',
                'json',
                '--fail-on=',
            ], $project);
            $process->run();

            self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            self::assertStringContainsString('Unsupported fail threshold ""', $process->getOutput() . $process->getErrorOutput());
        } finally {
            $this->removeDir($project);
        }
    }
}
