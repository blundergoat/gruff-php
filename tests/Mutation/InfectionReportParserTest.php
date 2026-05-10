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

    public function testParsesFullInfectionJsonReport(): void
    {
        $report = (new InfectionReportParser(self::PROJECT_ROOT))->parse(
            'tests/Fixtures/M14/Infection/infection-valid.json',
        );

        self::assertSame('tests/Fixtures/M14/Infection/infection-valid.json', $report->reportPath);
        self::assertSame(4, $report->totalMutants());
        self::assertSame(50.0, $report->msi());
        self::assertSame(50.0, $report->coveredMsi());
        self::assertSame(100.0, $report->coverageRate());
        self::assertCount(2, $report->survivedMutants());

        $fileSummaries = $report->fileSummaries();

        self::assertCount(1, $fileSummaries);
        self::assertSame('tests/Fixtures/M14/Source/OrderCalculator.php', $fileSummaries[0]->filePath);
        self::assertSame(4, $fileSummaries[0]->totalMutants);
        self::assertSame(2, $fileSummaries[0]->survivedMutants);
        self::assertSame(50.0, $fileSummaries[0]->msi);
    }

    public function testRejectsMalformedInfectionJsonReport(): void
    {
        $this->expectException(MutationReportException::class);
        $this->expectExceptionMessage('contains a non-numeric stats value');

        (new InfectionReportParser(self::PROJECT_ROOT))->parse('tests/Fixtures/M14/Infection/infection-malformed.json');
    }

    public function testInfectionRunnerPrefersProjectVendorBinary(): void
    {
        $projectRoot = $this->tempDir();
        $binaryPath = $projectRoot . '/vendor/bin/infection';

        try {
            self::assertTrue(mkdir(dirname($binaryPath), 0777, true));
            self::assertNotFalse(file_put_contents($binaryPath, "#!/usr/bin/env sh\nprintf 'local-infection %s' \"$*\"\n"));
            self::assertTrue(chmod($binaryPath, 0755));

            $result = (new InfectionRunner())->run($projectRoot, 'infection', null);

            self::assertSame(0, $result->exitCode);
            self::assertStringContainsString('local-infection run --no-progress --log-verbosity=none', $result->output);
            self::assertNull($result->diagnostic);
        } finally {
            $this->removeDir($projectRoot);
        }
    }

    public function testInfectionRunnerUsesProjectInfectionConfigByDefault(): void
    {
        $projectRoot = $this->tempDir();
        $binaryPath = $projectRoot . '/vendor/bin/infection';

        try {
            self::assertTrue(mkdir(dirname($binaryPath), 0777, true));
            self::assertNotFalse(file_put_contents($binaryPath, "#!/usr/bin/env sh\nprintf 'local-infection %s' \"$*\"\n"));
            self::assertTrue(chmod($binaryPath, 0755));
            self::assertNotFalse(file_put_contents($projectRoot . '/infection.json5', "{}\n"));

            $result = (new InfectionRunner())->run($projectRoot, 'infection', null);

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

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-infection-runner-' . bin2hex(random_bytes(6));

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            self::fail(sprintf('Unable to create temp directory: %s', $path));
        }

        return $path;
    }

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
