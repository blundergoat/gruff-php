<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Mutation;

use GruffPhp\Mutation\InfectionReportParser;
use GruffPhp\Mutation\InfectionRunner;
use GruffPhp\Mutation\MutationReportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Covers Infection JSON report parsing: full reports, optional status fields, malformed/missing/invalid input rejection, and runner integration with project vendor binaries and config.
 *
 * @phpstan-type InvalidReportScalar bool|float|int|object|string|null
 * @phpstan-type InvalidReportLevel10 array<array-key, InvalidReportScalar>
 * @phpstan-type InvalidReportLevel9 array<array-key, InvalidReportScalar|InvalidReportLevel10>
 * @phpstan-type InvalidReportLevel8 array<array-key, InvalidReportScalar|InvalidReportLevel9>
 * @phpstan-type InvalidReportLevel7 array<array-key, InvalidReportScalar|InvalidReportLevel8>
 * @phpstan-type InvalidReportLevel6 array<array-key, InvalidReportScalar|InvalidReportLevel7>
 * @phpstan-type InvalidReportLevel5 array<array-key, InvalidReportScalar|InvalidReportLevel6>
 * @phpstan-type InvalidReportLevel4 array<array-key, InvalidReportScalar|InvalidReportLevel5>
 * @phpstan-type InvalidReportLevel3 array<array-key, InvalidReportScalar|InvalidReportLevel4>
 * @phpstan-type InvalidReportLevel2 array<array-key, InvalidReportScalar|InvalidReportLevel3>
 * @phpstan-type InvalidReportLevel1 array<array-key, InvalidReportScalar|InvalidReportLevel2>
 * @phpstan-type InvalidReportValue InvalidReportScalar|InvalidReportLevel1|InvalidReportLevel2|InvalidReportLevel3|InvalidReportLevel4|InvalidReportLevel5|InvalidReportLevel6|InvalidReportLevel7|InvalidReportLevel8|InvalidReportLevel9|InvalidReportLevel10
 * @phpstan-type InvalidReportShape array<string, InvalidReportValue>
 */
final class InfectionReportParserTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify parses full infection JSON report.
     *
     * @return void
     */
    public function testParsesFullInfectionJsonReport(): void
    {
        $report = (new InfectionReportParser(self::PROJECT_ROOT))->parse(
            'tests/Fixtures/Mutation/Infection/infection-valid.json',
        );

        self::assertSame('tests/Fixtures/Mutation/Infection/infection-valid.json', $report->reportPath);
        self::assertSame(4, $report->totalMutants());
        self::assertSame(50.0, $report->msi());
        self::assertSame(50.0, $report->coveredMsi());
        self::assertSame(100.0, $report->coverageRate());
        self::assertCount(2, $report->survivedMutants());

        $fileSummaries = $report->fileSummaries();

        self::assertCount(1, $fileSummaries);
        self::assertSame('tests/Fixtures/Source/Code/OrderCalculator.php', $fileSummaries[0]->filePath);
        self::assertSame(4, $fileSummaries[0]->totalMutants);
        self::assertSame(2, $fileSummaries[0]->survivedMutants);
        self::assertSame(50.0, $fileSummaries[0]->msi);
    }

    /**
     * Verify parser normalises every Infection status section and optional mutant fields.
     *
     * @return void
     */
    public function testParsesStatusSectionsOptionalFieldsAndFileSummaries(): void
    {
        $projectRoot = $this->tempDir();

        try {
            self::assertTrue(mkdir($projectRoot . '/reports', 0777, true));
            $this->writeReport($projectRoot . '/reports/infection.json', [
                'stats' => [
                    'totalMutantsCount' => 8,
                    'killedCount' => 2,
                    'escapedCount' => 1,
                    'timedOutCount' => 1,
                    'msi' => 25.0,
                    'coveredCodeMsi' => 50.0,
                    'mutationCodeCoverage' => 75.0,
                ],
                'escaped' => [$this->mutant(
                    filePath:      $projectRoot . '/src/Alpha.php',
                    mutatorName:   'Plus',
                    line:          10,
                    diff:          'diff',
                    processOutput: 'escaped-output',
                )],
                'timeouted' => [$this->mutant($projectRoot . '/src/Alpha.php', 'Timeout', 11)],
                'killed' => [$this->mutant(
                    filePath:      $projectRoot . '/src/Alpha.php',
                    mutatorName:   'Killed',
                    line:          12,
                    diff:          '',
                    processOutput: '',
                )],
                'killedByStaticAnalysis' => [$this->mutant($projectRoot . '/src/Alpha.php', 'StaticKill', null)],
                'errored' => [$this->mutant($projectRoot . '/src/Beta.php', 'Error', 20)],
                'syntaxErrors' => [$this->mutant($projectRoot . '/src/Beta.php', 'Syntax', 21)],
                'uncovered' => [$this->mutant($projectRoot . '/src/Alpha.php', 'Uncovered', 13)],
                'ignored' => [$this->mutant($projectRoot . '/src/Beta.php', 'Ignored', 22)],
            ]);

            $report    = (new InfectionReportParser($projectRoot))->parse('reports/infection.json');
            $mutants   = array_map(static fn ($mutant): array => $mutant->toArray(), $report->mutants);
            $summaries = array_map(static fn ($summary): array => $summary->toArray(), $report->fileSummaries());

            self::assertSame('reports/infection.json', $report->reportPath);
            self::assertSame(8, $report->totalMutants());
            self::assertSame(25.0, $report->msi());
            self::assertSame(50.0, $report->coveredMsi());
            self::assertSame(75.0, $report->coverageRate());
            self::assertCount(2, $report->survivedMutants());
            self::assertSame([
                'error' => 1,
                'escaped' => 1,
                'ignored' => 1,
                'killed' => 1,
                'killed by SA' => 1,
                'not covered' => 1,
                'syntax error' => 1,
                'timed out' => 1,
            ], $report->statusCounts());
            self::assertSame([
                [
                    'status' => 'escaped',
                    'file' => 'src/Alpha.php',
                    'line' => 10,
                    'mutator' => 'Plus',
                    'diff' => 'diff',
                    'processOutput' => 'escaped-output',
                ],
                [
                    'status' => 'timed out',
                    'file' => 'src/Alpha.php',
                    'line' => 11,
                    'mutator' => 'Timeout',
                    'diff' => null,
                    'processOutput' => null,
                ],
                [
                    'status' => 'killed',
                    'file' => 'src/Alpha.php',
                    'line' => 12,
                    'mutator' => 'Killed',
                    'diff' => null,
                    'processOutput' => null,
                ],
                [
                    'status' => 'killed by SA',
                    'file' => 'src/Alpha.php',
                    'line' => null,
                    'mutator' => 'StaticKill',
                    'diff' => null,
                    'processOutput' => null,
                ],
                [
                    'status' => 'error',
                    'file' => 'src/Beta.php',
                    'line' => 20,
                    'mutator' => 'Error',
                    'diff' => null,
                    'processOutput' => null,
                ],
                [
                    'status' => 'syntax error',
                    'file' => 'src/Beta.php',
                    'line' => 21,
                    'mutator' => 'Syntax',
                    'diff' => null,
                    'processOutput' => null,
                ],
                [
                    'status' => 'not covered',
                    'file' => 'src/Alpha.php',
                    'line' => 13,
                    'mutator' => 'Uncovered',
                    'diff' => null,
                    'processOutput' => null,
                ],
                [
                    'status' => 'ignored',
                    'file' => 'src/Beta.php',
                    'line' => 22,
                    'mutator' => 'Ignored',
                    'diff' => null,
                    'processOutput' => null,
                ],
            ], $mutants);
            self::assertSame([
                [
                    'file' => 'src/Alpha.php',
                    'totalMutants' => 5,
                    'killedMutants' => 2,
                    'survivedMutants' => 2,
                    'notCoveredMutants' => 1,
                    'msi' => 40.0,
                    'coveredMsi' => 50.0,
                ],
                [
                    'file' => 'src/Beta.php',
                    'totalMutants' => 3,
                    'killedMutants' => 0,
                    'survivedMutants' => 0,
                    'notCoveredMutants' => 0,
                    'msi' => 0.0,
                    'coveredMsi' => 0.0,
                ],
            ], $summaries);
        } finally {
            $this->removeDir($projectRoot);
        }
    }

    /**
     * Verify rejects malformed infection JSON report.
     *
     * @return void
     */
    public function testRejectsMalformedInfectionJsonReport(): void
    {
        $this->expectException(MutationReportException::class);
        $this->expectExceptionMessage('contains a non-numeric stats value');

        (new InfectionReportParser(self::PROJECT_ROOT))->parse('tests/Fixtures/Mutation/Infection/infection-malformed.json');
    }

    /**
     * Verify missing reports are rejected before decoding.
     *
     * @return void
     */
    public function testRejectsMissingInfectionReport(): void
    {
        $this->expectException(MutationReportException::class);
        $this->expectExceptionMessage('Infection report not found: missing-report.json');

        (new InfectionReportParser(self::PROJECT_ROOT))->parse('missing-report.json');
    }

    /**
     * Verify malformed report shapes surface specific diagnostics.
     *
     * @param InvalidReportShape $report  Report payload.
     * @param string             $message Expected exception message fragment.
     * @return void
     */
    #[DataProvider('invalidReportProvider')]
    public function testRejectsInvalidReportShapes(array $report, string $message): void
    {
        $projectRoot = $this->tempDir();

        try {
            $this->writeReport($projectRoot . '/report.json', $report);

            $this->expectException(MutationReportException::class);
            $this->expectExceptionMessage($message);

            (new InfectionReportParser($projectRoot))->parse('report.json');
        } finally {
            $this->removeDir($projectRoot);
        }
    }

    /**
     * Provide invalid report cases for parameterized tests.
     *
     * @return array<string, array{InvalidReportShape, string}>
     */
    public static function invalidReportProvider(): array
    {
        $validStats = [
            'totalMutantsCount' => 1,
            'msi' => 0.0,
            'coveredCodeMsi' => 0.0,
            'mutationCodeCoverage' => 0.0,
        ];
        // Each case pairs a deliberately malformed report with the substring its rejection message must contain.
        return [
            'missing stats' => [
                ['escaped' => []],
                'must contain a "stats" object',
            ],
            'missing required stat' => [
                ['stats' => ['totalMutantsCount' => 1, 'msi' => 0.0, 'coveredCodeMsi' => 0.0], 'escaped' => []],
                'is missing stats.mutationCodeCoverage',
            ],
            'section must be list' => [
                ['stats' => $validStats, 'escaped' => ['bad' => 'shape']],
                'section "escaped" must be a JSON array',
            ],
            'mutant must be object' => [
                ['stats' => $validStats, 'escaped' => ['bad']],
                'mutant escaped[0] must be a JSON object',
            ],
            'mutator must be object' => [
                ['stats' => $validStats, 'escaped' => [['mutator' => 'bad']]],
                'mutant escaped[0] must contain a mutator object',
            ],
            'missing mutator field' => [
                ['stats' => $validStats, 'escaped' => [['mutator' => ['originalFilePath' => 'src/File.php']]]],
                'is missing mutator.mutatorName',
            ],
            'non-integer line' => [
                ['stats' => $validStats, 'escaped' => [[
                    'mutator' => [
                        'mutatorName' => 'Plus',
                        'originalFilePath' => 'src/File.php',
                        'originalStartLine' => '12',
                    ],
                ]]],
                'has a non-integer mutator.originalStartLine',
            ],
            'too deep json' => [
                ['stats' => $validStats, 'escaped' => [[
                    'mutator' => [
                        'mutatorName' => 'Plus',
                        'originalFilePath' => 'src/File.php',
                        'extra' => [[[[['too' => 'deep']]]]],
                    ],
                ]]],
                'Infection report nesting is deeper than supported',
            ],
        ];
    }

    /**
     * Verify infection runner prefers project vendor binary.
     *
     * @return void
     */
    public function testInfectionRunnerPrefersProjectVendorBinary(): void
    {
        $projectRoot = $this->tempDir();
        $binaryPath  = $projectRoot . '/vendor/bin/infection';

        try {
            self::assertTrue(mkdir(dirname($binaryPath), 0777, true));
            self::assertNotFalse(file_put_contents($binaryPath, "#!/usr/bin/env sh\nprintf 'local-infection %s' \"$*\"\n"));
            self::assertTrue(chmod($binaryPath, 0755));

            $result = (new InfectionRunner())->runInfection($projectRoot, 'infection', null);

            self::assertSame(0, $result->exitCode);
            self::assertStringContainsString('local-infection run --no-progress --log-verbosity=none', $result->output);
            self::assertNull($result->diagnostic);
        } finally {
            $this->removeDir($projectRoot);
        }
    }

    /**
     * Verify infection runner uses project infection config by default.
     *
     * @return void
     */
    public function testInfectionRunnerUsesProjectInfectionConfigByDefault(): void
    {
        $projectRoot = $this->tempDir();
        $binaryPath  = $projectRoot . '/vendor/bin/infection';

        try {
            self::assertTrue(mkdir(dirname($binaryPath), 0777, true));
            self::assertNotFalse(file_put_contents($binaryPath, "#!/usr/bin/env sh\nprintf 'local-infection %s' \"$*\"\n"));
            self::assertTrue(chmod($binaryPath, 0755));
            self::assertNotFalse(file_put_contents($projectRoot . '/infection.json5', "{}\n"));

            $result = (new InfectionRunner())->runInfection($projectRoot, 'infection', null);

            self::assertSame(0, $result->exitCode);
            self::assertStringContainsString(
                'local-infection run --no-progress --log-verbosity=none --configuration ' . $projectRoot . '/infection.json5',
                $result->output,
            );
            self::assertNull($result->diagnostic);
        } finally {
            $this->removeDir($projectRoot);
        }
    }

    /**
     * Verify infection runner passes test framework options.
     *
     * @return void
     */
    public function testInfectionRunnerPassesTestFrameworkOptions(): void
    {
        $projectRoot = $this->tempDir();
        $binaryPath  = $projectRoot . '/vendor/bin/infection';

        try {
            self::assertTrue(mkdir(dirname($binaryPath), 0777, true));
            self::assertNotFalse(file_put_contents($binaryPath, "#!/usr/bin/env sh\nprintf 'local-infection %s' \"$*\"\n"));
            self::assertTrue(chmod($binaryPath, 0755));

            $result = (new InfectionRunner())->runInfection(
                $projectRoot,
                'infection',
                null,
                '--testsuite=unit --filter=ExampleTest',
            );

            self::assertSame(0, $result->exitCode);
            self::assertStringContainsString(
                'local-infection run --no-progress --log-verbosity=none --test-framework-options=--testsuite=unit --filter=ExampleTest',
                $result->output,
            );
            self::assertNull($result->diagnostic);
        } finally {
            $this->removeDir($projectRoot);
        }
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-infection-runner-' . bin2hex(random_bytes(6));

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            self::fail(sprintf('Unable to create temp directory: %s', $path));
        }
        // Hand back the freshly created temp directory; the caller owns removing it after the test.
        return $path;
    }

    /**
     * Build one infection mutant payload for parser tests.
     *
     * @param string   $filePath      Source path the mutant claims to touch, mirrored into mutator.originalFilePath.
     * @param string   $mutatorName   Infection mutator label, e.g. "Plus"; drives how the parser classifies the mutant.
     * @param int|null $line          One-based source line; null omits originalStartLine to hit the optional branch.
     * @param string   $diff          Unified diff body the report would carry; blank when a test does not assert on it.
     * @param string   $processOutput Captured runner stdout for the mutant; blank when irrelevant to the case.
     * @return array{mutator: array{mutatorName: string, originalFilePath: string, originalStartLine?: int}, diff: string, processOutput: string}
     */
    private function mutant(string $filePath, string $mutatorName, ?int $line, string $diff = '', string $processOutput = ''): array
    {
        $mutator = [
            'mutatorName' => $mutatorName,
            'originalFilePath' => $filePath,
        ];

        if ($line !== null) {
            $mutator['originalStartLine'] = $line;
        }
        // Hand back the assembled mutant entry in the exact shape Infection writes into its escaped/killed lists.
        return [
            'mutator' => $mutator,
            'diff' => $diff,
            'processOutput' => $processOutput,
        ];
    }

    /**
     * Write an Infection report fixture to a temporary file.
     *
     * @param string             $path   Destination file the JSON-encoded fixture is written to; caller cleans it up.
     * @param InvalidReportShape $report
     * @return void
     */
    private function writeReport(string $path, array $report): void
    {
        self::assertNotFalse(file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR)));
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Filesystem path.
     * @return void
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            // Nothing to tear down when the directory was never created, so treat removal as already done.
            return;
        }

        $recursiveIteratorIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($recursiveIteratorIterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
