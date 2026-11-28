<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Covers the on-disk result cache through repeated scans and --no-cache output comparisons.
 * Checks cache-directory creation and finding counts after corrupt entries and a file edit.
 * Also compares bounded and unbounded scan reports when the deep-scan budget changes.
 */
final class ResultCacheCliTest extends CliTestCase
{
    /**
     * Project root of the throwaway cache fixture created per test.
     */
    private string $project = '';

    /**
     * Build a temporary project with one finding-bearing file and one clean file.
     *
     * @return void - Creates the temporary project and writes its README and two PHP fixtures.
     */
    protected function setUp(): void
    {
        $this->project = $this->tempDir();
        $this->writeProjectFile('README.md', "Cache fixture.\n");
        $this->writeProjectFile('src/Danger.php', $this->dangerSource());
        $this->writeProjectFile('src/Clean.php', $this->cleanSource(false));
    }

    /**
     * Remove the temporary fixture.
     *
     * @return void - Removes the per-test project tree when it exists.
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->project);
    }

    /**
     * Verify the first scan creates the cache directory and the next scan emits identical stdout.
     *
     * @return void - Asserts first-run success, cache-directory existence and byte-identical scan stdout.
     */
    public function testWarmRunIsByteIdenticalToCold(): void
    {
        $cold = $this->runScan();
        self::assertSame(0, $cold->getExitCode(), $cold->getErrorOutput());
        self::assertDirectoryExists($this->project . '/.gruff-cache');

        $warm = $this->runScan();

        self::assertSame($cold->getOutput(), $warm->getOutput());
    }

    /**
     * Verify scanning after cache rows are corrupted succeeds and reports one finding.
     *
     * @return void - Asserts exit code zero and one finding after corrupting the cached finding rows.
     */
    public function testInvalidCacheFindingRowsAreTreatedAsMisses(): void
    {
        $this->writeInvalidCacheEntries();

        $warm = $this->runScan();

        self::assertSame(0, $warm->getExitCode(), $warm->getErrorOutput());
        self::assertCount(1, $this->decodeFindings($warm));
    }

    /**
     * Verify --no-cache preserves stdout from a scan with caching enabled.
     *
     * @return void - Asserts byte-identical stdout with caching enabled and with --no-cache.
     */
    public function testNoCacheMatchesCachedOutput(): void
    {
        $cached  = $this->runScan();
        $noCache = $this->runScan(['--no-cache']);

        self::assertSame($cached->getOutput(), $noCache->getOutput());
    }

    /**
     * Verify adding an eval call to the clean file increases the reported finding count from one to two.
     *
     * @return void - Asserts one finding before the edit and two afterward.
     */
    public function testEditingAFileInvalidatesItsEntry(): void
    {
        $before = $this->decodeFindings($this->runScan());
        self::assertCount(1, $before);


        $this->writeProjectFile('src/Clean.php', $this->cleanSource(true));
        $after = $this->decodeFindings($this->runScan());

        self::assertCount(2, $after, 'A content change must invalidate the cached entry rather than serve stale findings.');
    }

    /**
     * Verify an unprofiled scan with the explicit rule configuration creates the cache directory.
     *
     * @return void - Asserts successful execution and cache-directory existence with the explicit configuration.
     */
    public function testDefaultRunWritesCacheWithNoProjectRulesRegistered(): void
    {
        // Omit --profile to exercise cache-directory creation beyond the security-only scans.

        $process = new Process(
            [PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'analyse', 'src', '--config', self::PROJECT_ROOT . '/tests/Fixtures/Config/enable-default-off-rules.yaml', '--fail-on', 'none', '--format', 'json'],
            $this->project,
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertDirectoryExists($this->project . '/.gruff-cache');
    }

    /**
     * Verify repeated bounded scans emit identical stdout with a bounded-scan diagnostic.
     * Disabling the budget must remove diagnostics and report the dangerous eval call.
     *
     * @return void - Asserts bounded output stability and an unbounded report with no diagnostics and the dangerous-call finding.
     */
    public function testDeepScanBudgetParticipatesInCacheIdentity(): void
    {
        $project = self::PROJECT_ROOT . '/tests/runtime-gruff-cache-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($project . '/src', 0777, true));
        self::assertNotFalse(file_put_contents($project . '/README.md', "Cache budget fixture.\n"));
        self::assertNotFalse(file_put_contents(
            $project . '/src/Danger.php',
            "<?php\n\$key = 'AKIAQ7R2M8N4P6T9V1X3';\neval(\$_GET['code']);\n",
        ));

        try {
            $boundedArguments = [
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                '--config', self::PROJECT_ROOT . '/tests/Fixtures/Config/enable-default-off-rules.yaml',
                '--profile',
                'security',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--deep-scan-budget',
                '1:1',
                'src/Danger.php',
            ];
            $cold = new Process($boundedArguments, $project);
            $cold->run();
            $warm = new Process($boundedArguments, $project);
            $warm->run();

            self::assertSame(0, $cold->getExitCode(), $cold->getErrorOutput());
            self::assertSame($cold->getOutput(), $warm->getOutput());
            $boundedReport      = $this->decodeJsonOutput($warm);
            $boundedDiagnostics = $boundedReport['diagnostics'] ?? null;
            self::assertIsArray($boundedDiagnostics);
            $boundedDiagnostic = $boundedDiagnostics[0] ?? null;
            self::assertIsArray($boundedDiagnostic);
            self::assertSame('bounded-deep-scan', $boundedDiagnostic['type'] ?? null);

            $unbounded = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                '--config', self::PROJECT_ROOT . '/tests/Fixtures/Config/enable-default-off-rules.yaml',
                '--profile',
                'security',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--deep-scan-budget',
                'off',
                'src/Danger.php',
            ], $project);
            $unbounded->run();
            $unboundedReport   = $this->decodeJsonOutput($unbounded);
            $unboundedFindings = $unboundedReport['findings'] ?? null;
            self::assertIsArray($unboundedFindings);
            $ruleIds = [];
            foreach ($unboundedFindings as $index => $finding) {
                self::assertIsArray($finding, sprintf('unbounded finding %s is not an array', (string)$index));
                $ruleIds[] = $finding['ruleId'] ?? null;
            }

            self::assertSame([], $unboundedReport['diagnostics'] ?? null);
            self::assertContains('security.dangerous-function-call', $ruleIds);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Prime the cache and replace each cache entry with an invalid finding row.
     *
     * @return void - Overwrites the non-empty set of JSON cache entries with structurally invalid findings.
     */
    private function writeInvalidCacheEntries(): void
    {
        $cold = $this->runScan();
        self::assertSame(0, $cold->getExitCode(), $cold->getErrorOutput());

        foreach ($this->cacheEntryPaths() as $entry) {
            file_put_contents($entry, '[{}]');
        }
    }

    /**
     * Return cache entry paths after a cache-producing run.
     *
     * @return list<string> - non-empty list of cache entry paths.
     */
    private function cacheEntryPaths(): array
    {
        $entries = glob($this->project . '/.gruff-cache/*.json');
        self::assertIsArray($entries);
        self::assertNotSame([], $entries);

        return $entries;
    }

    /**
     * Run a security-profile scan, returning the completed process.
     *
     * @param list<string> $extraArgs - Arguments appended to the fixed analyse command.
     *
     * @return Process - Completed analyse process.
     */
    private function runScan(array $extraArgs = []): Process
    {
        $process = new Process(
            array_merge(
                [PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'analyse', 'src', '--config', self::PROJECT_ROOT . '/tests/Fixtures/Config/enable-default-off-rules.yaml', '--profile', 'security', '--fail-on', 'none', '--format', 'json'],
                $extraArgs,
            ),
            $this->project,
        );
        $process->run();

        return $process;
    }

    /**
     * Decode the findings array from an analyse JSON report.
     *
     * @param Process $process - Completed analyse process whose stdout holds the JSON report.
     *
     * @return list<mixed> - Reindexed findings; empty for non-array reports or absent or null findings.
     */
    private function decodeFindings(Process $process): array
    {
        $decoded  = json_decode($process->getOutput(), true);
        $findings = is_array($decoded) ? ($decoded['findings'] ?? []) : [];
        self::assertIsArray($findings);

        // Re-index to a 0-based list so positional assertions stay stable regardless of source keys.
        return array_values($findings);
    }

    /**
     * Source for a file carrying one security finding (a dynamic eval call).
     *
     * @return string - Fixture source whose dynamic eval call supplies the initial security finding.
     */
    private function dangerSource(): string
    {
        return "<?php\n\nnamespace Demo;\n\n/**\n * Danger fixture with a security finding.\n */\nclass Danger\n{\n    /**\n     * Run dynamic code.\n     *\n     * @param string \$code Code to evaluate.\n     * @return mixed Evaluation result.\n     */\n    public function run(string \$code): mixed\n    {\n        return eval(\$code);\n    }\n}\n";
    }

    /**
     * Source for the second file, optionally carrying a security finding.
     *
     * @param bool $shouldIncludeFinding - Whether to include a dynamic eval call.
     *
     * @return string - Fixture source with an add() method and an optional dynamic eval call.
     */
    private function cleanSource(bool $shouldIncludeFinding): string
    {
        $method = $shouldIncludeFinding
            ? "\n    /**\n     * Run dynamic code.\n     *\n     * @param string \$code Code to evaluate.\n     * @return mixed Evaluation result.\n     */\n    public function run(string \$code): mixed\n    {\n        return eval(\$code);\n    }\n"
            : '';

        return "<?php\n\nnamespace Demo;\n\n/**\n * Clean fixture.\n */\nclass Clean\n{\n    /**\n     * Add one to the amount.\n     *\n     * @param int \$amount Amount to increment.\n     * @return int Incremented amount.\n     */\n    public function add(int \$amount): int\n    {\n        return \$amount + 1;\n    }\n{$method}}\n";
    }

    /**
     * Write a fixture file, creating parent directories as needed.
     *
     * @param string $path     - Project-relative file path.
     * @param string $contents - Complete replacement contents for the fixture file.
     *
     * @return void - Creates missing parent directories and writes or replaces the fixture file.
     */
    private function writeProjectFile(string $path, string $contents): void
    {
        $absolutePath = $this->project . '/' . $path;
        $directory    = dirname($absolutePath);

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }

        file_put_contents($absolutePath, $contents);
    }
}
