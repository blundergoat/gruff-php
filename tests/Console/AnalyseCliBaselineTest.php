<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers analyse command trend history and baseline workflows.
 */
final class AnalyseCliBaselineTest extends CliTestCase
{
    /**
     * Verify analyse command writes trend history file.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandWritesTrendHistoryFile(): void
    {
        $tempDir     = $this->tempDir();
        $historyPath = $tempDir . '/history/gruff-history.json';

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--history-file',
                $historyPath,
            ], __DIR__ . '/../..');
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertFileExists($historyPath);

            $report = $this->decodeJsonOutput($process);
            self::assertIsArray($report['trend'] ?? null);

            $decodedHistory = json_decode((string) file_get_contents($historyPath), true, 512, JSON_THROW_ON_ERROR);

            self::assertIsArray($decodedHistory);
            self::assertCount(1, $decodedHistory);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify analyse command generates and applies baseline.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandGeneratesAndAppliesBaseline(): void
    {
        $tempDir      = $this->tempDir();
        $baselinePath = $tempDir . '/gruff-baseline.json';

        try {
            $generateProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
                $baselinePath,
                '--no-config',
            ], __DIR__ . '/../..');
            $generateProcess->run();

            self::assertSame(0, $generateProcess->getExitCode(), $generateProcess->getErrorOutput());
            self::assertFileExists($baselinePath);

            $generatedReport   = $this->decodeJsonOutput($generateProcess);
            $generatedBaseline = $generatedReport['baseline'] ?? null;
            self::assertIsArray($generatedBaseline);
            self::assertSame(true, $generatedBaseline['generated'] ?? null);
            self::assertSame(1, $generatedBaseline['totalEntries'] ?? null);

            $applyProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--baseline',
                $baselinePath,
                '--no-config',
            ], __DIR__ . '/../..');
            $applyProcess->run();

            self::assertSame(0, $applyProcess->getExitCode(), $applyProcess->getErrorOutput());
            $appliedReport   = $this->decodeJsonOutput($applyProcess);
            $appliedBaseline = $appliedReport['baseline'] ?? null;
            $summary         = $appliedReport['summary'] ?? null;
            self::assertIsArray($appliedBaseline);
            self::assertIsArray($summary);
            self::assertSame(1, $appliedBaseline['suppressedFindings'] ?? null);
            $findingCounts = $summary['findings'] ?? null;
            self::assertIsArray($findingCounts);
            self::assertSame(0, $findingCounts['total'] ?? null);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify analyse command rejects invalid baseline JSON.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandRejectsInvalidBaselineJson(): void
    {
        $tempDir      = $this->tempDir();
        $baselinePath = $tempDir . '/broken-baseline.json';

        try {
            file_put_contents($baselinePath, '{"schemaVersion":"wrong","findings":[]}');

            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--fail-on',
                'none',
                '--baseline',
                $baselinePath,
            ], __DIR__ . '/../..');
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('[BASELINE-ERROR]', $process->getOutput());
            self::assertStringContainsString('Baseline schemaVersion must be "gruff.baseline.v1".', $process->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify analyse command writes and auto applies default baseline file.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandWritesAndAutoAppliesDefaultBaselineFile(): void
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
            self::assertFileExists($project . '/gruff-baseline.json');

            $generatedReport   = $this->decodeJsonOutput($generateProcess);
            $generatedBaseline = $generatedReport['baseline'] ?? null;
            self::assertIsArray($generatedBaseline);
            self::assertSame('gruff-baseline.json', $generatedBaseline['path'] ?? null);
            self::assertSame(true, $generatedBaseline['generated'] ?? null);
            self::assertSame(1, $generatedBaseline['totalEntries'] ?? null);
            self::assertSame('default', $generatedBaseline['source'] ?? null);

            $autoApplyProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $autoApplyProcess->run();

            self::assertSame(0, $autoApplyProcess->getExitCode(), $autoApplyProcess->getErrorOutput());
            $autoReport   = $this->decodeJsonOutput($autoApplyProcess);
            $autoBaseline = $autoReport['baseline'] ?? null;
            self::assertIsArray($autoBaseline);
            self::assertSame('gruff-baseline.json', $autoBaseline['path'] ?? null);
            self::assertSame(false, $autoBaseline['generated'] ?? null);
            self::assertSame(1, $autoBaseline['suppressedFindings'] ?? null);
            self::assertSame('default', $autoBaseline['source'] ?? null);
            $autoSummary = $autoReport['summary'] ?? null;
            self::assertIsArray($autoSummary);
            $autoCounts = $autoSummary['findings'] ?? null;
            self::assertIsArray($autoCounts);
            self::assertSame(0, $autoCounts['total'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command skips auto baseline with no baseline flag.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandSkipsAutoBaselineWithNoBaselineFlag(): void
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

            $skippedProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--no-baseline',
            ], $project);
            $skippedProcess->run();

            self::assertSame(0, $skippedProcess->getExitCode(), $skippedProcess->getErrorOutput());
            $report = $this->decodeJsonOutput($skippedProcess);
            self::assertArrayNotHasKey('baseline', $report);
            $skippedSummary = $report['summary'] ?? null;
            self::assertIsArray($skippedSummary);
            $skippedCounts = $skippedSummary['findings'] ?? null;
            self::assertIsArray($skippedCounts);
            self::assertGreaterThan(0, $skippedCounts['total'] ?? 0);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command shows new findings after baseline generation.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandShowsNewFindingsAfterBaselineGeneration(): void
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

            file_put_contents(
                $project . '/src/Newcomer.php',
                "<?php\n\ndeclare(strict_types=1);\n\nfinal readonly class Newcomer\n{\n    public function arrive(int \$x): int\n    {\n        return \$x;\n    }\n}\n",
            );

            $rerunProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $rerunProcess->run();

            self::assertSame(0, $rerunProcess->getExitCode(), $rerunProcess->getErrorOutput());
            $report   = $this->decodeJsonOutput($rerunProcess);
            $baseline = $report['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame(1, $baseline['suppressedFindings'] ?? null);

            $findings = $report['findings'] ?? null;
            self::assertIsArray($findings);
            $newFiles = array_values(array_filter(
                $findings,
                static fn (mixed $finding): bool => is_array($finding)
                    && ($finding['file'] ?? null) === 'src/Newcomer.php',
            ));
            self::assertNotSame([], $newFiles, 'A new finding introduced after baseline generation must still be reported.');
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command reports stale baseline entries.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandReportsStaleBaselineEntries(): void
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

            file_put_contents(
                $project . '/src/OrderCalculator.php',
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixtures\\Source\\Code;\n\n/**\n * Documents the public surface so the docs.missing-public-phpdoc finding goes away.\n */\nfinal readonly class OrderCalculator\n{\n    /**\n     * Sum the subtotal and tax to produce the order total.\n     */\n    public function calculateTotal(int \$subtotal, int \$taxAmount): int\n    {\n        return \$subtotal + \$taxAmount;\n    }\n}\n",
            );

            $rerunProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $rerunProcess->run();

            self::assertSame(0, $rerunProcess->getExitCode(), $rerunProcess->getErrorOutput());
            $report   = $this->decodeJsonOutput($rerunProcess);
            $baseline = $report['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame('full-project', $baseline['staleEvaluation'] ?? null);
            self::assertSame(1, $baseline['staleEntries'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command rejects no baseline combined with baseline.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandRejectsNoBaselineCombinedWithBaseline(): void
    {
        $project = $this->createBaselineProject();

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'text',
                '--fail-on',
                'none',
                '--no-baseline',
                '--baseline=gruff-baseline.json',
            ], $project);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('--no-baseline cannot be combined with --baseline.', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command rejects baseline combined with generate baseline.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandRejectsBaselineCombinedWithGenerateBaseline(): void
    {
        $project = $this->createBaselineProject();

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'text',
                '--fail-on',
                'none',
                '--baseline=gruff-baseline.json',
                '--generate-baseline',
            ], $project);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('--baseline and --generate-baseline are mutually exclusive.', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }
}
