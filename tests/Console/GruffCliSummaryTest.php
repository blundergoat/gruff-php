<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers the summary CLI command: digest section output, suppression of per-finding lines, JSON-schema-conformant output, invalid-option rejection,
 * and registration in the command list.
 */
final class GruffCliSummaryTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify summary runs and shows digest sections.
     *
     * @return void
     */
    public function testSummaryRunsAndShowsDigestSections(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'summary',
                                   'tests/Fixtures/Source/mixed',
                                   '--no-config',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $output = $process->getOutput();

        self::assertStringContainsString('gruff-php 0.3.1 summary', $output);
        self::assertStringContainsString('Paths     tests/Fixtures/Source/mixed', $output);
        self::assertMatchesRegularExpression('/^Composite: [A-F] \(\d+\.\d{2} \/ 100\)$/m', $output);
        self::assertMatchesRegularExpression(
            '/^Findings: \d+ total · \d+ error · \d+ warning · \d+ advisory$/m',
            $output,
        );
        self::assertStringContainsString('Score note Per-pillar scores start at 100', $output);
        self::assertStringContainsString('Pillars', $output);
        self::assertStringContainsString('Top', $output);
        self::assertStringContainsString('gruff-php analyse --generate-baseline', $output);
        self::assertStringContainsString('known debt', $output);
    }

    /**
     * Verify summary does not emit per finding lines.
     *
     * @return void
     */
    public function testSummaryDoesNotEmitPerFindingLines(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'summary',
                                   'tests/Fixtures/Source/mixed',
                                   '--no-config',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode());

        // The analyse text reporter shows per-finding `[warning] rule.id` lines under a bare
        // "Findings" heading. The summary digest must aggregate; it must not include those lines.
        // The canonical `Findings:` tally line (with colon) is expected and asserted elsewhere.
        $output = $process->getOutput();
        self::assertStringNotContainsString('[warning]', $output);
        self::assertStringNotContainsString('[advisory]', $output);
        self::assertDoesNotMatchRegularExpression('/^Findings$/m', $output);
    }

    /**
     * Verify summary JSON output matches schema.
     *
     * @return void
     * @throws JsonException
     */
    public function testSummaryJsonOutputMatchesSchema(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'summary',
                                   'tests/Fixtures/Source/mixed',
                                   '--no-config',
                                   '--format',
                                   'json',
                                   '--top',
                                   '3',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        self::assertSame('gruff.summary.v2', $decoded['schemaVersion'] ?? null);
        $tool = $decoded['tool'] ?? null;
        self::assertIsArray($tool);
        self::assertSame('gruff-php', $tool['name'] ?? null);
        self::assertSame('0.3.1', $tool['version'] ?? null);

        $scope = $decoded['scope'] ?? null;
        self::assertIsArray($scope);
        self::assertSame(['tests/Fixtures/Source/mixed'], $scope['paths'] ?? null);
        self::assertArrayHasKey('configPath', $scope);
        self::assertNull($scope['configPath']);
        self::assertSame(2, $scope['filesDiscovered'] ?? null);

        $composite = $decoded['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertArrayHasKey('score', $composite);
        self::assertArrayHasKey('grade', $composite);

        $findings = $decoded['findings'] ?? null;
        self::assertIsArray($findings);
        self::assertArrayHasKey('total', $findings);
        self::assertArrayHasKey('advisory', $findings);
        self::assertArrayHasKey('warning', $findings);
        self::assertArrayHasKey('error', $findings);

        $topRules = $decoded['topRules'] ?? null;
        self::assertIsArray($topRules);
        self::assertLessThanOrEqual(3, count($topRules));
    }

    /**
     * Verify summary rejects invalid option combinations.
     *
     * @param list<string> $arguments - CLI arguments appended after the base command.
     * @param string       $message - Expected usage error excerpt.
     *
     * @return void
     */
    #[DataProvider('invalidSummaryOptionProvider')]
    public function testSummaryRejectsInvalidOptions(array $arguments, string $message): void
    {
        $process = new Process(array_merge([
                                               PHP_BINARY,
                                               self::PROJECT_ROOT . '/bin/gruff-php',
                                               'summary',
                                               'tests/Fixtures/Source/mixed',
                                               '--no-config',
                                           ], $arguments), self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString($message, $process->getOutput());
    }

    /**
     * Verify list includes summary command.
     *
     * @return void
     */
    public function testListIncludesSummaryCommand(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list'], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('summary', $process->getOutput());
    }

    /**
     * Provide invalid summary option combinations and their usage errors.
     *
     * @return iterable<string, array{0: list<string>, 1: string}> - one named case per yield, each pairing the extra CLI arguments with the
     *                          usage-error excerpt expected on stdout
     */
    public static function invalidSummaryOptionProvider(): iterable
    {
        yield 'unknown format' => [
            ['--format', 'yaml'],
            'USAGE-ERROR Unsupported summary format "yaml"',
        ];
        yield 'non integer top' => [
            ['--top', 'lots'],
            'USAGE-ERROR --top must be a non-negative integer.',
        ];
        yield 'config with no config' => [
            ['--config', '.gruff-php.yaml'],
            '--no-config cannot be combined with --config.',
        ];
    }
}
