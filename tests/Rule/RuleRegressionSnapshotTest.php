<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceDiscovery;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Rules\Complexity\HalsteadVolumeRule;
use GruffPhp\Rules\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rules\Docs\MissingReadmeRule;
use GruffPhp\Rules\Docs\ParamCommentRule;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use GruffPhp\Rules\Security\DebugModeEnabledRule;
use GruffPhp\Rules\Security\DependencyComposerPathRule;
use GruffPhp\Rules\Security\DependencyComposerVcsRule;
use GruffPhp\Rules\Security\DisabledSslVerificationRule;
use GruffPhp\Rules\Security\HeaderInjectionRule;
use GruffPhp\Rules\Security\SilentCatchRule;
use GruffPhp\Rules\Security\SqlConcatenationRule;
use GruffPhp\Rules\Security\UnsafeUnserializeRule;
use GruffPhp\Rules\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rules\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rules\Size\ClassLengthRule;
use GruffPhp\Rules\Size\FileLengthRule;
use GruffPhp\Rules\Size\MethodLengthRule;
use GruffPhp\Rules\TestQuality\LoopAssertionWithoutMessageRule;
use GruffPhp\Rules\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rules\TestQuality\NoAssertionsRule;
use GruffPhp\Rules\TestQuality\PhpUnitCoverageSourceMissingRule;
use GruffPhp\Rules\TestQuality\PhpUnitDeprecationsNotFatalRule;
use GruffPhp\Rules\TestQuality\PhpUnitStrictFlagsMissingRule;
use GruffPhp\Rules\TestQuality\SutNotCalledRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use PHPUnit\Framework\TestCase;

/**
 * Pins the default corpus's unit count, finding count and canonical finding payload hash.
 *
 * Separate calibration scans supply rule coverage missing from the default corpus.
 * Together the tests require at least one finding for every registered rule.
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
     * @return void - Asserts the fixture-unit count, finding count and canonical payload hash.
     */
    public function testDefaultRuleRegistryFindingsStayStableAcrossFixtures(): void
    {
        [$units, $findings, $json] = $this->analysePaths(['tests/Fixtures']);







        self::assertCount(163, $units);








        // M10 D23 (2026-09-25) removed one: synthetic-secrets.php's digit-free mixed-case alphabet run, since a
        // qualifying high-entropy literal now holds a letter and a digit.
































        // Precision-floor M09 (2026-11-22) removed two: default scans no longer run complexity.cyclomatic or
        // test-quality.setup-bloat, and complexity.maintainability-index is off by default.
        self::assertCount(1974, $findings);










        self::assertSame(
            '70302b562728d02cffe46552e6c404785a89a4d02d47d992b846b9bffbec763d',
            hash('sha256', $json),
        );
    }

    /**
     * Verify every registered rule has either default-corpus signal or explicit calibration coverage.
     *
     * @return void - Asserts the expected default coverage gaps and that supplemental findings cover each gap.
     */
    public function testDefaultAndSupplementalCalibrationScenariosCoverEveryRegisteredRule(): void
    {
        $registry            = RuleRegistry::defaults();
        [, $defaultFindings] = $this->analysePaths(['tests/Fixtures']);
        $registeredRuleIds   = array_map(static fn($rule): string => $rule->definition()->id, $registry->all());
        $defaultRuleIds      = $this->uniqueRuleIds($defaultFindings);
        $defaultMissing      = array_values(array_diff($registeredRuleIds, $defaultRuleIds));

        self::assertSame([
                             HalsteadVolumeRule::ID,
                             MaintainabilityIndexRule::ID,
                             UnusedPrivateMethodRule::ID,
                             MissingReadmeRule::ID,
                             ParamCommentRule::ID,
                             DangerousFunctionCallRule::ID,
                             DebugModeEnabledRule::ID,
                             DependencyComposerPathRule::ID,
                             DependencyComposerVcsRule::ID,
                             DisabledSslVerificationRule::ID,
                             HeaderInjectionRule::ID,
                             SilentCatchRule::ID,
                             SqlConcatenationRule::ID,
                             UnsafeUnserializeRule::ID,
                             DatabaseUrlPasswordRule::ID,
                             PiiTestFixtureRule::ID,
                             ClassLengthRule::ID,
                             FileLengthRule::ID,
                             MethodLengthRule::ID,
                             LoopAssertionWithoutMessageRule::ID,
                             MockingDomainObjectRule::ID,
                             NoAssertionsRule::ID,
                             PhpUnitCoverageSourceMissingRule::ID,
                             PhpUnitDeprecationsNotFatalRule::ID,
                             PhpUnitStrictFlagsMissingRule::ID,
                             SutNotCalledRule::ID,
                             TestLongerThanSutRule::ID,
                         ], $defaultMissing);

        $supplementalRuleIds = $this->uniqueRuleIds($this->supplementalCalibrationFindings());

        self::assertSame([], array_values(array_diff($defaultMissing, $supplementalRuleIds)));
    }

    /**
     * Analyse fixture paths and return findings for assertions.
     *
     * @param list<string>        $paths       - Fixture paths to parse and analyse.
     * @param AnalysisConfig|null $config      - Optional config override, or null to use default-registry config.
     * @param string              $projectRoot - Project root used to resolve fixture paths and rule context.
     *
     * @return array{0: list<AnalysisUnit>, 1: list<Finding>, 2: string} - Parsed units, raw findings and canonical JSON, in that order.
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
     * Build supplemental calibration findings for the test fixture.
     *
     * @return list<Finding> - All findings from supplemental scans, including rules already represented in the default corpus.
     */
    private function supplementalCalibrationFindings(): array
    {
        $registry = RuleRegistry::defaults();
        $findings = [];

        array_push(
               $findings,
            // Rooted at tests/Fixtures, so the maintainability index, which skips test paths, reads these as source files.
            ...$this->analysePaths(
            ['Complexity'],
            (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/complexity-low-thresholds.yaml', $registry),
            self::PROJECT_ROOT . '/tests/Fixtures',
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
            ...$this->paramCommentFindings(),
            ...$this->phpUnitConfigCalibrationFindings(),
            ...$this->analysePaths(
            ['tests/Fixtures/TestQuality/mocking-domain-object.php'],
            (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/enable-mocking-domain-object.yaml', $registry),
        )[1],
            ...$this->analysePaths(
            ['tests/Fixtures/TestQuality/testdox-readability.php'],
            (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/enable-testdox-readability.yaml', $registry),
        )[1],
            // Enable opt-in rules so the coverage assertion can include their findings.
            ...$this->analysePaths(
            ['tests/Fixtures/Security', 'tests/Fixtures/DeadCode', 'tests/Fixtures/SensitiveData', 'tests/Fixtures/TestQuality'],
            (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/enable-default-off-rules.yaml', $registry),
        )[1],
        );

        return $findings;
    }

    /**
     * Collect findings from a summarised function whose bare parameter tag is not in a tags-only block.
     *
     * @return list<Finding> - All findings from the synthetic source, with docs.param-comment explicitly enabled.
     */
    private function paramCommentFindings(): array
    {
        $root = $this->tempDir();

        try {
            self::assertTrue(mkdir($root . '/src', 0777, true));
            file_put_contents(
                $root . '/src/Example.php',
                "<?php\n\n/**\n * Example of a summarised function with a bare parameter tag.\n */\n\n"
                . "/**\n * Doubles a value.\n *\n * @param int \$value\n *\n * @return int The doubled value.\n */\n"
                . "function doubled(int \$value): int\n{\n    return \$value * 2;\n}\n",
            );
            $config = AnalysisConfig::fromRegistry(RuleRegistry::defaults())
                ->withRuleSettings(ParamCommentRule::ID, new RuleSettings(true, []));

            return $this->analysePaths(['src/Example.php'], $config, $root)[1];
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Build missing readme findings for the test fixture.
     *
     * @return list<Finding> - All findings from a synthetic src tree with no README.
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
     * Build PHPUnit configuration calibration findings for the test fixture.
     *
     * @return list<Finding> - All findings for non-candidates.php analysed with the lax PHPUnit project root.
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
     * List unique rule identifiers present in finding output.
     *
     * @param list<Finding> $findings - Findings whose rule ids feed the supplemental coverage assertion.
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

        return $ruleIds;
    }

    /**
     * Normalize findings to stable arrays for regression snapshots.
     *
     * @param list<Finding> $findings - Findings to convert into canonical snapshot payload rows.
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

        return $payload;
    }

    /**
     * Build a stable finding payload row for snapshot hashing.
     *
     * @param Finding $finding - Finding to serialize with sorted top-level and metadata-map keys.
     *
     * @return FindingArray - the finding payload with top-level keys sorted so equal findings serialise byte-for-byte alike
     */
    private static function canonicalFindingArray(Finding $finding): array
    {
        $findingPayload = $finding->toArray();

        if (is_array($findingPayload['metadata'])) {
            $findingPayload['metadata'] = self::canonicalMetadata($findingPayload['metadata']);
        }

        ksort($findingPayload, SORT_STRING);

        return $findingPayload;
    }

    /**
     * Sorts top-level and immediate nested map keys so equivalent metadata produces a stable regression snapshot.
     *
     * @param FindingMetadata $metadata - Metadata to sort by its top-level and immediate nested map keys.
     *
     * @return FindingMetadata - Metadata with top-level and immediate nested map keys sorted for a stable snapshot hash.
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
     * @return string - path to a freshly created, uniquely named temp directory for the caller to populate
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
     * @param string $path - Temporary directory root to clean up; non-directory paths are ignored.
     *
     * @return void - Recursively removes entries and attempts to remove the directory.
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
