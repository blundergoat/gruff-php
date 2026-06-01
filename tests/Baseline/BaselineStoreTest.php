<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Baseline;

use GruffPhp\Baseline\BaselineStore;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Covers atomic baseline file replacement without leaving temp-file residue on disk.
 */
final class BaselineStoreTest extends TestCase
{
    /**
     * Verify write replaces baseline atomically without lingering temp files.
     *
     * @return void
     */
    public function testWriteReplacesBaselineAtomicallyWithoutLingeringTempFiles(): void
    {
        $root = $this->tempDir();

        try {
            $baselineStore = new BaselineStore($root);
            $baselineData  = $baselineStore->write('baselines/gruff-baseline.json', [$this->finding()]);
            $baselineData  = $baselineStore->write('baselines/gruff-baseline.json', [$this->finding()]);

            self::assertCount(1, $baselineData->entries);
            self::assertFileExists($root . '/baselines/gruff-baseline.json');
            self::assertSame([], glob($root . '/baselines/gruff-baseline-*') ?: []);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Build a finding fixture for assertions.
     *
     * @return Finding - one fixed advisory documentation finding the store round-trips through these tests
     */
    private function finding(): Finding
    {
        // A single fixed finding the store round-trips through in these tests.
        return new Finding(
            ruleId:     'docs.example',
            message:    'Example finding.',
            filePath:   'src/Example.php',
            line:       12,
            severity:   Severity::Advisory,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string - absolute path to a freshly created unique temp dir for the caller to populate and tear down
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-baseline-test-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path - Filesystem path.
     *
     * @return void
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            // Nothing to clean when setup never created the dir; keep teardown idempotent.
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $directoryEntry) {
            if ($directoryEntry === '.' || $directoryEntry === '..') {
                continue;
            }

            $child = $path . '/' . $directoryEntry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
