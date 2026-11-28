<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Covers the --print-runtime CLI contract used by scripts/test-performance.sh.
 * Checks summary fields, detailed rule timings, exclusions, and phase coverage.
 * Checks that the mixed fixture leaves stderr empty when runtime reporting is omitted.
 */
final class AnalyseCliRuntimeTest extends CliTestCase
{
    /**
     * Verify the trailing stderr payload has summary fields and omits per-rule totals.
     *
     * @return void - Asserts summary counters and phase durations, and that per-rule totals are absent.
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
     * @return void - Asserts nonempty per-rule rows with positive invocation counts, nonnegative durations, and descending totals.
     */
    public function testPrintRuntimeDetailedAddsPerRuleTotals(): void
    {
        // Disable cached findings so this test measures rule invocations on a fresh analysis.
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--no-cache',
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
     * Verify --exclude-rule removes the rule from execution: it must not appear in the detailed per-rule totals.
     *
     * @return void - Asserts the control records the rule, the excluded run omits it, and rulesExecuted drops by one.
     */
    public function testExcludeRuleRemovesRuleFromExecution(): void
    {
        $controlPayload  = $this->detailedRuntimePayload([]);
        $excludedPayload = $this->detailedRuntimePayload(['--exclude-rule', 'docs.missing-class-phpdoc']);

        $controlRuleIds  = array_column($controlPayload['rules'], 'ruleId');
        $excludedRuleIds = array_column($excludedPayload['rules'], 'ruleId');

        self::assertContains('docs.missing-class-phpdoc', $controlRuleIds, 'control run must execute the rule for the exclusion proof to mean anything');
        self::assertNotContains('docs.missing-class-phpdoc', $excludedRuleIds, '--exclude-rule must remove the rule from execution, not merely hide its findings');
        self::assertSame(
            $controlPayload['rulesExecuted'] - 1,
            $excludedPayload['rulesExecuted'],
            'the enabled-rule count must drop by exactly the one excluded rule',
        );
    }

    /**
     * Verify timed phases total more than half the wall time for an uncached src scan.
     * This detects untimed work only when it pushes the measured ratio to 0.5 or below.
     *
     * @return void - Asserts positive wall time, integer phase durations, and phase coverage above 0.5.
     */
    public function testRuntimePhasesCoverMostOfWallTime(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'src',
            '--no-config',
            '--no-baseline',
            '--no-cache',
            '--print-runtime',
            '--fail-on',
            'none',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $payload = $this->decodeRuntimePayload($process->getErrorOutput());
        self::assertIsInt($payload['wallMs']);
        self::assertGreaterThan(0, $payload['wallMs']);
        self::assertIsArray($payload['phases']);

        $phaseTotalNs = 0;
        foreach ($payload['phases'] as $phaseKey => $phaseNs) {
            self::assertIsInt($phaseNs, "phase {$phaseKey} must be an integer nanosecond count");
            $phaseTotalNs += $phaseNs;
        }

        $wallNs   = $payload['wallMs'] * 1_000_000;
        $coverage = $phaseTotalNs / $wallNs;

        self::assertGreaterThan(
            0.5,
            $coverage,
            sprintf('timed phases cover only %.2f of wall time; an untimed seam has reappeared (phases %dns, wall %dns)', $coverage, $phaseTotalNs, $wallNs),
        );
    }

    /**
     * Verify a successful analyse run leaves stderr empty when --print-runtime is omitted.
     *
     * @return void - Asserts exit status 0 and empty stderr for the mixed fixture.
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
     * Run a detailed-runtime analyse over the mixed fixture with extra arguments and decode the payload.
     *
     * @param list<string> $extraArguments - Additional analyse CLI arguments appended to the base invocation.
     *
     * @return array{rulesExecuted: int, rules: list<array{ruleId: string, totalNs: int, invocations: int}>} - Decoded detailed runtime payload.
     */
    private function detailedRuntimePayload(array $extraArguments): array
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--no-config',
            '--no-baseline',
            '--no-cache',
            '--print-runtime',
            '--runtime-mode=detailed',
            '--fail-on',
            'none',
            ...$extraArguments,
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $payload = $this->decodeRuntimePayload($process->getErrorOutput());
        self::assertIsInt($payload['rulesExecuted']);
        self::assertIsArray($payload['rules']);

        /**
         * @var array{rulesExecuted: int, rules: list<array{ruleId: string, totalNs: int, invocations: int}>} $payload - CLI row shape assumed.
         */
        return $payload;
    }

    /**
     * Decode the trailing JSON line of an analyse command's stderr output.
     *
     * @param string $stderr - Captured stderr whose final non-blank line is the --print-runtime JSON payload.
     *
     * @return array<string, mixed> - Parsed runtime payload.
     */
    private function decodeRuntimePayload(string $stderr): array
    {
        // Other stderr output may precede the payload, so only the final non-blank line is decoded as JSON.
        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($stderr)) ?: [], static fn (string $line): bool => $line !== ''));
        self::assertNotEmpty($lines, 'stderr must contain a runtime payload line');

        $payload = json_decode($lines[count($lines) - 1], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload - Runtime data from the trailing JSON line. */
        return $payload;
    }
}
