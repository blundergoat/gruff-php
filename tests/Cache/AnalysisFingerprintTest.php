<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Cache;

use FilesystemIterator;
use GruffPhp\Engine\Cache\AnalysisFingerprint;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Rules\RuleRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Covers the one input the run digest gained in 0.6.0: the analyser's own source bytes.
 *
 * A version string cannot see a rule that changed between two releases, so a source
 * checkout kept serving findings an older rule had written and `analyse` disagreed
 * with `summary`. These tests point the fingerprint at a throwaway source tree and
 * prove that editing, adding or renaming a source moves every file's cache key, while
 * an untouched tree keeps it.
 */
final class AnalysisFingerprintTest extends TestCase
{
    /**
     * Throwaway directory standing in for the analyser's source tree.
     */
    private string $implementationRoot = '';

    /**
     * Build a two-file source tree the fingerprint can digest.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->implementationRoot = sys_get_temp_dir() . '/gruff-fingerprint-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->implementationRoot . '/Rules', 0777, true));
        $this->writeSource('Rules/DemoRule.php', "<?php\n// reports nothing yet\n");
        $this->writeSource('Helper.php', "<?php\n// shared helper\n");
    }

    /**
     * Remove the throwaway source tree.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Removed by walking the tree, so a test that writes one more file cannot leave a directory behind.
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->implementationRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
        rmdir($this->implementationRoot);
    }

    /**
     * Verify an untouched source tree gives the same key twice, so a warm cache still hits.
     *
     * @return void
     */
    public function testUnchangedSourcesKeepTheKey(): void
    {
        self::assertSame($this->fileKey(), $this->fileKey());
    }

    /**
     * Verify a rule whose bytes changed under one version string can no longer be served from the cache.
     *
     * @return void
     */
    public function testEditingARuleSourceMovesTheKey(): void
    {
        $before = $this->fileKey();
        $this->writeSource('Rules/DemoRule.php', "<?php\n// now reports a finding\n");

        self::assertNotSame($before, $this->fileKey());
    }

    /**
     * Verify a new source and a renamed source each move the key, because either can change what is reported.
     *
     * @return void
     */
    public function testAddingOrRenamingASourceMovesTheKey(): void
    {
        $before = $this->fileKey();
        $this->writeSource('Rules/SecondRule.php', "<?php\n// a second rule\n");
        $afterAdding = $this->fileKey();
        self::assertTrue(rename($this->implementationRoot . '/Rules/DemoRule.php', $this->implementationRoot . '/Rules/MovedRule.php'));

        self::assertNotSame($before, $afterAdding);
        self::assertNotSame($afterAdding, $this->fileKey());
    }

    /**
     * Verify a file that is not PHP source leaves the key alone, so a stray note never empties the cache.
     *
     * @return void
     */
    public function testANonSourceFileLeavesTheKeyAlone(): void
    {
        $before = $this->fileKey();
        $this->writeSource('notes.txt', "not source\n");

        self::assertSame($before, $this->fileKey());
    }

    /**
     * Compute one scanned file's cache key under the throwaway source tree.
     *
     * @return string - Hex cache key.
     */
    private function fileKey(): string
    {
        $registry = RuleRegistry::defaults();

        return AnalysisFingerprint::forRun($registry, AnalysisConfig::fromRegistry($registry), '0.0.0-test', $this->implementationRoot)
            ->forFile('src/Scanned.php', "<?php\n");
    }

    /**
     * Write one file into the throwaway source tree.
     *
     * @param string $name     - Path relative to the throwaway root.
     * @param string $contents - Bytes to write.
     *
     * @return void
     */
    private function writeSource(string $name, string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->implementationRoot . '/' . $name, $contents));
    }
}
