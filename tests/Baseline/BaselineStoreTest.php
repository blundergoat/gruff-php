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
 * Covers BaselineStoreTest behavior.
 */
final class BaselineStoreTest extends TestCase
{
    /**
     * Verify write replaces baseline atomically without lingering temp files.
     *
     * @return void No return value.
     */
    public function testWriteReplacesBaselineAtomicallyWithoutLingeringTempFiles(): void
    {
        $root = $this->tempDir();

        try {
            $store = new BaselineStore($root);
            $baselineData = $store->write('baselines/gruff-baseline.json', [$this->finding()]);

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
     * @return Finding Fixture value.
     */
    private function finding(): Finding
    {
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
     * @return string Fixture value.
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
     * @param string $path Filesystem path.
     * @return void No return value.
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
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
