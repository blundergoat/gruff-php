<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GruffCliSummaryTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../..';

    public function testSummaryRunsAndShowsDigestSections(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'summary',
            'tests/Fixtures/Source/mixed',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $output = $process->getOutput();

        self::assertStringContainsString('gruff 0.1.0-dev — summary', $output);
        self::assertStringContainsString('Paths     tests/Fixtures/Source/mixed', $output);
        self::assertStringContainsString('Composite', $output);
        self::assertStringContainsString('Pillars', $output);
        self::assertStringContainsString('Top', $output);
        self::assertStringContainsString('Totals', $output);
    }

    public function testSummaryDoesNotEmitPerFindingLines(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'summary',
            'tests/Fixtures/Source/mixed',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode());

        // The text reporter shows per-finding `[warning] rule.id` lines under "Findings".
        // The summary digest must aggregate; it must not include those lines.
        self::assertStringNotContainsString('[warning]', $process->getOutput());
        self::assertStringNotContainsString('[advisory]', $process->getOutput());
        self::assertStringNotContainsString('Findings', $process->getOutput());
    }

    /**
     * @throws JsonException
     */
    public function testSummaryJsonOutputMatchesSchema(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
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

        self::assertSame('gruff.summary.v1', $decoded['schemaVersion'] ?? null);
        $tool = $decoded['tool'] ?? null;
        self::assertIsArray($tool);
        self::assertSame('gruff', $tool['name'] ?? null);
        self::assertSame('0.1.0-dev', $tool['version'] ?? null);

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

    public function testSummaryRejectsUnknownFormat(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'summary',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--format',
            'yaml',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('USAGE-ERROR Unsupported summary format "yaml"', $process->getOutput());
    }

    public function testSummaryRejectsNonIntegerTop(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'summary',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--top',
            'lots',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('USAGE-ERROR --top must be a non-negative integer.', $process->getOutput());
    }

    public function testSummaryRejectsBothConfigAndNoConfig(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'summary',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--config',
            '.gruff.yaml',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('--no-config cannot be combined with --config.', $process->getOutput());
    }

    public function testListIncludesSummaryCommand(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff', 'list'], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('summary', $process->getOutput());
    }
}
