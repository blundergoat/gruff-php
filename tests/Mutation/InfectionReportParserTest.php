<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Mutation;

use GruffPhp\Mutation\InfectionReportParser;
use GruffPhp\Mutation\InfectionRunner;
use GruffPhp\Mutation\MutationReportException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class InfectionReportParserTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify parses full infection JSON report.
     *
     * @return void No return value.
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
     * Verify rejects malformed infection JSON report.
     *
     * @return void No return value.
     */
    public function testRejectsMalformedInfectionJsonReport(): void
    {
        $this->expectException(MutationReportException::class);
        $this->expectExceptionMessage('contains a non-numeric stats value');

        (new InfectionReportParser(self::PROJECT_ROOT))->parse('tests/Fixtures/Mutation/Infection/infection-malformed.json');
    }

    /**
     * Verify infection runner prefers project vendor binary.
     *
     * @return void No return value.
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
     * @return void No return value.
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
     * @return void No return value.
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
     * @return string Fixture value.
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-infection-runner-' . bin2hex(random_bytes(6));

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            self::fail(sprintf('Unable to create temp directory: %s', $path));
        }

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Filesystem path.
     * @return void No return value.
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
