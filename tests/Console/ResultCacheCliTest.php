<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Covers the on-disk result cache: a warm run is byte-identical to a cold run,
 * --no-cache reproduces the same output, editing a file invalidates its entry,
 * and runs with project rules bypass the cache entirely.
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
     * @return void
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
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->project);
    }

    /**
     * Verify a warm run reproduces a cold run byte-for-byte and populates the cache.
     *
     * @return void
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
     * Verify a structurally invalid cache entry fails open to a cold scan.
     *
     * @return void
     */
    public function testInvalidCacheFindingRowsAreTreatedAsMisses(): void
    {
        $this->writeInvalidCacheEntries();

        $warm = $this->runScan();

        self::assertSame(0, $warm->getExitCode(), $warm->getErrorOutput());
        self::assertCount(1, $this->decodeFindings($warm));
    }

    /**
     * Verify --no-cache produces output identical to a cached run.
     *
     * @return void
     */
    public function testNoCacheMatchesCachedOutput(): void
    {
        $cached  = $this->runScan();
        $noCache = $this->runScan(['--no-cache']);

        self::assertSame($cached->getOutput(), $noCache->getOutput());
    }

    /**
     * Verify editing a file invalidates only its cache entry (no stale serve).
     *
     * @return void
     */
    public function testEditingAFileInvalidatesItsEntry(): void
    {
        $before = $this->decodeFindings($this->runScan());
        self::assertCount(1, $before);

        // Introduce a second security finding in the previously clean file.
        $this->writeProjectFile('src/Clean.php', $this->cleanSource(true));
        $after = $this->decodeFindings($this->runScan());

        self::assertCount(2, $after, 'A content change must invalidate the cached entry rather than serve stale findings.');
    }

    /**
     * Verify a default no-config run writes the per-file cache now that no registered rule needs project context.
     *
     * @return void
     */
    public function testDefaultRunWritesCacheWithNoProjectRulesRegistered(): void
    {
        // No --profile: since the project rules were retired the default rule set has no
        // ProjectRuleInterface implementor, so the cache guard passes and entries are written.
        $process = new Process(
            [PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'analyse', 'src', '--no-config', '--fail-on', 'none', '--format', 'json'],
            $this->project,
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertDirectoryExists($this->project . '/.gruff-cache');
    }

    /**
     * Prime the cache and replace each cache entry with an invalid finding row.
     *
     * @return void
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
     * Run a cache-eligible security-profile scan, returning the completed process.
     *
     * @param list<string> $extraArgs - Additional CLI arguments.
     *
     * @return Process - Completed analyse process.
     */
    private function runScan(array $extraArgs = []): Process
    {
        $process = new Process(
            array_merge(
                [PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'analyse', 'src', '--no-config', '--profile', 'security', '--fail-on', 'none', '--format', 'json'],
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
     * @return list<mixed> - Findings list.
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
     * @return string - PHP source.
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
     * @return string - PHP source.
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
     * @param string $path - Project-relative file path.
     * @param string $contents - File contents.
     *
     * @return void
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
