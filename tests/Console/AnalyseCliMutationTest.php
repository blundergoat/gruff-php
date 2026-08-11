<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers analyse command mutation-report behavior.
 */
final class AnalyseCliMutationTest extends CliTestCase
{
    /**
     * Verify analyse command ingests infection report in JSON.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testAnalyseCommandIngestsInfectionReportInJson(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--infection-report',
            'tests/Fixtures/Mutation/Infection/infection-valid.json',
            '--format',
            'json',
            '--fail-on',
            'none',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $mutation = $report['mutation'] ?? null;
        $findings = $report['findings'] ?? null;

        self::assertIsArray($mutation);
        $totals = $mutation['totals'] ?? null;
        self::assertIsArray($totals);
        self::assertEquals(50.0, $totals['msi'] ?? null);
        self::assertSame(2, $totals['survivedMutants'] ?? null);
        self::assertIsArray($findings);
        self::assertContains(
            'mutation.survived-mutant',
            array_map(
                static fn (mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
                $findings,
            ),
        );

        $mutationFindings = array_values(array_filter(
            $findings,
            static fn (mixed $finding): bool => is_array($finding)
                && ($finding['ruleId'] ?? null) === 'mutation.survived-mutant',
        ));
        $firstMutationFinding = $mutationFindings[0] ?? null;

        self::assertIsArray($firstMutationFinding);
        $metadata = $firstMutationFinding['metadata'] ?? null;
        self::assertIsArray($metadata);
        self::assertEquals(50.0, $metadata['msi'] ?? null);
        self::assertEquals(50.0, $metadata['coveredMsi'] ?? null);
    }

    /**
     * Verify analyse command renders mutation summary in text.
     *
     * @return void
     */
    public function testAnalyseCommandRendersMutationSummaryInText(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--infection-report',
            'tests/Fixtures/Mutation/Infection/infection-valid.json',
            '--fail-on',
            'none',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Mutation', $process->getOutput());
        self::assertStringContainsString('MSI: 50.00%', $process->getOutput());
        self::assertStringContainsString('Statuses: escaped=1, killed=2, timed out=1', $process->getOutput());
        self::assertStringContainsString('Mutation escaped via Plus; tests did not fail against this mutant.', $process->getOutput());
        self::assertStringContainsString('Mutation timed out via IntegerPlus; Infection exceeded the timeout before a clear test failure.', $process->getOutput());
        self::assertStringContainsString('mutation.survived-mutant', $process->getOutput());
    }

    /**
     * Verify analyse command renders score and mutation context in markdown.
     *
     * @return void
     */
    public function testAnalyseCommandRendersScoreAndMutationContextInMarkdown(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--infection-report',
            'tests/Fixtures/Mutation/Infection/infection-valid.json',
            '--format',
            'markdown',
            '--fail-on',
            'none',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('**Score drivers:** Per-pillar scores start at 100', $process->getOutput());
        self::assertStringContainsString('Mutation uses the supplied Infection MSI as the mutation pillar score.', $process->getOutput());
        self::assertStringContainsString('**Mutation:** MSI 50.00%', $process->getOutput());
        self::assertStringContainsString('**Mutation statuses:** escaped=1, killed=2, timed out=1.', $process->getOutput());
    }

    /**
     * Verify analyse command reports mutation budget and msi regression.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testAnalyseCommandReportsMutationBudgetAndMsiRegression(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--infection-report',
            'tests/Fixtures/Mutation/Infection/infection-valid.json',
            '--mutation-baseline',
            'tests/Fixtures/Mutation/Infection/infection-baseline.json',
            '--mutation-budget',
            '1',
            '--format',
            'json',
            '--fail-on',
            'none',
            '--no-config',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $mutation = $report['mutation'] ?? null;
        $findings = $report['findings'] ?? null;

        self::assertIsArray($mutation);
        $baseline = $mutation['baseline'] ?? null;
        $budget   = $mutation['budget'] ?? null;
        self::assertIsArray($baseline);
        self::assertIsArray($budget);
        self::assertEquals(-30.0, $baseline['delta'] ?? null);
        self::assertSame(true, $budget['exceeded'] ?? null);
        self::assertIsArray($findings);

        $ruleIds = array_map(
            static fn (mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
            $findings,
        );

        self::assertContains('mutation.budget-exceeded', $ruleIds);
        self::assertContains('mutation.msi-regression', $ruleIds);
    }
}
