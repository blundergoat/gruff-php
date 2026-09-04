<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers which rules an `analyse` run actually executes once selection, profiles, and exclusions are applied.
 *
 * Selection changes both the findings a user sees and the exit status CI reads, so these cases check that an
 * excluded rule stops running rather than being filtered from the report, that configured selection is honoured,
 * that the security profile narrows the run and overrides configured selection, and that an unknown profile is
 * refused. Split from AnalyseCliTest so neither class exceeds the family public-method threshold.
 */
final class AnalyseCliRuleSelectionTest extends CliTestCase
{
    /**
     * Verify --exclude-rule is execution-level: the excluded rule's findings neither display nor trip the fail gate.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandExcludeRuleSkipsExecutionAndExitCode(): void
    {
        $baseArguments = [
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--no-config',
            '--no-baseline',
            '--format',
            'json',
            '--fail-on',
            'advisory',
        ];

        $controlProcess = new Process($baseArguments, __DIR__ . '/../..');
        $controlProcess->run();
        self::assertSame(1, $controlProcess->getExitCode(), 'the fixture must trip the advisory gate for the exclusion proof to mean anything');

        $excludedProcess = new Process([...$baseArguments, '--exclude-rule', 'docs.missing-public-phpdoc'], __DIR__ . '/../..');
        $excludedProcess->run();
        self::assertSame(0, $excludedProcess->getExitCode(), $excludedProcess->getErrorOutput());

        $report  = $this->decodeJsonOutput($excludedProcess);
        $summary = $report['summary'] ?? null;
        self::assertIsArray($summary);
        $findingCounts = $summary['findings'] ?? null;
        self::assertIsArray($findingCounts);
        self::assertSame(0, $findingCounts['total'] ?? null);
    }

    /**
     * Verify analyse command applies configured rule selection.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandAppliesConfiguredRuleSelection(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--config',
                                   'tests/Fixtures/Config/only-size-rules.yaml',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report  = $this->decodeJsonOutput($process);
        $summary = $report['summary'] ?? null;
        self::assertIsArray($summary);
        $findingCounts = $summary['findings'] ?? null;
        self::assertIsArray($findingCounts);
        self::assertSame(0, $findingCounts['total'] ?? null);
    }

    /**
     * Verify security profile limits rule execution to security and sensitive-data rules.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandSecurityProfileRunsSecurityRulesOnly(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Security/cumulative-security.php',
                                   '--no-config',
                                   '--profile',
                                   'security',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        $score    = $report['score'] ?? null;
        self::assertIsArray($findings);
        self::assertIsArray($score);
        $composite = $score['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertNotCount(0, $findings);
        self::assertSame('C', $composite['grade'] ?? null);

        foreach ($findings as $index => $finding) {
            self::assertIsArray($finding, sprintf('Finding %d should be an array.', $index));
            $ruleId = $finding['ruleId'] ?? null;
            self::assertIsString($ruleId, sprintf('Finding %d should include a string ruleId.', $index));
            self::assertTrue(
                str_starts_with($ruleId, 'security.') || str_starts_with($ruleId, 'sensitive-data.'),
                'Unexpected rule from security profile: ' . $ruleId,
            );
        }
    }

    /**
     * Verify security profile replaces configured rule selection.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandSecurityProfileOverridesConfiguredSelection(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Security/cumulative-security.php',
                                   '--config',
                                   'tests/Fixtures/Config/only-size-rules.yaml',
                                   '--profile',
                                   'security',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        $ruleIds = array_map(
            static fn(mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
            $findings,
        );

        self::assertContains('security.dangerous-function-call', $ruleIds);
        self::assertNotContains('docs.missing-file-phpdoc', $ruleIds);
    }

    /**
     * Verify analyse command rejects unknown execution profiles.
     *
     * @return void
     */
    public function testAnalyseCommandReportsInvalidProfile(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   '--profile',
                                   'security-plus',
                                   'tests/Fixtures/Source/Code',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[USAGE-ERROR] Unsupported profile "security-plus". Use default or security.', $process->getOutput());
    }
}
