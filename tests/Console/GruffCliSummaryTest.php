<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Cli\Application;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * Exercises the `summary` command's text digest and JSON output through CLI processes.
 *
 * Covers digest content, selected JSON fields, analysis projection, launch-directory consistency, and exit codes.
 * Checks CLI-list output, invalid options, removed configuration, and bounded-scan diagnostics.
 */
final class GruffCliSummaryTest extends CliTestCase
{
    /**
     * Number of source files gruff-php discovers in the mixed-language summary fixture; its tracked
     * `vendor/ignored.php` is skipped as a third-party copy (FAMILY-CONTRACT.md section 7, precision-floor M04).
     */
    private const MIXED_FIXTURE_FILES = 6;

    /**
     * Verify summary runs and shows digest sections.
     *
     * @return void - Asserts a successful exit and digest headings, numeric tallies, and baseline guidance.
     */
    public function testSummaryRunsAndShowsDigestSections(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'summary',
                                   'tests/Fixtures/Source/mixed',
                                   '--no-config',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $output = $process->getOutput();

        self::assertStringContainsString('gruff-php ' . Application::VERSION . ' summary', $output);
        self::assertStringContainsString('Paths     tests/Fixtures/Source/mixed', $output);
        self::assertMatchesRegularExpression('/^Composite: [A-F] \(\d+\.\d{2} \/ 100\)$/m', $output);
        self::assertMatchesRegularExpression(
            '/^Findings: \d+ total · \d+ error · \d+ warning · \d+ advisory$/m',
            $output,
        );
        self::assertStringContainsString('Score note Each pillar scores on the density of its weighted findings per evaluated file', $output);
        self::assertStringContainsString('Pillars', $output);
        self::assertStringContainsString('Top', $output);
        self::assertStringContainsString('gruff-php analyse --generate-baseline', $output);
        self::assertStringContainsString('known debt', $output);
    }

    /**
     * Verify summary omits warning/advisory markers and the standalone Findings heading.
     *
     * @return void - Asserts a successful exit and the absence of those markers and heading.
     */
    public function testSummaryDoesNotEmitPerFindingLines(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'summary',
                                   'tests/Fixtures/Source/mixed',
                                   '--no-config',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode());

        // The analyse text reporter uses a bare "Findings" heading for per-finding output, which the summary digest must omit.
        // The colon in "Findings:" distinguishes the aggregate tally that remains valid here.
        $output = $process->getOutput();
        self::assertStringNotContainsString('[warning]', $output);
        self::assertStringNotContainsString('[advisory]', $output);
        self::assertDoesNotMatchRegularExpression('/^Findings$/m', $output);
    }

    /**
     * Verify the summary JSON schema version, selected fields, and absence of top-level findings.
     *
     * @return void - Asserts tool and input metadata, score keys, file count, and finding-count keys.
     * @throws JsonException - If CLI stdout cannot be decoded as JSON.
     */
    public function testSummaryJsonOutputMatchesSchema(): void
    {
        $decoded = $this->runMachineJson([
            'summary',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--format',
            'json',
        ]);

        self::assertSame('gruff.summary.v3', $decoded['schemaVersion'] ?? null);
        $tool = $decoded['tool'] ?? null;
        self::assertIsArray($tool);
        self::assertSame('gruff-php', $tool['name'] ?? null);
        self::assertSame(Application::VERSION, $tool['version'] ?? null);

        $runMetadata = $decoded['run'] ?? null;
        self::assertIsArray($runMetadata);
        self::assertSame(['tests/Fixtures/Source/mixed'], $runMetadata['inputs'] ?? null);
        self::assertArrayNotHasKey('config', $runMetadata);

        $score = $decoded['score'] ?? null;
        self::assertIsArray($score);
        $composite = $score['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertArrayHasKey('score', $composite);
        self::assertArrayHasKey('grade', $composite);

        $summary = $decoded['summary'] ?? null;
        self::assertIsArray($summary);
        self::assertSame(self::MIXED_FIXTURE_FILES, $summary['discoveredFiles'] ?? null);
        $findings = $summary['findings'] ?? null;
        self::assertIsArray($findings);
        self::assertArrayHasKey('total', $findings);
        self::assertArrayHasKey('advisory', $findings);
        self::assertArrayHasKey('warning', $findings);
        self::assertArrayHasKey('error', $findings);

        self::assertArrayNotHasKey('findings', $decoded);
    }

    /**
     * Compare selected summary/analyse sections with a project-root launch and check normalized inputs from sibling and subdirectory launches.
     *
     * @return void - Asserts normalized inputs and matching section values for sibling and subdirectory launches.
     * @throws JsonException - If a CLI response cannot be decoded as JSON.
     */
    public function testMachineJsonIsTheSameFromAnyLaunchDirectory(): void
    {
        $workspace = $this->tempDir();
        self::assertTrue(mkdir($workspace . '/project/sub', 0o777, true));
        self::assertTrue(mkdir($workspace . '/sibling'));
        self::assertNotFalse(file_put_contents(
            $workspace . '/project/Sample.php',
            "<?php\n\nfunction sample(\$used, \$unused)\n{\n    return \$used;\n}\n",
        ));
        self::assertNotFalse(file_put_contents($workspace . '/Stray.php', "<?php\n\nfunction stray(\$unused)\n{\n}\n"));
        $launches = [$workspace . '/sibling' => '../project', $workspace . '/project/sub' => '..'];

        try {
            foreach ([['summary'], ['analyse', '--fail-on', 'none', '--no-cache']] as $command) {
                $inside = $this->runMachineJson([...$command, '.', '--no-config', '--format', 'json'], $workspace . '/project');
                foreach ($launches as $launchDirectory => $target) {
                    $outside = $this->runMachineJson([...$command, $target, '--no-config', '--format', 'json'], $launchDirectory);

                    $runMetadata = $outside['run'] ?? null;
                    self::assertIsArray($runMetadata, $command[0] . ' ' . $target);
                    self::assertSame(['.'], $runMetadata['inputs'] ?? null, $command[0] . ' ' . $target . ' inputs');
                    foreach (['score', 'summary', 'paths', 'diagnostics', 'findings'] as $section) {
                        self::assertSame($inside[$section] ?? null, $outside[$section] ?? null, $command[0] . ' ' . $target . ' ' . $section);
                    }
                }
            }
        } finally {
            $this->removeDir($workspace);
        }
    }

    /**
     * Verify both formats exit 2 for a parse error and text exits 0 after the file is repaired.
     *
     * @return void - Asserts failure exits in both formats and successful recovery in text output.
     */
    public function testSummaryExitsTwoInTextAndJsonWhenAParseErrorInvalidatesTheRun(): void
    {
        $workspace = $this->tempDir();
        self::assertNotFalse(file_put_contents($workspace . '/Broken.php', "<?php\n\nfunction broken( {\n"));

        try {
            foreach ([[], ['--format', 'json']] as $formatArguments) {
                $process = new Process(
                    [PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'summary', '.', '--no-config', ...$formatArguments],
                    $workspace,
                );
                $process->run();
                self::assertSame(2, $process->getExitCode(), 'summary ' . implode(' ', $formatArguments) . ': ' . $process->getOutput());
            }

            self::assertNotFalse(file_put_contents($workspace . '/Broken.php', "<?php\n\nfunction mended(): void\n{\n}\n"));
            $mended = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'summary', '.', '--no-config'], $workspace);
            $mended->run();
            self::assertSame(0, $mended->getExitCode(), $mended->getOutput());
        } finally {
            $this->removeDir($workspace);
        }
    }

    /**
     * Verify summary JSON is the exact findings-free projection of analysis JSON for the same inputs.
     *
     * @return void - Asserts equality after removing top-level findings and replacing the analysis schema version.
     * @throws JsonException - If either command's stdout cannot be decoded as JSON.
     */
    public function testSummaryJsonOutputIsExactAnalysisProjection(): void
    {
        $summaryDocument = $this->runMachineJson([
            'summary',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--format',
            'json',
        ]);
        $analysisDocument = $this->runMachineJson([
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--no-baseline',
            '--no-cache',
            '--no-interaction',
            '--format',
            'json',
            '--fail-on',
            'none',
        ]);

        unset($analysisDocument['findings']);
        $analysisDocument['schemaVersion'] = 'gruff.summary.v3';
        self::assertSame($analysisDocument, $summaryDocument);
    }

    /**
     * Verify both compact formats retain the nonfatal budget diagnostic.
     *
     * @return void - Asserts both formats succeed and include the bounded-scan diagnostic and CLI budget.
     */
    public function testSummaryShowsBoundedDeepScanDiagnostic(): void
    {
        foreach (['text', 'json'] as $format) {
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'summary',
                '--no-config',
                '--format',
                $format,
                '--deep-scan-budget',
                '1:1',
                'tests/Fixtures/Source/mixed/alpha.php',
            ], self::PROJECT_ROOT);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('bounded-deep-scan', strtolower($process->getOutput()), $format);
            self::assertStringContainsString('maxLines=1; maxBytes=1; override=cli', $process->getOutput(), $format);
        }
    }

    /**
     * Models a user running summary with an old non-empty secret-preview list.
     * The command must exit 2 and identify the removed key with instructions to delete it.
     *
     * @return void - Asserts exit code 2 and the migration message for allowlists.secretPreviews.
     */
    public function testSummaryRejectsConfiguredLegacySecretPreview(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'summary',
                                   'tests/Fixtures/SensitiveData/synthetic-secrets.php',
                                   '--config',
                                   'tests/Fixtures/Config/allow-aws-preview.yaml',
                                   '--format',
                                   'json',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString(
            '[CONFIG-ERROR] Config key "allowlists.secretPreviews" is removed in 0.6.0: FAMILY-CONTRACT.md section 5 makes category markers unconditional, so the key authorises nothing; delete it from the configuration.',
            $process->getOutput(),
        );
    }

    /**
     * Verify summary rejects invalid option combinations.
     *
     * @param list<string> $arguments - CLI arguments appended after the base command.
     * @param string       $message   - Expected usage error excerpt.
     *
     * @return void - Asserts exit code 2 and the supplied usage-error excerpt for each option case.
     */
    #[DataProvider('invalidSummaryOptionProvider')]
    public function testSummaryRejectsInvalidOptions(array $arguments, string $message): void
    {
        $process = new Process(array_merge([
                                               PHP_BINARY,
                                               self::PROJECT_ROOT . '/bin/gruff-php',
                                               'summary',
                                               'tests/Fixtures/Source/mixed',
                                               '--no-config',
                                           ], $arguments), self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString($message, $process->getOutput());
    }

    /**
     * Keep the summary substring present in the CLI list output.
     *
     * @return void - Asserts a successful list exit and the summary substring in stdout.
     */
    public function testListIncludesSummaryCommand(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list'], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('summary', $process->getOutput());
    }

    /**
     * Run one machine-output CLI command and assert that stdout decodes to an array with string keys.
     *
     * @param list<string> $arguments        - CLI arguments appended after the gruff-php binary.
     * @param string       $workingDirectory - Directory the command is launched from.
     *
     * @return array<string, mixed> - Decoded string-keyed machine document.
     * @throws JsonException - When stdout is not valid JSON.
     */
    private function runMachineJson(array $arguments, string $workingDirectory = self::PROJECT_ROOT): array
    {
        $process = new Process(
            array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $arguments),
            $workingDirectory,
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $document = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $document[$key] = $value;
        }

        return $document;
    }

    /**
     * Verify summary omits disabled maintainability by default and includes it when enabled by config.
     *
     * @return void - Asserts maintainability is absent from default scored pillars and present when enabled.
     * @throws JsonException - If CLI stdout cannot be decoded as JSON.
     */
    public function testSummaryOmitsDisabledMaintainabilityPillarByDefaultAndIncludesWhenEnabled(): void
    {
        $defaultReport = $this->runMachineJson([
            'summary',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--format',
            'json',
        ]);
        $defaultScore = $defaultReport['score'] ?? null;
        self::assertIsArray($defaultScore);
        $defaultScoredPillars = $defaultScore['scoredPillars'] ?? null;
        $defaultPillars       = $defaultScore['pillars'] ?? null;
        self::assertIsArray($defaultScoredPillars);
        self::assertIsArray($defaultPillars);

        self::assertNotContains('maintainability', $defaultScoredPillars);
        self::assertNotContains('maintainability', array_column($defaultPillars, 'pillar'));

        $enabledReport = $this->runMachineJson([
            'summary',
            'tests/Fixtures/Source/mixed',
            '--config',
            'tests/Fixtures/Config/complexity-low-thresholds.yaml',
            '--format',
            'json',
        ]);
        $enabledScore = $enabledReport['score'] ?? null;
        self::assertIsArray($enabledScore);
        $enabledScoredPillars = $enabledScore['scoredPillars'] ?? null;
        $enabledPillars       = $enabledScore['pillars'] ?? null;
        self::assertIsArray($enabledScoredPillars);
        self::assertIsArray($enabledPillars);

        self::assertContains('maintainability', $enabledScoredPillars);
        self::assertContains('maintainability', array_column($enabledPillars, 'pillar'));
    }

    /**
     * Verify a config-less external project uses registry defaults instead of inheriting gruff-php's checkout config.
     *
     * @return void - Asserts run.config is null and default-off maintainability stays absent from scoredPillars.
     * @throws JsonException - If CLI stdout cannot be decoded as JSON.
     */
    public function testSummaryOnConfiglessProjectUsesRegistryDefaultsInsteadOfPackageCheckoutConfig(): void
    {
        $project = $this->tempDir();

        try {
            file_put_contents($project . '/Sample.php', "<?php\nfinal class Sample\n{\n    public function run(): int\n    {\n        return 1;\n    }\n}\n");
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'summary',
                '.',
                '--format=json',
                '--no-interaction',
            ], $project);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $document = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($document);

            $run   = $document['run'] ?? null;
            $score = $document['score'] ?? null;
            self::assertIsArray($run);
            self::assertIsArray($score);
            self::assertNull($run['config'] ?? null);
            $scoredPillars = $score['scoredPillars'] ?? null;
            self::assertIsArray($scoredPillars);
            self::assertNotContains('maintainability', $scoredPillars);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Provide invalid summary option combinations and their usage errors.
     *
     * @return iterable<string, array{0: list<string>, 1: string}> - Named cases pairing extra CLI arguments with expected stdout excerpts.
     */
    public static function invalidSummaryOptionProvider(): iterable
    {
        yield 'unknown format' => [
            ['--format', 'yaml'],
            'USAGE-ERROR Unsupported summary format "yaml"',
        ];
        yield 'non integer top' => [
            ['--top', 'lots'],
            'USAGE-ERROR --top must be a non-negative integer.',
        ];
        yield 'config with no config' => [
            ['--config', '.gruff-php.yaml'],
            '--no-config cannot be combined with --config.',
        ];
    }
}
