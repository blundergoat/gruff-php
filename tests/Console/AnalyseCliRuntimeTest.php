<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Covers the --print-runtime CLI contract used by scripts/test-performance.sh.
 */
final class AnalyseCliRuntimeTest extends CliTestCase
{
    /**
     * Verify --print-runtime emits a single JSON line on stderr with the documented summary shape.
     *
     * @return void
     */
    public function testPrintRuntimeEmitsSummaryShapeOnStderr(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--print-runtime',
            '--fail-on',
            'error',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $payload = $this->decodeRuntimePayload($process->getErrorOutput());

        self::assertSame('summary', $payload['mode']);
        self::assertIsInt($payload['wallMs']);
        self::assertGreaterThanOrEqual(0, $payload['wallMs']);
        self::assertIsInt($payload['peakBytes']);
        self::assertGreaterThan(0, $payload['peakBytes']);
        self::assertIsInt($payload['filesParsed']);
        self::assertIsInt($payload['rulesExecuted']);
        self::assertGreaterThan(0, $payload['rulesExecuted']);

        self::assertIsArray($payload['phases']);
        foreach (['discoverParseNs', 'analyseNs', 'scoreNs', 'reportNs'] as $phaseKey) {
            self::assertArrayHasKey($phaseKey, $payload['phases'], "phase {$phaseKey} must be present");
            self::assertIsInt($payload['phases'][$phaseKey], "phase {$phaseKey} must be an integer nanosecond count");
            self::assertGreaterThanOrEqual(0, $payload['phases'][$phaseKey], "phase {$phaseKey} must be non-negative");
        }

        self::assertArrayNotHasKey('rules', $payload, 'summary mode must omit per-rule totals');
    }

    /**
     * Verify --runtime-mode=detailed adds a sorted per-rule totals array.
     *
     * @return void
     */
    public function testPrintRuntimeDetailedAddsPerRuleTotals(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--print-runtime',
            '--runtime-mode=detailed',
            '--fail-on',
            'error',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $payload = $this->decodeRuntimePayload($process->getErrorOutput());

        self::assertSame('detailed', $payload['mode']);
        self::assertIsArray($payload['rules']);
        self::assertNotEmpty($payload['rules'], 'detailed mode must report at least one rule invocation');

        foreach ($payload['rules'] as $index => $ruleRuntime) {
            self::assertIsArray($ruleRuntime, "rule row #{$index} must be an array");
            self::assertArrayHasKey('ruleId', $ruleRuntime, "rule row #{$index} must include ruleId");
            self::assertIsString($ruleRuntime['ruleId'], "rule row #{$index} ruleId must be a string");
            self::assertArrayHasKey('totalNs', $ruleRuntime, "rule row #{$index} must include totalNs");
            self::assertIsInt($ruleRuntime['totalNs'], "rule row #{$index} totalNs must be an integer");
            self::assertGreaterThanOrEqual(0, $ruleRuntime['totalNs'], "rule row #{$index} totalNs must be non-negative");
            self::assertArrayHasKey('invocations', $ruleRuntime, "rule row #{$index} must include invocations");
            self::assertIsInt($ruleRuntime['invocations'], "rule row #{$index} invocations must be an integer");
            self::assertGreaterThan(0, $ruleRuntime['invocations'], "rule row #{$index} invocations must be positive");
        }

        $totals = array_column($payload['rules'], 'totalNs');
        $sorted = $totals;
        rsort($sorted);
        self::assertSame($sorted, $totals, 'rules list must be sorted by descending totalNs');
    }

    /**
     * Verify the analyse command default behaviour is unchanged when --print-runtime is omitted.
     *
     * @return void
     */
    public function testAnalyseWithoutPrintRuntimeProducesEmptyStderr(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--fail-on',
            'error',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode());
        self::assertSame('', $process->getErrorOutput(), 'no stderr output expected without --print-runtime');
    }

    /**
     * Decode the trailing JSON line of an analyse command's stderr output.
     *
     * @return array<string, mixed> Parsed runtime payload.
     */
    private function decodeRuntimePayload(string $stderr): array
    {
        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($stderr)) ?: [], static fn (string $line): bool => $line !== ''));
        self::assertNotEmpty($lines, 'stderr must contain a runtime payload line');

        $payload = json_decode($lines[count($lines) - 1], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload decoded JSON payload is validated key by key below. */
        return $payload;
    }
}
