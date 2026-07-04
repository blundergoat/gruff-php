<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Baseline;

use GruffPhp\Results\Baseline\BaselineException;
use GruffPhp\Results\Baseline\BaselineStore;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Covers baseline persistence: atomic replacement, v2 group aggregation, and legacy schema rejection.
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
     * Verify write aggregates same-identity findings into sorted count rows without per-finding fields.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testWriteAggregatesGroupsAndSortsRowsDeterministically(): void
    {
        $root = $this->tempDir();

        try {
            $secondLine = 30;
            // Unsorted input across two files; the duplicate-identity pair must collapse to one row with count 2.
            $baselineData = (new BaselineStore($root))->write('gruff-baseline.json', [
                $this->finding(filePath: 'src/Zulu.php'),
                $this->finding(),
                $this->finding(line: $secondLine),
            ]);

            self::assertCount(2, $baselineData->entries);

            $decoded = json_decode((string)file_get_contents($root . '/gruff-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame(BaselineStore::SCHEMA_VERSION, $decoded['schemaVersion'] ?? null);
            $groups = $decoded['groups'] ?? null;
            self::assertIsArray($groups);
            self::assertSame(
                [
                    ['file' => 'src/Example.php', 'ruleId' => 'docs.example', 'message' => 'Example finding.', 'count' => 2],
                    ['file' => 'src/Zulu.php', 'ruleId' => 'docs.example', 'message' => 'Example finding.', 'count' => 1],
                ],
                $groups,
            );
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify a legacy v1 baseline fails closed with a regenerate instruction.
     *
     * @return void
     */
    public function testReadRejectsLegacyV1SchemaWithRegenerateInstruction(): void
    {
        $store = new BaselineStore(__DIR__ . '/../Fixtures/Baseline');

        $this->expectException(BaselineException::class);
        $this->expectExceptionMessage('Baseline schema "gruff.baseline.v1" is no longer supported');

        $store->read('gruff-baseline-v1.json');
    }

    /**
     * Verify a v2 fixture parses into group entries with counts.
     *
     * @return void
     */
    public function testReadParsesV2FixtureGroups(): void
    {
        $baselineData = (new BaselineStore(__DIR__ . '/../Fixtures/Baseline'))->read('gruff-baseline-v2.json');

        self::assertCount(2, $baselineData->entries);
        self::assertSame('src/Example.php', $baselineData->entries[0]->filePath);
        self::assertSame('docs.missing-public-phpdoc', $baselineData->entries[0]->ruleId);
        self::assertSame(2, $baselineData->entries[0]->count);
        self::assertSame(1, $baselineData->entries[1]->count);
    }

    /**
     * Verify a group row with a count below one is rejected.
     *
     * @return void
     */
    public function testReadRejectsGroupCountBelowOne(): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents(
                $root . '/gruff-baseline.json',
                '{"schemaVersion":"gruff.baseline.v2","groups":[{"file":"src/A.php","ruleId":"docs.example","message":"Example finding.","count":0}]}',
            );

            $this->expectException(BaselineException::class);
            $this->expectExceptionMessage('Baseline group 0 field "count" must be an integer of at least 1.');

            (new BaselineStore($root))->read('gruff-baseline.json');
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Build a finding fixture for assertions.
     *
     * @param string $filePath - Display path recorded for the finding; varied to prove row ordering.
     * @param int    $line - Source line; varied to prove same-identity findings on different lines aggregate.
     *
     * @return Finding - one advisory documentation finding the store round-trips through these tests
     */
    private function finding(string $filePath = 'src/Example.php', int $line = 12): Finding
    {
        return new Finding(
            ruleId:     'docs.example',
            message:    'Example finding.',
            filePath:   $filePath,
            line:       $line,
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
