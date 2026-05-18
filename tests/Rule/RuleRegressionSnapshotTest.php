<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\HalsteadVolumeRule;
use GruffPhp\Rule\Docs\MissingReadmeRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\AverageMethodLengthRule;
use GruffPhp\Rule\Size\FileLengthRule;
use GruffPhp\Rule\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rule\TestQuality\MultipleAaaCyclesRule;
use GruffPhp\Rule\TestQuality\PhpUnitCoverageSourceMissingRule;
use GruffPhp\Rule\TestQuality\PhpUnitDeprecationsNotFatalRule;
use GruffPhp\Rule\TestQuality\PhpUnitStrictFlagsMissingRule;
use GruffPhp\Rule\TestQuality\TestdoxReadabilityRule;
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
     * @return void No return value.
     */
    public function testDefaultRuleRegistryFindingsStayStableAcrossFixtures(): void
    {
        [$units, $findings, $json] = $this->analysePaths(['tests/Fixtures']);

        self::assertCount(135, $units);
        self::assertCount(2152, $findings);
        self::assertSame(
            '72053e1f75e1c7f9d3ef' . '54f4f94bc008ae9696726a343c615fdff1605766057a',
            hash('sha256', $json),
        );
    }

    /**
     * Verify every registered rule has either default-corpus signal or explicit calibration coverage.
     *
     * @return void No return value.
     */
    public function testDefaultAndSupplementalCalibrationScenariosCoverEveryRegisteredRule(): void
    {
        $registry            = RuleRegistry::defaults();
        [, $defaultFindings] = $this->analysePaths(['tests/Fixtures']);
        $registeredRuleIds   = array_map(static fn ($rule): string => $rule->definition()->id, $registry->all());
        $defaultRuleIds      = $this->uniqueRuleIds($defaultFindings);
        $defaultMissing      = array_values(array_diff($registeredRuleIds, $defaultRuleIds));

        self::assertSame([
            HalsteadVolumeRule::ID,
            MissingReadmeRule::ID,
            FileLengthRule::ID,
            MockingDomainObjectRule::ID,
            MultipleAaaCyclesRule::ID,
            PhpUnitCoverageSourceMissingRule::ID,
            PhpUnitDeprecationsNotFatalRule::ID,
            PhpUnitStrictFlagsMissingRule::ID,
            TestdoxReadabilityRule::ID,
        ], $defaultMissing);

        $supplementalRuleIds = $this->uniqueRuleIds($this->supplementalCalibrationFindings());

        self::assertSame([], array_values(array_diff($defaultMissing, $supplementalRuleIds)));
    }

    /**
     * @param list<string>        $paths
     * @param AnalysisConfig|null $config
     * @param string              $projectRoot
     * @return array{0: list<AnalysisUnit>, 1: list<Finding>, 2: string}
     */
    private function analysePaths(
        array $paths,
        ?AnalysisConfig $config = null,
        string $projectRoot = self::PROJECT_ROOT,
    ): array
    {
        $registry = RuleRegistry::defaults();
        $parser   = new PhpFileParser();
        $files    = (new SourceDiscovery($projectRoot))->discover($paths, true)->files;
        $units    = array_map(
            static fn (SourceFile $file): AnalysisUnit => $parser->parse($file),
            $files,
        );
        $findings = $registry->analyse($units, new RuleContext(
            $projectRoot,
            $config ?? AnalysisConfig::fromRegistry($registry),
        ));
        $payload = $this->canonicalFindingPayload($findings);
        $json    = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertSame(count($files), count($units));

        return [$units, $findings, $json];
    }

    /**
     * @return list<Finding>
     */
    private function supplementalCalibrationFindings(): array
    {
        $registry = RuleRegistry::defaults();
        $findings = [];

        array_push(
            $findings,
            ...$this->analysePaths(
                ['tests/Fixtures/Complexity/cognitive.php'],
                AnalysisConfig::fromRegistry($registry)->withRuleSettings(
                    HalsteadVolumeRule::ID,
                    new RuleSettings(true, ['warning' => 30, 'error' => 100]),
                ),
            )[1],
        );
        array_push(
            $findings,
            ...$this->analysePaths(
                ['tests/Fixtures/Size/long-method.php'],
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

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function missingReadmeFindings(): array
    {
        $root = $this->tempDir();

        try {
            self::assertTrue(mkdir($root . '/src', 0777, true));
            file_put_contents($root . '/src/Example.php', "<?php\n\nfinal class Example {}\n");

            return $this->analysePaths(['src/Example.php'], projectRoot: $root)[1];
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * @return list<Finding>
     */
    private function phpUnitConfigCalibrationFindings(): array
    {
        $registry = RuleRegistry::defaults();
        $unit     = (new PhpFileParser())->parse(new SourceFile(
            self::PROJECT_ROOT . '/tests/Fixtures/TestQuality/non-candidates.php',
            'tests/Fixtures/TestQuality/non-candidates.php',
        ));

        return $registry->analyse(
            [$unit],
            new RuleContext(
                self::PROJECT_ROOT . '/tests/Fixtures/PhpUnitConfig/lax',
                AnalysisConfig::fromRegistry($registry),
            ),
        );
    }

    /**
     * @param list<Finding> $findings
     * @return list<string>
     */
    private function uniqueRuleIds(array $findings): array
    {
        $ruleIds = array_values(array_unique(array_map(
            static fn (Finding $finding): string => $finding->ruleId,
            $findings,
        )));
        sort($ruleIds, SORT_STRING);

        return $ruleIds;
    }

    /**
     * @param list<Finding> $findings
     * @return list<FindingArray>
     */
    private function canonicalFindingPayload(array $findings): array
    {
        $payload = array_map(
            static fn (Finding $finding): array => self::canonicalFindingArray($finding),
            $findings,
        );

        usort($payload, static fn (array $left, array $right): int => $left <=> $right);

        return $payload;
    }

    /**
     * Build a stable finding payload row for snapshot hashing.
     *
     * @return FindingArray Canonical finding payload.
     */
    private static function canonicalFindingArray(Finding $finding): array
    {
        $row = $finding->toArray();

        if (is_array($row['metadata'])) {
            $row['metadata'] = self::canonicalMetadata($row['metadata']);
        }

        ksort($row, SORT_STRING);

        return $row;
    }

    /**
     * @param FindingMetadata $metadata Finding metadata payload.
     * @return FindingMetadata Canonical metadata payload.
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

        return $metadata;
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string Fixture value.
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-rule-calibration-' . bin2hex(random_bytes(6));

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

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
