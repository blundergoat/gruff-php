<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Baseline;

use GruffPhp\Results\Baseline\BaselineException;
use GruffPhp\Results\Baseline\BaselineStore;
use GruffPhp\Results\Finding\BaselineIdentity;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Covers baseline persistence under the v3 contract: identity rows with counts, sensitive counts without rows, loud rejection of
 * older layouts, the validator, and a migration that leaves the 0.5 original byte-identical.
 */
final class BaselineStoreTest extends TestCase
{
    /**
     * Verify write replaces the baseline atomically without lingering temp files.
     *
     * @return void
     */
    public function testWriteReplacesBaselineAtomicallyWithoutLingeringTempFiles(): void
    {
        $root = $this->tempDir();

        try {
            $store = new BaselineStore($root);
            $store->write('gruff-baseline.json', [$this->finding()]);
            $store->write('gruff-baseline.json', [$this->finding(), $this->finding(filePath: 'src/Zulu.php')]);

            $entries = scandir($root);
            self::assertIsArray($entries);
            self::assertSame(['.', '..', 'gruff-baseline.json'], $entries);
            self::assertCount(2, $store->read('gruff-baseline.json')->entries);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify write collapses same-identity findings into one counted row, sorts rows by identity, and stores nothing positional.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testWriteAggregatesByIdentityAndStoresNothingPositional(): void
    {
        $root = $this->tempDir();

        try {
            $secondLine   = 30;
            $baselineData = (new BaselineStore($root))->write('gruff-baseline.json', [
                $this->finding(filePath: 'src/Zulu.php'),
                $this->finding(),
                $this->finding(line: $secondLine),
            ]);

            self::assertCount(2, $baselineData->entries);
            self::assertSame(BaselineIdentity::TOOL_LANGUAGE, $baselineData->toolLanguage);

            $decoded = json_decode((string)file_get_contents($root . '/gruff-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame(BaselineStore::SCHEMA_VERSION, $decoded['schemaVersion'] ?? null);
            self::assertSame('php', $decoded['toolLanguage'] ?? null);

            $occurrences = $decoded['occurrences'] ?? null;
            self::assertIsArray($occurrences);
            self::assertCount(2, $occurrences);

            $identities = array_values(array_filter(array_column($occurrences, 'identity'), 'is_string'));
            $sorted     = $identities;
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $identities);

            // Two findings on one declaration at different lines are one row with the pair counted, and no row names a line.
            $occurrencesOnOneDeclaration = 2;
            $counts                      = array_column($occurrences, 'count', 'path');
            self::assertSame($occurrencesOnOneDeclaration, $counts['src/Example.php'] ?? null);
            self::assertSame(1, $counts['src/Zulu.php'] ?? null);
            self::assertStringNotContainsString('"line"', (string)file_get_contents($root . '/gruff-baseline.json'));
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify a written file carries no sensitive occurrence and nothing that could name one, only counts.
     *
     * @return void
     */
    public function testWriteCountsSensitiveFindingsInsteadOfStoringThem(): void
    {
        $root       = $this->tempDir();
        $secretText = 'synthetic-fixture-credential-body';

        try {
            $secret = new Finding(
                ruleId:     'sensitive-data.aws-access-key',
                message:    'Possible AWS access key ' . $secretText,
                filePath:   'config/app.env',
                line:       3,
                severity:   Severity::Error,
                pillar:     Pillar::SensitiveData,
                tier:       RuleTier::V01,
                confidence: Confidence::High,
            );

            $baselineData = (new BaselineStore($root))->write('gruff-baseline.json', [$this->finding(), $secret, $secret]);
            $written      = (string)file_get_contents($root . '/gruff-baseline.json');

            self::assertCount(1, $baselineData->entries);
            self::assertSame(['sensitive-data.aws-access-key' => 2], $baselineData->sensitiveByRule);
            self::assertStringNotContainsString($secretText, $written);
            self::assertStringNotContainsString('config/app.env', $written);
            self::assertStringContainsString('"eligible": false', $written);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify a 0.5 baseline fails closed with the migration command rather than being misread.
     *
     * @return void
     */
    public function testReadRejectsLegacyV2SchemaWithMigrationInstruction(): void
    {
        $store = new BaselineStore(__DIR__ . '/../Fixtures/Baseline');

        $this->expectException(BaselineException::class);
        $this->expectExceptionMessage('--migrate-baseline gruff-baseline-v2.json --generate-baseline');

        $store->read('gruff-baseline-v2.json');
    }

    /**
     * Verify the retired v1 layout still fails closed with a regenerate instruction.
     *
     * @return void
     */
    public function testReadRejectsRetiredV1SchemaWithRegenerateInstruction(): void
    {
        $store = new BaselineStore(__DIR__ . '/../Fixtures/Baseline');

        $this->expectException(BaselineException::class);
        $this->expectExceptionMessage('Baseline schema "gruff.baseline.v1" is no longer supported');

        $store->read('gruff-baseline-v1.json');
    }

    /**
     * Verify a v3 fixture parses into identity rows with counts and sensitive counts.
     *
     * @return void
     */
    public function testReadParsesV3FixtureRows(): void
    {
        $baselineData = (new BaselineStore(__DIR__ . '/../Fixtures/Baseline'))->read('gruff-baseline-v3.json');

        self::assertCount(2, $baselineData->entries);
        self::assertSame('php', $baselineData->toolLanguage);
        self::assertSame('3b0c1f2e4d5a6978', $baselineData->entries[0]->identity);
        self::assertSame(2, $baselineData->entries[0]->count);
        self::assertSame('docs.missing-public-phpdoc', $baselineData->entries[0]->ruleId);
        self::assertSame(1, $baselineData->entries[1]->count);
        self::assertSame(1, $baselineData->sensitiveTotal());
    }

    /**
     * Verify the validator rejects every way a hand-edited file could expire, leak, or reorder.
     *
     * @return void
     */
    public function testReadRejectsInvalidRows(): void
    {
        $root = $this->tempDir();

        try {
            $cases = [
                'a count below one'        => '{"identity":"0000000000000000","count":0}',
                'a forbidden line field'   => '{"identity":"0000000000000000","count":1,"line":10}',
                'a malformed identity'     => '{"identity":"not-hex","count":1}',
                'a duplicated identity'    => '{"identity":"0000000000000000","count":1},{"identity":"0000000000000000","count":1}',
                'identities out of order'  => '{"identity":"ffffffffffffffff","count":1},{"identity":"0000000000000000","count":1}',
            ];

            foreach ($cases as $label => $occurrences) {
                file_put_contents($root . '/gruff-baseline.json', $this->document($occurrences));

                try {
                    (new BaselineStore($root))->read('gruff-baseline.json');
                    self::fail(sprintf('read accepted %s', $label));
                } catch (BaselineException $exception) {
                    self::assertStringContainsString('Baseline occurrences[', $exception->getMessage(), $label);
                }
            }
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify migration refuses in-place targets, keeps the 0.5 input byte-identical, writes a valid v3 file, and translates no 0.5 digest.
     *
     * @return void
     */
    public function testMigrateCarriesReviewsIntoASeparateFileAndPreservesTheOriginal(): void
    {
        $root = $this->tempDir();

        try {
            $legacyBytes = $this->stageLegacyInput($root);
            $store       = new BaselineStore($root);

            $reviewed   = $this->finding(
                ruleId:   'docs.missing-public-phpdoc',
                message:  'Method calculateTotal needs a brief intent description above its declaration (one plain-English line; not a restatement of the method signature).',
                symbol:   'Example::calculateTotal()',
            );
            $unreviewed = $this->finding(ruleId: 'naming.generic-method', symbol: 'Example::handle()');

            try {
                $store->migrate('legacy.json', 'legacy.json', [$reviewed]);
                self::fail('a same-path migration was accepted');
            } catch (BaselineException $exception) {
                self::assertStringContainsString('same file', $exception->getMessage());
            }

            self::assertTrue(symlink($root . '/legacy.json', $root . '/link.json'));

            try {
                $store->migrate('legacy.json', 'link.json', [$reviewed]);
                self::fail('a symlinked migration target was accepted');
            } catch (BaselineException $exception) {
                self::assertStringContainsString('resolves to the input', $exception->getMessage());
            }

            $migration = $store->migrate('legacy.json', 'gruff-baseline.json', [$reviewed, $unreviewed]);

            self::assertSame(1, $migration->accepted);
            self::assertCount(1, $migration->writtenBaseline->entries);
            $this->assertLegacyInputUnchanged($root, $legacyBytes);
            self::assertSame('docs.missing-public-phpdoc', $store->read('gruff-baseline.json')->entries[0]->ruleId);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify a written baseline carries no sentinel, raw, partial, hashed or encoded.
     *
     * A file a team commits and shares must not leak the secret it was counting, in any derived form.
     *
     * @return void
     */
    public function testAWrittenBaselineCarriesNoSentinel(): void
    {
        $root = $this->tempDir();

        try {
            // A synthetic AWS-shaped literal, not a live credential; it exists to be searched for.
            $sentinel = 'AKIA' . 'IOSFODNN7EXAMPLE';
            $secret   = $this->finding(
                ruleId:   'sensitive-data.aws-access-key',
                filePath: 'src/Config.php',
                message:  sprintf('possible AWS access key %s in a literal', $sentinel),
                pillar:   Pillar::SensitiveData,
            );

            (new BaselineStore($root))->write('gruff-baseline.json', [$secret]);
            $written = (string)file_get_contents($root . '/gruff-baseline.json');

            foreach ($this->sentinelForms($sentinel) as $name => $form) {
                self::assertStringNotContainsString($form, $written, sprintf('the written baseline carries the %s form of the sentinel', $name));
            }

            // What it does carry is a count, which is what makes the secret auditable without naming it.
            self::assertStringContainsString('"sensitive-data.aws-access-key": 1', $written);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Return every shape a leaked secret could take in an artifact, so a derived value is caught as well as a raw one.
     *
     * @param string $sentinel - The synthetic literal the test searches for.
     *
     * @return array<string, string> - Form name to the text that must not appear.
     */
    private function sentinelForms(string $sentinel): array
    {
        return [
            'raw'     => $sentinel,
            'partial' => substr($sentinel, 0, 8),
            'hashed'  => hash('sha256', $sentinel),
            'encoded' => base64_encode($sentinel),
        ];
    }

    /**
     * Verify a generate at the default path keeps the retreat copy unless the user forces it.
     *
     * @return void
     */
    public function testDefaultPathProtectionKeepsTheRetreatCopy(): void
    {
        $root = $this->tempDir();

        try {
            $store       = new BaselineStore($root);
            $legacyBytes = (string)json_encode(['schemaVersion' => 'gruff.baseline.v2', 'groups' => []], JSON_THROW_ON_ERROR);

            // An empty project has nothing to protect.
            $store->requireOverwritableDefaultPath('gruff-baseline.json', false);

            file_put_contents($root . '/gruff-baseline.json', $legacyBytes);

            try {
                $store->requireOverwritableDefaultPath('gruff-baseline.json', false);
                self::fail('a generate over a 0.5 baseline must be refused');
            } catch (BaselineException $exception) {
                self::assertStringContainsString('--force', $exception->getMessage());
            }

            // The refusal is not a write: the retreat copy is exactly as the user left it.
            self::assertSame($legacyBytes, file_get_contents($root . '/gruff-baseline.json'));
            // The destructive case stays available and stays explicit.
            $store->requireOverwritableDefaultPath('gruff-baseline.json', true);

            // Regenerating v3 over v3 is not destructive, because v3 is what the tool now reads.
            $store->write('gruff-baseline.json', [$this->finding()]);
            $store->requireOverwritableDefaultPath('gruff-baseline.json', false);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify a 0.5 input naming two row containers is refused, so no two ports read one file differently.
     *
     * @return void
     */
    public function testMigrationRefusesAnAmbiguousInput(): void
    {
        $root = $this->tempDir();

        try {
            $ambiguous = json_encode(
                ['schemaVersion' => 'gruff.baseline.v2', 'groups' => [], 'entries' => []],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            );
            file_put_contents($root . '/legacy.json', $ambiguous);

            try {
                (new BaselineStore($root))->migrate('legacy.json', 'migrated.json', []);
                self::fail('an ambiguous 0.5 input must be refused');
            } catch (BaselineException $exception) {
                self::assertStringContainsString('more than one row container', $exception->getMessage());
            }

            self::assertSame($ambiguous, file_get_contents($root . '/legacy.json'), 'a refused migration changed its input');
            // A refused migration writes nothing, so the user is not left with a half-migrated second file.
            self::assertFileDoesNotExist($root . '/migrated.json');
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify an output hard-linked to the input is refused, because it is the same inode under another name.
     *
     * @return void
     */
    public function testMigrationRefusesAHardLinkedOutput(): void
    {
        $root = $this->tempDir();

        try {
            $legacyBytes = $this->stageLegacyInput($root);

            self::assertTrue(link($root . '/legacy.json', $root . '/hard-link.json'), 'the fixture needs a hard link');

            try {
                (new BaselineStore($root))->migrate('legacy.json', 'hard-link.json', []);
                self::fail('a hard-linked output must be refused');
            } catch (BaselineException $exception) {
                self::assertStringContainsString('resolves to the input path', $exception->getMessage());
            }

            $this->assertLegacyInputUnchanged($root, $legacyBytes);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Copy the committed 0.5 fixture into the temp project as the migration input, and hand back its exact bytes.
     *
     * @param string $root - Temp project root.
     *
     * @return string - Bytes of the staged input, for the byte-for-byte comparison after the migration.
     */
    private function stageLegacyInput(string $root): string
    {
        $legacyBytes = (string)file_get_contents(__DIR__ . '/../Fixtures/Baseline/gruff-baseline-v2.json');
        file_put_contents($root . '/legacy.json', $legacyBytes);

        return $legacyBytes;
    }

    /**
     * Prove the 0.5 input survived the migration byte for byte, which is the user's retreat path.
     *
     * @param string $root - Temp project root.
     * @param string $legacyBytes - Bytes staged before the migration.
     *
     * @return void
     */
    private function assertLegacyInputUnchanged(string $root, string $legacyBytes): void
    {
        $afterMigration = (string)file_get_contents($root . '/legacy.json');

        self::assertSame($legacyBytes, $afterMigration, 'the migration changed the 0.5 input');
    }

    /**
     * Wrap one or more occurrence objects in an otherwise valid v3 document.
     *
     * @param string $occurrences - JSON occurrence objects, comma separated.
     *
     * @return string - Complete v3 document text.
     */
    private function document(string $occurrences): string
    {
        return '{"schemaVersion":"gruff.baseline.v3","toolLanguage":"php","generatedAt":"2026-09-05T00:00:00+00:00","occurrences":['
            . $occurrences
            . '],"sensitive":{"eligible":false,"reason":"r","counts":{"total":0,"byRule":{}}}}';
    }

    /**
     * Build a finding fixture for assertions.
     *
     * @param string      $filePath - Display path recorded for the finding; varied to prove row ordering.
     * @param int         $line - Source line; varied to prove same-identity findings on different lines aggregate.
     * @param string      $ruleId - Rule id, part of the identity.
     * @param string      $message - Message; only part of the identity when no symbol is named.
     * @param string|null $symbol - Symbol the finding is anchored to; null for a file-level finding.
     * @param Pillar      $pillar - Pillar the finding belongs to; pass SensitiveData to build a secret the store must not store.
     *
     * @return Finding - One advisory finding the store round-trips through these tests, documentation unless a pillar is named.
     */
    private function finding(
        string $filePath = 'src/Example.php',
        int $line = 12,
        string $ruleId = 'docs.example',
        string $message = 'Example finding.',
        ?string $symbol = 'Example::process()',
        Pillar $pillar = Pillar::Documentation,
    ): Finding {
        return new Finding(
            ruleId:     $ruleId,
            message:    $message,
            filePath:   $filePath,
            line:       $line,
            severity:   Severity::Advisory,
            pillar:     $pillar,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
            symbol:     $symbol,
        );
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string - Absolute path to a freshly created unique temp dir for the caller to populate and tear down.
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-baseline-test-' . bin2hex(random_bytes(6));

        mkdir($path);
        self::assertDirectoryExists($path);

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
