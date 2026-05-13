<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceDiscovery;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers cross-rule regression behaviour over the fixture corpus.
 */
final class RuleRegressionSnapshotTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify the default rule registry keeps fixture findings stable.
     *
     * @return void No return value.
     */
    public function testDefaultRuleRegistryFindingsStayStableAcrossFixtures(): void
    {
        [$units, $findings, $json] = $this->analysePaths(['tests/Fixtures']);

        self::assertCount(128, $units);
        self::assertCount(1889, $findings);
        self::assertSame('bb1d41c2f2c6d49e93608a34dced84d4125fee58519a57b6bd6b75554a7d00a1', hash('sha256', $json));
    }

    /**
     * @param list<string> $paths
     * @return array{0: list<AnalysisUnit>, 1: list<Finding>, 2: string}
     */
    private function analysePaths(array $paths): array
    {
        $registry = RuleRegistry::defaults();
        $parser = new PhpFileParser();
        $files  = (new SourceDiscovery(self::PROJECT_ROOT))->discover($paths, true)->files;
        $units  = array_map(
            static fn (SourceFile $file): AnalysisUnit => $parser->parse($file),
            $files,
        );
        $findings = $registry->analyse($units, new RuleContext(
            self::PROJECT_ROOT,
            AnalysisConfig::fromRegistry($registry),
        ));
        $payload = $this->canonicalFindingPayload($findings);
        $json    = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertSame(count($files), count($units));

        return [$units, $findings, $json];
    }

    /**
     * @param list<Finding> $findings
     * @return list<array<mixed>>
     */
    private function canonicalFindingPayload(array $findings): array
    {
        $payload = array_map(static function (Finding $finding): array {
            $row = $finding->toArray();
            self::sortRecursively($row);

            return $row;
        }, $findings);

        usort($payload, static fn (array $left, array $right): int => $left <=> $right);

        return $payload;
    }

    /**
     * @param array<mixed> $value
     * @return void No return value.
     */
    private static function sortRecursively(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursively($item);
            }
        }
        unset($item);

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
    }
}
