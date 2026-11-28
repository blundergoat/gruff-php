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
 * Exercises v3 baseline persistence with counted identities and separate sensitive counts.
 *
 * Checks selected validation failures, legacy-schema rejection, and migration into a separate file.
 * Checks that tested secret representations are absent and legacy input bytes are preserved.
 */
final class BaselineStoreTest extends TestCase
{
    /**
     * Verify two writes leave only the baseline file with two entries after the second write.
     *
     * @return void - Asserts the final directory listing and two entries read from the baseline file.
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
     * Verify duplicate identities share a counted row, rows are sorted, and no line field is written.
     *
     * @throws JsonException - If decoding the written baseline fails.
     *
     * @return void - Asserts identity aggregation, row ordering, schema metadata, and absence of line fields.
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
     * Verify sensitive findings contribute counts while the supplied secret text and path stay out of the file.
     *
     * @return void - Asserts one stored entry, two sensitive counts, ineligibility, and omission of the tested text and path.
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
     * Verify a mixed ordinary/separator-symbol batch writes one row without adding sensitive counts.
     *
     * @return void - Asserts one baseline entry and no sensitive counts after writing both findings.
     */
    public function testWriteLeavesOutASeparatorBearingSymbol(): void
    {
        $root = $this->tempDir();

        try {
            $baselineData = (new BaselineStore($root))->write('gruff-baseline.json', [
                $this->finding(),
                $this->finding(symbol: 'Example::process()#2'),
            ]);

            self::assertCount(1, $baselineData->entries);
            self::assertSame([], $baselineData->sensitiveByRule);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify a 0.5 baseline fails closed with the migration command rather than being misread.
     *
     * @return void - Expects BaselineException with the migration command for the v2 fixture.
     */
    public function testReadRejectsLegacyV2SchemaWithMigrationInstruction(): void
    {
        $store = new BaselineStore(__DIR__ . '/../Fixtures/Baseline');

        $this->expectException(BaselineException::class);
        $this->expectExceptionMessage('--migrate-baseline gruff-baseline-v2.json --generate-baseline');

        $store->read('gruff-baseline-v2.json');
    }

    /**
     * Verify the retired v1 fixture is rejected with an unsupported-schema error.
     *
     * @return void - Expects BaselineException identifying the unsupported v1 schema.
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
     * @return void - Asserts two entries, selected identity and rule fields, occurrence counts, language, and sensitive total.
     */
    public function testReadParsesV3FixtureRows(): void
    {
        $baselineData = (new BaselineStore(__DIR__ . '/../Fixtures/Baseline'))->read('gruff-baseline-v3.json');

        self::assertCount(2, $baselineData->entries);
        self::assertSame('php', $baselineData->toolLanguage);
        self::assertSame('3b0c1f2e4d5a6978', $baselineData->entries[0]->identity);
        self::assertSame(2, $baselineData->entries[0]->count);
        self::assertSame('docs.missing-phpdoc', $baselineData->entries[0]->ruleId);
        self::assertSame(1, $baselineData->entries[1]->count);
        self::assertSame(1, $baselineData->sensitiveTotal());
    }

    /**
     * Verify rejection of invalid counts, forbidden line fields, malformed or duplicate identities, and unsorted rows.
     *
     * @return void - Expects an occurrence-specific BaselineException for each listed invalid row case.
     */
    public function testReadRejectsInvalidRows(): void
    {
        $root = $this->tempDir();

        try {
            $cases = [
                'a count below one' => '{"identity":"0000000000000000","count":0}',
                'a forbidden line field' => '{"identity":"0000000000000000","count":1,"line":10}',
                'a malformed identity' => '{"identity":"not-hex","count":1}',
                'a duplicated identity' => '{"identity":"0000000000000000","count":1},{"identity":"0000000000000000","count":1}',
                'identities out of order' => '{"identity":"ffffffffffffffff","count":1},{"identity":"0000000000000000","count":1}',
            ];

            // Exercise each malformed reviewed occurrence through the same baseline read used by the CLI.
            foreach ($cases as $label => $occurrences) {
                file_put_contents($root . '/gruff-baseline.json', $this->document($occurrences));

                try {
                    (new BaselineStore($root))->read('gruff-baseline.json');
                    self::fail(sprintf('read accepted %s', $label));
                } catch (BaselineException $exception) {
                    // Check that the malformed occurrence names the right field for the caller to fix.
                    self::assertStringContainsString('Baseline occurrences[', $exception->getMessage(), $label);
                }
            }
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify same-path and symlink targets are rejected, the original bytes survive, and the legacy rule alias matches.
     *
     * @return void - Checks alias refusal, a two-occurrence migration budget, the current rule id, and preserved input.
     */
    public function testMigrateCarriesReviewsIntoASeparateFileAndPreservesTheOriginal(): void
    {
        $root = $this->tempDir();

        try {
            $legacyBytes = $this->stageLegacyInput($root);
            $store       = new BaselineStore($root);

            $reviewed = $this->finding(
                ruleId:  'docs.missing-phpdoc',
                message: 'Method calculateTotal needs a brief intent description above its declaration (one plain-English line; not a restatement of the method signature).',
                symbol:  'Example::calculateTotal()',
            );
            $unreviewed = $this->finding(ruleId: 'naming.generic-method', symbol: 'Example::handle()');
            $laterMatch = $this->finding(
                line:    20,
                ruleId:  $reviewed->ruleId,
                message: $reviewed->message,
                symbol:  'Example::other()',
            );
            $excessMatch = $this->finding(
                line:    30,
                ruleId:  $reviewed->ruleId,
                message: $reviewed->message,
                symbol:  'Example::unreviewed()',
            );

            try {
                $store->migrate('legacy.json', 'legacy.json', [$reviewed]);
                self::fail('a same-path migration was accepted');
            } catch (BaselineException $exception) {
                // A migration naming the same input and output must give an actionable refusal.
                self::assertStringContainsString('same file', $exception->getMessage());
            }

            self::assertTrue(symlink($root . '/legacy.json', $root . '/link.json'));

            try {
                $store->migrate('legacy.json', 'link.json', [$reviewed]);
                self::fail('a symlinked migration target was accepted');
            } catch (BaselineException $exception) {
                // A link to the migration input should be refused even when the paths look different.
                self::assertStringContainsString('resolves to the input', $exception->getMessage());
            }

            $migration = $store->migrate('legacy.json', 'gruff-baseline.json', [$excessMatch, $laterMatch, $reviewed, $unreviewed]);

            self::assertSame(2, $migration->accepted);
            self::assertCount(2, $migration->writtenBaseline->entries);
            self::assertNotContains('Example::unreviewed()#1', array_column($migration->writtenBaseline->entries, 'subject'));
            $this->assertLegacyInputUnchanged($root, $legacyBytes);
            self::assertSame('docs.missing-phpdoc', $store->read('gruff-baseline.json')->entries[0]->ruleId);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify the file omits the sentinel, its eight-byte prefix, SHA-256 digest, and Base64 encoding.
     * The sensitive rule count remains available without those tested representations.
     *
     * @return void - Asserts absence of the four tested representations and presence of the sensitive rule count.
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

            // Check each representation so redaction cannot hide the raw value in another common encoding.
            foreach ($this->sentinelForms($sentinel) as $name => $form) {
                self::assertStringNotContainsString($form, $written, sprintf('the written baseline carries the %s form of the sentinel', $name));
            }

            // What it does carry is the rule count without storing the matched text.
            self::assertStringContainsString('"sensitive-data.aws-access-key": 1', $written);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Return the sentinel, its first eight bytes, SHA-256 digest, and Base64 encoding for absence checks.
     *
     * @param string $sentinel - The synthetic literal the test searches for.
     *
     * @return array<string, string> - Form name to the text that must not appear.
     */
    private function sentinelForms(string $sentinel): array
    {
        return [
            'raw' => $sentinel,
            'partial' => substr($sentinel, 0, 8),
            'hashed' => hash('sha256', $sentinel),
            'encoded' => base64_encode($sentinel),
        ];
    }

    /**
     * Verify default-path checks preserve legacy bytes on refusal and allow missing paths, forced checks, and v3 files.
     *
     * @return void - Asserts the unforced legacy check throws with --force advice and leaves the file unchanged.
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
                // The overwrite refusal must name the flag a caller can use after reviewing the damaged file.
                self::assertStringContainsString('--force', $exception->getMessage());
            }


            self::assertSame($legacyBytes, file_get_contents($root . '/gruff-baseline.json'));

            $store->requireOverwritableDefaultPath('gruff-baseline.json', true);

            // A current-schema file needs no migration, so the guard permits it without force.
            $store->write('gruff-baseline.json', [$this->finding()]);
            $store->requireOverwritableDefaultPath('gruff-baseline.json', false);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify a v2 input with both groups and entries is rejected without changing the input or creating an output.
     *
     * @return void - Asserts the row-container error, unchanged input bytes, and absence of the destination file.
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
                // A baseline with two row containers must explain why it cannot be applied.
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
     * @return void - Expects the same-input-path error and verifies unchanged legacy bytes after hard-link rejection.
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
                // A destination linked to the original baseline must fail before either file changes.
                self::assertStringContainsString('resolves to the input path', $exception->getMessage());
            }

            $this->assertLegacyInputUnchanged($root, $legacyBytes);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify read() accepts valid SORT_STRING-ordered hex identities even when PHP would coerce them numerically out of order.
     *
     * @return void - Asserts both numeric-looking hex identities load in their stored bytewise order.
     */
    public function testReadAcceptsNumericStringHexIdentitiesInSortStringOrder(): void
    {
        $root = $this->tempDir();

        try {
            $json = $this->document(
                '{"identity":"1000000000000000","count":1},{"identity":"1e00000000000001","count":2}',
            );
            file_put_contents($root . '/gruff-baseline.json', $json);

            $loaded = (new BaselineStore($root))->read('gruff-baseline.json');

            self::assertCount(2, $loaded->entries);
            self::assertSame('1000000000000000', $loaded->entries[0]->identity);
            self::assertSame('1e00000000000001', $loaded->entries[1]->identity);
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
     * @param string $root        - Temp project root.
     * @param string $legacyBytes - Bytes staged before the migration.
     *
     * @return void - Asserts that the staged legacy file still has exactly the supplied bytes.
     */
    private function assertLegacyInputUnchanged(string $root, string $legacyBytes): void
    {
        $afterMigration = (string)file_get_contents($root . '/legacy.json');

        self::assertSame($legacyBytes, $afterMigration, 'the migration changed the 0.5 input');
    }

    /**
     * Wrap supplied occurrence JSON in an otherwise valid v3 document.
     *
     * @param string $occurrences - Comma-separated JSON occurrence objects; an empty string creates an empty list.
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
     * @param int         $line     - Source line; varied to prove same-identity findings on different lines aggregate.
     * @param string      $ruleId   - Rule id, part of the identity.
     * @param string      $message  - Message; only part of the identity when no symbol is named.
     * @param string|null $symbol   - Symbol the finding is anchored to; null for a file-level finding.
     * @param Pillar      $pillar   - Pillar assigned to the finding; SensitiveData creates a sensitive-data fixture.
     *
     * @return Finding - Advisory finding using the supplied fields, with Documentation as the default pillar.
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
     * @param string $path - Temporary directory to remove; a missing or non-directory path is ignored.
     *
     * @return void - Removes the tree if present, unlinking nested directory symlinks without following them.
     */
    private function removeDir(string $path): void
    {
        // Cleanup also runs when validation fails before the temporary directory is created.
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        // Remove only entries that belong to this test's temporary folder.
        foreach ($items as $directoryEntry) {
            // Skip the entries that refer to the test directory itself and its parent.
            if ($directoryEntry === '.' || $directoryEntry === '..') {
                continue;
            }

            $child = $path . '/' . $directoryEntry;

            // Recurse into fixture folders while leaving a symlink target outside the cleanup untouched.
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
