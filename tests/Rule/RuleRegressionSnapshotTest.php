<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\CognitiveComplexityRule;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\Complexity\HalsteadVolumeRule;
use GruffPhp\Rule\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rule\Docs\MissingReadmeRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\AverageMethodLengthRule;
use GruffPhp\Rule\Size\ClassLengthRule;
use GruffPhp\Rule\Size\FileLengthRule;
use GruffPhp\Rule\Size\MethodLengthRule;
use GruffPhp\Rule\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rule\TestQuality\PhpUnitCoverageSourceMissingRule;
use GruffPhp\Rule\TestQuality\PhpUnitDeprecationsNotFatalRule;
use GruffPhp\Rule\TestQuality\PhpUnitStrictFlagsMissingRule;
use GruffPhp\Source\SourceDiscovery;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers cross-rule regression behaviour over the fixture corpus.
 *
 * @phpstan-import-type FindingArray from Finding
 * @phpstan-import-type FindingMetadata from Finding
 */
final class RuleRegressionSnapshotTest extends TestCase
{
    /** Project root used by fixture snapshot tests. */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify the default rule registry keeps fixture findings stable.
     *
     * @return void
     */
    public function testDefaultRuleRegistryFindingsStayStableAcrossFixtures(): void
    {
        [$units, $findings, $json] = $this->analysePaths(['tests/Fixtures']);

        self::assertCount(161, $units);
        self::assertCount(2325, $findings);
        self::assertSame(
            '51b9df4d17bb7d5e19854e' . 'a4ffde1e576253cdc4cb1608b4d14c17415a88cc36',
            hash('sha256', $json),
        );
    }

    /**
     * Verify every registered rule has either default-corpus signal or explicit calibration coverage.
     *
     * @return void
     */
    public function testDefaultAndSupplementalCalibrationScenariosCoverEveryRegisteredRule(): void
    {
        $registry = RuleRegistry::defaults();
        [, $defaultFindings] = $this->analysePaths(['tests/Fixtures']);
        $registeredRuleIds = array_map(static fn($rule): string => $rule->definition()->id, $registry->all());
        $defaultRuleIds    = $this->uniqueRuleIds($defaultFindings);
        $defaultMissing    = array_values(array_diff($registeredRuleIds, $defaultRuleIds));

        self::assertSame([
                             CognitiveComplexityRule::ID,
                             CyclomaticComplexityRule::ID,
                             HalsteadVolumeRule::ID,
                             MaintainabilityIndexRule::ID,
                             MissingReadmeRule::ID,
                             AverageMethodLengthRule::ID,
                             ClassLengthRule::ID,
                             FileLengthRule::ID,
                             MethodLengthRule::ID,
                             MockingDomainObjectRule::ID,
                             PhpUnitCoverageSourceMissingRule::ID,
                             PhpUnitDeprecationsNotFatalRule::ID,
                             PhpUnitStrictFlagsMissingRule::ID,
                         ], $defaultMissing);

        $supplementalRuleIds = $this->uniqueRuleIds($this->supplementalCalibrationFindings());

        self::assertSame([], array_values(array_diff($defaultMissing, $supplementalRuleIds)));
    }

    /**
     * Analyse fixture paths and return findings for assertions.
     *
     * @param list<string>        $paths
     * @param AnalysisConfig|null $config
     * @param string              $projectRoot
     *
     * @return array{0: list<AnalysisUnit>, 1: list<Finding>, 2: string} - parsed units, raw findings, and canonical JSON for the analysed paths, in
     *                  that order
     */
    private function analysePaths(
        array           $paths,
        ?AnalysisConfig $config = null,
        string          $projectRoot = self::PROJECT_ROOT,
    ): array {
        $registry      = RuleRegistry::defaults();
        $phpFileParser = new PhpFileParser();
        $files         = (new SourceDiscovery($projectRoot))->discover($paths, true)->files;
        $units         = array_map(
            static fn(SourceFile $file): AnalysisUnit => $phpFileParser->parse($file),
            $files,
        );
        $findings      = $registry->analyse($units, new RuleContext(
            $projectRoot,
            $config ?? AnalysisConfig::fromRegistry($registry),
        ));
        $payload       = $this->canonicalFindingPayload($findings);
        $json          = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertSame(count($files), count($units));

        // Bundle the parsed units, raw findings, and canonical JSON so callers can assert on any of the three.
        return [$units, $findings, $json];
    }

    /**
     * Build supplemental calibration findings for the test fixture.
     *
     * @return list<Finding> - the extra findings the baseline corpus scan cannot reach, merged into one calibration list
     */
    private function supplementalCalibrationFindings(): array
    {
        $registry = RuleRegistry::defaults();
        $findings = [];

        array_push(
               $findings,
            ...$this->analysePaths(
            ['tests/Fixtures/Complexity'],
            (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/complexity-low-thresholds.yaml', $registry),
        )[1],
        );
        array_push(
               $findings,
            ...$this->analysePaths(
            ['tests/Fixtures/Size'],
            (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/size-low-thresholds.yaml', $registry),
        )[1],
        );
        array_push(
               $findings,
            ...$this->missingReadmeFindings(),
            ...$this->phpUnitConfigCalibrationFindings(),
            ...$this->analysePaths(
            ['tests/Fixtures/TestQuality/mocking-domain-object.php'],
            (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/enable-mocking-domain-object.yaml', $registry),
        )[1],
            ...$this->analysePaths(
            ['tests/Fixtures/TestQuality/multiple-aaa-cycles.php'],
            (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/enable-multiple-aaa-cycles.yaml', $registry),
        )[1],
            ...$this->analysePaths(
            ['tests/Fixtures/TestQuality/testdox-readability.php'],
            (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/enable-testdox-readability.yaml', $registry),
        )[1],
        );

        // Hand back the extra findings the baseline scan cannot reach, merged into one calibration list.
        return $findings;
    }

    /**
     * Build missing readme findings for the test fixture.
     *
     * @return list<Finding> - findings from a synthetic src tree with no README, isolating the docs.missing-readme signal
     */
    private function missingReadmeFindings(): array
    {
        $root = $this->tempDir();

        try {
            self::assertTrue(mkdir($root . '/src', 0777, true));
            file_put_contents($root . '/src/Example.php', "<?php\n\nfinal class Example {}\n");

            // A src tree with no README is what trips docs.missing-readme; return only its findings.
            return $this->analysePaths(['src/Example.php'], projectRoot: $root)[1];
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Build php unit config calibration findings for the test fixture.
     *
     * @return list<Finding> - findings produced against the lax phpunit-config fixture root so the phpunit.* config rules fire
     */
    private function phpUnitConfigCalibrationFindings(): array
    {
        $registry = RuleRegistry::defaults();
        $unit     = (new PhpFileParser())->parse(new SourceFile(
                                                     self::PROJECT_ROOT . '/tests/Fixtures/TestQuality/non-candidates.php',
                                                     'tests/Fixtures/TestQuality/non-candidates.php',
                                                 ));

        // Run against the lax phpunit-config fixture root so the phpunit.* config rules fire for the snapshot.
        return $registry->analyse(
            [$unit],
            new RuleContext(
                self::PROJECT_ROOT . '/tests/Fixtures/PhpUnitConfig/lax',
                AnalysisConfig::fromRegistry($registry),
            ),
        );
    }

    /**
     * List unique rule identifiers present in finding output.
     *
     * @param list<Finding> $findings
     *
     * @return list<string> - the de-duplicated rule ids in stable ascending string order; empty when no findings
     */
    private function uniqueRuleIds(array $findings): array
    {
        $ruleIds = array_values(array_unique(array_map(
                                                 static fn(Finding $finding): string => $finding->ruleId,
                                                 $findings,
                                             )));
        sort($ruleIds, SORT_STRING);

        // Hand back the de-duplicated rule ids in stable string order so the snapshot is deterministic.
        return $ruleIds;
    }

    /**
     * Normalize findings to stable arrays for regression snapshots.
     *
     * @param list<Finding> $findings
     *
     * @return list<FindingArray> - rows sorted into a canonical order so reordered findings still hash identically
     */
    private function canonicalFindingPayload(array $findings): array
    {
        $payload = array_map(
            static fn(Finding $finding): array => self::canonicalFindingArray($finding),
            $findings,
        );

        usort($payload, static fn(array $left, array $right): int => $left <=> $right);

        // Hand back the rows sorted into a canonical order so reordered findings still hash identically.
        return $payload;
    }

    /**
     * Build a stable finding payload row for snapshot hashing.
     *
     * @param Finding $finding single finding to flatten; its metadata is recursively key-sorted for stability
     *
     * @return FindingArray - the flattened finding with top-level keys sorted so equal findings serialise byte-for-byte alike
     */
    private static function canonicalFindingArray(Finding $finding): array
    {
        $findingPayload = $finding->toArray();

        if (is_array($findingPayload['metadata'])) {
            $findingPayload['metadata'] = self::canonicalMetadata($findingPayload['metadata']);
        }

        ksort($findingPayload, SORT_STRING);

        // Hand back the row with top-level keys sorted so two equal findings serialise byte-for-byte alike.
        return $findingPayload;
    }

    /**
     * @param FindingMetadata $metadata Finding metadata payload.
     *
     * @return FindingMetadata - the metadata with both nested maps and the top level key-sorted for a stable snapshot hash
     */
    private static function canonicalMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_array($value) && !array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            $metadata[$key] = $value;
        }

        ksort($metadata, SORT_STRING);

        // Hand back the metadata with both nested maps and the top level key-sorted for a stable snapshot.
        return $metadata;
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string - absolute path to a freshly created, uniquely named temp directory for the caller to populate
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-rule-calibration-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        // Hand back the freshly created, uniquely named temp directory path for the caller to populate.
        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Filesystem path.
     *
     * @return void
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            // Nothing to clean up when the path was never created; treat removal as a no-op.
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
