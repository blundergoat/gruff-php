<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers analyse output when an Infection report is supplied.
 * Checks mutation totals, finding metadata, and text and Markdown summaries.
 * Checks budget and baseline findings with --fail-on=none and a successful exit.
 */
final class AnalyseCliMutationTest extends CliTestCase
{
    /**
     * Verify analyse command ingests infection report in JSON.
     *
     * @throws JsonException - If decoding the command's JSON output fails.
     *
     * @return void - Asserts mutation totals and MSI and covered MSI metadata on the first survived-mutant finding.
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
        $mutation = $this->decodedJsonObjectAt($report, 'extensions', 'php', 'topLevel', 'mutation');
        $findings = $report['findings'] ?? null;

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
     * @return void - Asserts success, MSI 50.00%, status counts, escaped and timed-out explanations, and a survived-mutant rule ID.
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
     * @return void - Asserts successful Markdown output includes score-driver guidance, MSI 50.00%, and mutation status counts.
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
        self::assertStringContainsString('**Score drivers:** Each pillar scores on the density of its weighted findings per evaluated file', $process->getOutput());
        self::assertStringContainsString('Mutation uses the supplied Infection MSI as the mutation pillar score.', $process->getOutput());
        self::assertStringContainsString('**Mutation:** MSI 50.00%', $process->getOutput());
        self::assertStringContainsString('**Mutation statuses:** escaped=1, killed=2, timed out=1.', $process->getOutput());
    }

    /**
     * Verify analyse command reports mutation budget and msi regression.
     *
     * @throws JsonException - If decoding the command's JSON output fails.
     *
     * @return void - Asserts a baseline delta of -30, an exceeded budget, and both corresponding finding rule IDs.
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
        $mutation = $this->decodedJsonObjectAt($report, 'extensions', 'php', 'topLevel', 'mutation');
        $findings = $report['findings'] ?? null;

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
