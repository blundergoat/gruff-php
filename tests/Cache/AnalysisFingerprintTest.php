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
 * Checks how analyser source changes affect a scanned file's cache key.
 * Uses a temporary source tree with a fixed tool version to isolate source-dependent invalidation.
 * Covers unchanged sources, edited PHP bytes, added or renamed PHP paths, and an ignored text file.
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
     * @return void - Creates the source directory and writes two PHP fixtures.
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
     * @return void - Deletes fixture files and directories, then removes the temporary root.
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
     * Verify consecutive key computations agree when the source tree is unchanged.
     *
     * @return void - Asserts equality of two keys for the same scanned path, bytes, and analyser sources.
     */
    public function testUnchangedSourcesKeepTheKey(): void
    {
        self::assertSame($this->fileKey(), $this->fileKey());
    }

    /**
     * Verify changing a PHP source file changes the key while the tool version stays fixed.
     *
     * @return void - Asserts different keys before and after replacing the rule fixture's bytes.
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
     * @return void - Asserts key changes after adding a PHP file and again after renaming an existing one.
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
     * Verify adding notes.txt leaves the computed key unchanged.
     *
     * @return void - Asserts equal keys before and after adding the text fixture.
     */
    public function testANonSourceFileLeavesTheKeyAlone(): void
    {
        $before = $this->fileKey();
        $this->writeSource('notes.txt', "not source\n");

        self::assertSame($before, $this->fileKey());
    }

    /**
     * Compute one scanned file's cache key using the throwaway analyser source tree.
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
     * @return void - Asserts that the fixture write reports success.
     */
    private function writeSource(string $name, string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->implementationRoot . '/' . $name, $contents));
    }
}
