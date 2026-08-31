<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers analyse command trend history and baseline workflows.
 */
final class AnalyseCliBaselineTest extends CliTestCase
{
    /**
     * Verify analyse command writes trend history file.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testAnalyseCommandWritesTrendHistoryFile(): void
    {
        $tempDir     = $this->tempDir();
        $historyPath = $tempDir . '/history/gruff-history.json';

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--history-file',
                $historyPath,
                '--no-config',
            ], __DIR__ . '/../..');
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertFileExists($historyPath);

            $report = $this->decodeJsonOutput($process);
            $this->decodedJsonObjectAt($report, 'extensions', 'php', 'topLevel', 'trend');

            $decodedHistory = json_decode((string) file_get_contents($historyPath), true, 512, JSON_THROW_ON_ERROR);

            self::assertIsArray($decodedHistory);
            self::assertCount(1, $decodedHistory);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify changed-region runs record a diff-scope trend entry without a cross-scope delta.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testChangedRegionTrendRecordsDiffScopeWithoutCrossScopeDelta(): void
    {
        $project = $this->createBaselineProject();

        try {
            $fullRun    = $this->runInProject($project, ['analyse', 'src', '--format', 'json', '--fail-on', 'none', '--no-baseline', '--history-file', 'gruff-history.json']);
            $fullReport = $this->decodeJsonOutput($fullRun);
            $fullTrend  = $this->decodedJsonObjectAt($fullReport, 'extensions', 'php', 'topLevel', 'trend');
            self::assertSame('full-project', $fullTrend['scope'] ?? null);

            $diffRun    = $this->runInProject($project, ['analyse', '--changed-ranges', '1-5', 'src/OrderCalculator.php', '--format', 'json', '--fail-on', 'none', '--no-baseline', '--history-file', 'gruff-history.json']);
            $diffReport = $this->decodeJsonOutput($diffRun);
            $diffTrend  = $this->decodedJsonObjectAt($diffReport, 'extensions', 'php', 'topLevel', 'trend');
            // The diff-scoped score joins its own series: no delta against the full-project entry.
            self::assertSame('diff', $diffTrend['scope'] ?? null);
            self::assertArrayHasKey('previousScore', $diffTrend);
            self::assertNull($diffTrend['previousScore']);
            self::assertArrayHasKey('delta', $diffTrend);
            self::assertNull($diffTrend['delta']);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command generates and applies baseline.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testAnalyseCommandGeneratesAndAppliesBaseline(): void
    {
        $tempDir      = $this->tempDir();
        $baselinePath = $tempDir . '/gruff-baseline.json';

        try {
            $generateProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
                $baselinePath,
                '--no-config',
            ], __DIR__ . '/../..');
            $generateProcess->run();

            self::assertSame(0, $generateProcess->getExitCode(), $generateProcess->getErrorOutput());
            self::assertFileExists($baselinePath);

            $generatedReport   = $this->decodeJsonOutput($generateProcess);
            $generatedBaseline = $generatedReport['baseline'] ?? null;
            self::assertIsArray($generatedBaseline);
            self::assertSame(true, $generatedBaseline['generated'] ?? null);
            self::assertSame(1, $generatedBaseline['entries'] ?? null);

            $applyProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--baseline',
                $baselinePath,
                '--no-config',
            ], __DIR__ . '/../..');
            $applyProcess->run();

            self::assertSame(0, $applyProcess->getExitCode(), $applyProcess->getErrorOutput());
            $appliedReport   = $this->decodeJsonOutput($applyProcess);
            $appliedBaseline = $appliedReport['baseline'] ?? null;
            $summary         = $appliedReport['summary'] ?? null;
            self::assertIsArray($appliedBaseline);
            self::assertIsArray($summary);
            self::assertSame(1, $appliedBaseline['suppressedFindings'] ?? null);
            $findingCounts = $summary['findings'] ?? null;
            self::assertIsArray($findingCounts);
            self::assertSame(0, $findingCounts['total'] ?? null);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify analyse command rejects invalid baseline JSON.
     *
     * @return void
     */
    public function testAnalyseCommandRejectsInvalidBaselineJson(): void
    {
        $tempDir      = $this->tempDir();
        $baselinePath = $tempDir . '/broken-baseline.json';

        try {
            file_put_contents($baselinePath, '{"schemaVersion":"wrong","findings":[]}');

            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--fail-on',
                'none',
                '--baseline',
                $baselinePath,
            ], __DIR__ . '/../..');
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('[BASELINE-ERROR]', $process->getOutput());
            self::assertStringContainsString('Baseline schemaVersion must be "gruff.baseline.v2".', $process->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify a legacy v1 baseline fails closed with a regenerate instruction instead of parsing silently.
     *
     * @return void
     */
    public function testAnalyseCommandFailsClosedOnLegacyV1Baseline(): void
    {
        $tempDir      = $this->tempDir();
        $baselinePath = $tempDir . '/gruff-baseline.json';

        try {
            file_put_contents(
                $baselinePath,
                '{"schemaVersion":"gruff.baseline.v1","findings":[{"fingerprint":"0123456789abcdef","ruleId":"docs.example","file":"src/Example.php","line":1,"symbol":null,"message":"Example finding."}]}',
            );

            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--fail-on',
                'none',
                '--baseline',
                $baselinePath,
            ], __DIR__ . '/../..');
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('[BASELINE-ERROR]', $process->getOutput());
            self::assertStringContainsString('Baseline schema "gruff.baseline.v1" is no longer supported', $process->getOutput());
            self::assertStringContainsString('--generate-baseline', $process->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify analyse command writes and auto applies default baseline file.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testAnalyseCommandWritesAndAutoAppliesDefaultBaselineFile(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generateProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generateProcess->run();

            self::assertSame(0, $generateProcess->getExitCode(), $generateProcess->getErrorOutput());
            self::assertFileExists($project . '/gruff-baseline.json');

            $generatedReport   = $this->decodeJsonOutput($generateProcess);
            $generatedBaseline = $generatedReport['baseline'] ?? null;
            self::assertIsArray($generatedBaseline);
            self::assertSame('gruff-baseline.json', $generatedBaseline['path'] ?? null);
            self::assertSame(true, $generatedBaseline['generated'] ?? null);
            self::assertSame(1, $generatedBaseline['entries'] ?? null);
            self::assertSame('default', $generatedBaseline['source'] ?? null);

            $autoApplyProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $autoApplyProcess->run();

            self::assertSame(0, $autoApplyProcess->getExitCode(), $autoApplyProcess->getErrorOutput());
            $autoReport   = $this->decodeJsonOutput($autoApplyProcess);
            $autoBaseline = $autoReport['baseline'] ?? null;
            self::assertIsArray($autoBaseline);
            self::assertSame('gruff-baseline.json', $autoBaseline['path'] ?? null);
            self::assertSame(false, $autoBaseline['generated'] ?? null);
            self::assertSame(1, $autoBaseline['suppressedFindings'] ?? null);
            self::assertSame('default', $autoBaseline['source'] ?? null);
            $autoSummary = $autoReport['summary'] ?? null;
            self::assertIsArray($autoSummary);
            $autoCounts = $autoSummary['findings'] ?? null;
            self::assertIsArray($autoCounts);
            self::assertSame(0, $autoCounts['total'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command skips auto baseline with no baseline flag.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testAnalyseCommandSkipsAutoBaselineWithNoBaselineFlag(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generateProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generateProcess->run();
            self::assertSame(0, $generateProcess->getExitCode(), $generateProcess->getErrorOutput());

            $skippedProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--no-baseline',
            ], $project);
            $skippedProcess->run();

            self::assertSame(0, $skippedProcess->getExitCode(), $skippedProcess->getErrorOutput());
            $report = $this->decodeJsonOutput($skippedProcess);
            self::assertArrayNotHasKey('baseline', $report);
            $skippedSummary = $report['summary'] ?? null;
            self::assertIsArray($skippedSummary);
            $skippedCounts = $skippedSummary['findings'] ?? null;
            self::assertIsArray($skippedCounts);
            self::assertGreaterThan(0, $skippedCounts['total'] ?? 0);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command shows new findings after baseline generation.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testAnalyseCommandShowsNewFindingsAfterBaselineGeneration(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generateProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generateProcess->run();
            self::assertSame(0, $generateProcess->getExitCode(), $generateProcess->getErrorOutput());

            file_put_contents(
                $project . '/src/Newcomer.php',
                "<?php\n\ndeclare(strict_types=1);\n\nfinal readonly class Newcomer\n{\n    public function arrive(int \$x): int\n    {\n        return \$x;\n    }\n}\n",
            );

            $rerunProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $rerunProcess->run();

            self::assertSame(0, $rerunProcess->getExitCode(), $rerunProcess->getErrorOutput());
            $report   = $this->decodeJsonOutput($rerunProcess);
            $baseline = $report['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame(1, $baseline['suppressedFindings'] ?? null);

            $findings = $report['findings'] ?? null;
            self::assertIsArray($findings);
            $newFiles = array_values(array_filter(
                $findings,
                static fn (mixed $finding): bool => is_array($finding)
                    && ($finding['file'] ?? null) === 'src/Newcomer.php',
            ));
            self::assertNotSame([], $newFiles, 'A new finding introduced after baseline generation must still be reported.');
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command reports stale baseline entries.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testAnalyseCommandReportsStaleBaselineEntries(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generateProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generateProcess->run();
            self::assertSame(0, $generateProcess->getExitCode(), $generateProcess->getErrorOutput());

            file_put_contents(
                $project . '/src/OrderCalculator.php',
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixtures\\Source\\Code;\n\n/**\n * Documents the public surface so the docs.missing-public-phpdoc finding goes away.\n */\nfinal readonly class OrderCalculator\n{\n    /**\n     * Sum the subtotal and tax to produce the order total.\n     */\n    public function calculateTotal(int \$subtotal, int \$taxAmount): int\n    {\n        return \$subtotal + \$taxAmount;\n    }\n}\n",
            );

            $rerunProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $rerunProcess->run();

            self::assertSame(0, $rerunProcess->getExitCode(), $rerunProcess->getErrorOutput());
            $report   = $this->decodeJsonOutput($rerunProcess);
            $baseline = $report['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame('full-project', $baseline['staleEvaluation'] ?? null);
            self::assertSame(1, $baseline['staleEntries'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command rejects no baseline combined with baseline.
     *
     * @return void
     */
    public function testAnalyseCommandRejectsNoBaselineCombinedWithBaseline(): void
    {
        $project = $this->createBaselineProject();

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'text',
                '--fail-on',
                'none',
                '--no-baseline',
                '--baseline=gruff-baseline.json',
            ], $project);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('--no-baseline cannot be combined with --baseline.', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command rejects baseline combined with generate baseline.
     *
     * @return void
     */
    public function testAnalyseCommandRejectsBaselineCombinedWithGenerateBaseline(): void
    {
        $project = $this->createBaselineProject();

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'text',
                '--fail-on',
                'none',
                '--baseline=gruff-baseline.json',
                '--generate-baseline',
            ], $project);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('--baseline and --generate-baseline are mutually exclusive.', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify resolving a baselined finding reports it as a resolved bucket and lists it only with the flag.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testBaselineIncludeAbsentListsResolvedEntries(): void
    {
        $project = $this->createBaselineProject();

        try {
            $this->runInProject($project, ['analyse', 'src', '--format', 'json', '--fail-on', 'none', '--generate-baseline']);

            // Fully document the public surface and comment the return so every baselined finding resolves with nothing new.
            file_put_contents(
                $project . '/src/OrderCalculator.php',
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixtures\\Source\\Code;\n\n/**\n * Calculates order totals for baseline movement tests.\n */\nfinal readonly class OrderCalculator\n{\n    /**\n     * Sum the subtotal and tax to produce the order total.\n     *\n     * @param int \$subtotal  Order subtotal in minor units.\n     * @param int \$taxAmount Tax to add in minor units.\n     * @return int Combined order total.\n     */\n    public function calculateTotal(int \$subtotal, int \$taxAmount): int\n    {\n        // Order total is the subtotal plus tax, both already in minor units.\n        return \$subtotal + \$taxAmount;\n    }\n}\n",
            );

            $defaultRun = $this->runInProject($project, ['analyse', 'src', '--format', 'text', '--fail-on', 'none']);
            self::assertStringContainsString('Movement: 0 new, 0 unchanged, 1 resolved', $defaultRun->getOutput());
            self::assertStringNotContainsString('Resolved entries:', $defaultRun->getOutput());

            $textRun = $this->runInProject($project, ['analyse', 'src', '--format', 'text', '--fail-on', 'none', '--baseline-include-absent']);
            self::assertStringContainsString('Resolved entries:', $textRun->getOutput());
            self::assertStringContainsString('docs.missing-public-phpdoc', $textRun->getOutput());

            $markdownRun = $this->runInProject($project, ['analyse', 'src', '--format', 'markdown', '--fail-on', 'none', '--baseline-include-absent']);
            self::assertStringContainsString('**Baseline:** 0 new, 0 unchanged, 1 resolved', $markdownRun->getOutput());
            self::assertStringContainsString('<details><summary>Resolved baseline entries</summary>', $markdownRun->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify a new finding and a still-matching finding land in the new and unchanged buckets.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testBaselineMovementCountsNewAndUnchanged(): void
    {
        $project = $this->createBaselineProject();

        try {
            $this->runInProject($project, ['analyse', 'src', '--format', 'json', '--fail-on', 'none', '--generate-baseline']);

            file_put_contents(
                $project . '/src/Newcomer.php',
                "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Newcomer fixture introduced after baseline generation.\n */\nfinal readonly class Newcomer\n{\n    public function arrive(int \$amount): int\n    {\n        return \$amount;\n    }\n}\n",
            );

            $jsonRun  = $this->runInProject($project, ['analyse', 'src', '--format', 'json', '--fail-on', 'none']);
            $report   = $this->decodeJsonOutput($jsonRun);
            $baseline = $this->decodedJsonObjectAt($report, 'baseline');
            $buckets  = $this->decodedJsonObjectAt($baseline, 'extensions', 'php', 'baseline', 'buckets');
            self::assertSame(1, $buckets['unchanged'] ?? null);
            self::assertSame(0, $buckets['absent'] ?? null);
            self::assertGreaterThanOrEqual(1, $buckets['new'] ?? 0);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify a line inserted above a baselined finding leaves the accepted debt suppressed.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testLineShiftAboveBaselinedFindingKeepsItSuppressed(): void
    {
        $project = $this->createBaselineProject();

        try {
            $this->runInProject($project, ['analyse', 'src', '--format', 'json', '--fail-on', 'none', '--generate-baseline']);

            // Insert one line above the baselined finding so its line, endLine, and fingerprint all shift.
            $fixture = (string)file_get_contents($project . '/src/OrderCalculator.php');
            file_put_contents(
                $project . '/src/OrderCalculator.php',
                str_replace(
                    'declare(strict_types=1);',
                    "declare(strict_types=1);\n\n// Unrelated line inserted above the accepted finding.",
                    $fixture,
                ),
            );

            $rerun    = $this->runInProject($project, ['analyse', 'src', '--format', 'json', '--fail-on', 'none']);
            $report   = $this->decodeJsonOutput($rerun);
            $baseline = $this->decodedJsonObjectAt($report, 'baseline');
            $buckets  = $this->decodedJsonObjectAt($baseline, 'extensions', 'php', 'baseline', 'buckets');
            self::assertSame(0, $buckets['new'] ?? null);
            self::assertSame(1, $buckets['unchanged'] ?? null);
            self::assertSame(0, $buckets['absent'] ?? null);
            $summary = $report['summary'] ?? null;
            self::assertIsArray($summary);
            $counts = $summary['findings'] ?? null;
            self::assertIsArray($counts);
            self::assertSame(0, $counts['total'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify --fail-on-new trips on instances beyond a group's accepted count and passes within it.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testFailOnNewGatesOnGroupCountOverflow(): void
    {
        $project = $this->createBaselineProject();

        try {
            // Two anonymous classes sharing an undocumented method name emit two error findings
            // with identical (file, ruleId, message) identity, so they land in one baseline group.
            file_put_contents(
                $project . '/src/Handlers.php',
                "<?php\n\n\$first = new class {\n    public function handle(): int\n    {\n        return 1;\n    }\n};\n\n\$second = new class {\n    public function handle(): int\n    {\n        return 2;\n    }\n};\n",
            );
            $this->writeHandlerGroupBaseline($project, 1);

            $overflowRun = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src/Handlers.php',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--fail-on-new',
                '--baseline',
                'gruff-baseline.json',
                '--include-rule',
                'docs.missing-public-phpdoc',
            ], $project);
            $overflowRun->run();

            self::assertSame(1, $overflowRun->getExitCode(), $overflowRun->getErrorOutput());
            $overflowReport   = $this->decodeJsonOutput($overflowRun);
            $overflowBaseline = $this->decodedJsonObjectAt($overflowReport, 'baseline');
            self::assertSame(1, $overflowBaseline['newFindings'] ?? null);

            // Accepting both instances in the group must clear the gate.
            $this->writeHandlerGroupBaseline($project, 2);
            $withinBudgetRun = $this->runInProject($project, [
                'analyse',
                'src/Handlers.php',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--fail-on-new',
                '--baseline',
                'gruff-baseline.json',
                '--include-rule',
                'docs.missing-public-phpdoc',
            ]);
            $withinBudgetReport   = $this->decodeJsonOutput($withinBudgetRun);
            $withinBudgetBaseline = $this->decodedJsonObjectAt($withinBudgetReport, 'baseline');
            self::assertSame(0, $withinBudgetBaseline['newFindings'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Write a one-group v2 baseline accepting the anonymous-class handler findings.
     *
     * @param string $project       - Fixture project root the baseline is written into.
     * @param int    $acceptedCount - Accepted instance count for the handler group.
     *
     * @return void
     */
    private function writeHandlerGroupBaseline(string $project, int $acceptedCount): void
    {
        file_put_contents(
            $project . '/gruff-baseline.json',
            sprintf(
                '{"schemaVersion":"gruff.baseline.v2","groups":[{"file":"src/Handlers.php","ruleId":"docs.missing-public-phpdoc","message":"Method class@anonymous::handle() needs a brief intent description above its declaration (one plain-English line; not a restatement of the method signature).","count":%d}]}',
                $acceptedCount,
            ),
        );
    }

    /**
     * Verify changed-only runs do not mark unscanned baseline entries absent.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testChangedOnlyBaselineDoesNotMarkUnscannedEntriesAbsent(): void
    {
        $project = $this->createBaselineProject();

        try {
            file_put_contents(
                $project . '/src/Changed.php',
                "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Clean file used for changed-only baseline scope.\n */\nfinal readonly class Changed\n{\n    /**\n     * Return the input amount.\n     *\n     * @param int \$amount Input amount.\n     * @return int Same amount.\n     */\n    public function amount(int \$amount): int\n    {\n        return \$amount;\n    }\n}\n",
            );
            $this->runInProject($project, ['analyse', 'src', '--format', 'json', '--fail-on', 'none', '--generate-baseline']);
            $this->gitInProject($project, ['init', '-q']);
            $this->gitInProject($project, ['add', 'README.md', 'src/OrderCalculator.php', 'src/Changed.php', 'gruff-baseline.json']);
            $this->gitInProject($project, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', 'base']);

            file_put_contents(
                $project . '/src/Changed.php',
                "<?php\n\ndeclare(strict_types=1);\n\n/**\n * File changed after baseline generation.\n */\nfinal readonly class Changed\n{\n    public function amount(int \$amount): int\n    {\n        return \$amount;\n    }\n}\n",
            );

            $jsonRun  = $this->runInProject($project, ['analyse', 'src', '--format', 'json', '--fail-on', 'none', '--diff-vs', 'HEAD', '--changed-only']);
            $report   = $this->decodeJsonOutput($jsonRun);
            $baseline = $this->decodedJsonObjectAt($report, 'baseline');
            self::assertSame('not-evaluated-diff-scope', $baseline['staleEvaluation'] ?? null);
            $buckets = $this->decodedJsonObjectAt($baseline, 'extensions', 'php', 'baseline', 'buckets');
            self::assertSame(0, $buckets['absent'] ?? null);
            self::assertGreaterThanOrEqual(1, $buckets['new'] ?? 0);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Run the analyse CLI inside a project directory and return the finished process.
     *
     * @param string       $project - Working directory the binary runs in, so relative paths resolve against it.
     * @param list<string> $args    - CLI arguments passed after the binary.
     *
     * @return Process - Completed analyse process.
     */
    private function runInProject(string $project, array $args): Process
    {
        $process = new Process(array_merge([PHP_BINARY, __DIR__ . '/../../bin/gruff-php'], $args), $project);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $process;
    }

    /**
     * Run git inside a fixture project.
     *
     * @param string       $project - Fixture repository root.
     * @param list<string> $args    - Git arguments.
     *
     * @return void
     */
    private function gitInProject(string $project, array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $project);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
