<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers analyse CLI diff-scope modes.
 */
final class AnalyseCliDiffTest extends CliTestCase
{
    /**
     * Verify analyse command reports non Git diff mode clearly.
     *
     * @return void
     */
    public function testAnalyseCommandReportsNonGitDiffModeClearly(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function run(): void {}\n}\n");

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       '.',
                                       '--diff',
                                       'unstaged',
                                       '--fail-on',
                                       'none',
                                   ], $tempDir);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('[DIFF-MODE-ERROR]', $process->getOutput());
            self::assertStringContainsString('Diff mode requires a git working tree.', $process->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify changed-ranges mode reports only findings attributable to the changed symbol.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandChangedRangesUsesSymbolScopeAndSuppressedCount(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/Example.php', $this->changedRegionSource());

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       'Example.php',
                                       '--changed-ranges',
                                       '11-11',
                                       '--format',
                                       'json',
                                       '--fail-on',
                                       'none',
                                       '--no-config',
                                   ], $tempDir);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            $report   = $this->decodeJsonOutput($process);
            $findings = $report['findings'] ?? null;

            self::assertIsArray($findings);
            /** @var list<array{symbol?: string|null}> $findings decoded finding rows used for symbol extraction */
            self::assertGreaterThanOrEqual(1, $report['suppressedCount'] ?? null);
            self::assertContains('Example::changed()', $this->symbolsFromJsonFindings($findings));
            self::assertNotContains('Example::unchanged()', $this->symbolsFromJsonFindings($findings));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify unified diff stdin mode feeds the same changed-region filter.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandDiffStdinParsesUnifiedDiff(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/Example.php', $this->changedRegionSource());

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       'Example.php',
                                       '--diff',
                                       '-',
                                       '--format',
                                       'json',
                                       '--fail-on',
                                       'none',
                                       '--no-config',
                                   ], $tempDir);
            $process->setInput(<<<'PATCH'
diff --git a/Example.php b/Example.php
--- a/Example.php
+++ b/Example.php
@@ -10,0 +11,1 @@
+        echo 'new';
PATCH
            );
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            $report = $this->decodeJsonOutput($process);
            $diff   = $report['diff'] ?? null;

            self::assertIsArray($diff);
            self::assertSame('stdin', $diff['mode'] ?? null);
            self::assertGreaterThanOrEqual(1, $report['suppressedCount'] ?? null);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify changed-ranges accounting reconciles with full-file intra-file findings.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandChangedRangesReconcilesIntraFileFindings(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->ruleSelectionConfig(['waste.empty-method']));
            file_put_contents($tempDir . '/Example.php', $this->multiMethodIntraFileSource());

            $fullReport = $this->runJsonAnalyse($tempDir, [
                'analyse',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--no-baseline',
                '--format',
                'json',
                '--fail-on',
                'none',
            ]);
            $fullFindings = $this->findingRows($fullReport);
            self::assertCount(2, $fullFindings);

            $scopedReport = $this->runJsonAnalyse($tempDir, [
                'analyse',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--no-baseline',
                '--changed-ranges',
                '5-5',
                '--changed-scope',
                'symbol',
                '--format',
                'json',
                '--fail-on',
                'none',
            ]);

            $findings = $this->findingRows($scopedReport);
            self::assertCount(1, $findings);
            self::assertSame(1, $this->suppressedCount($scopedReport));
            self::assertSame(1, $this->diffSuppressedCount($scopedReport));
            self::assertSame(
                count($fullFindings),
                count($findings) + $this->suppressedCount($scopedReport),
            );
            self::assertSame('Example::first()', $findings[0]['symbol'] ?? null);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify project-wide rule findings in the changed file surface or count, while other project findings stay out of the file-scoped total.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandChangedRangesReconcilesProjectWideFindings(): void
    {
        $tempDir = $this->tempDir();

        try {
            $this->writeProjectWideChangedRegionFixture($tempDir);

            $fullReport = $this->runJsonAnalyse($tempDir, [
                'analyse',
                'src/ChangedUnused.php',
                '--config',
                'gruff-test.yaml',
                '--no-baseline',
                '--format',
                'json',
                '--fail-on',
                'none',
            ]);
            $fullFindings = $this->findingRows($fullReport);
            self::assertSame(['App\\ChangedUnused'], $this->symbolsFromJsonFindings($fullFindings));

            $inRangeReport = $this->runJsonAnalyse($tempDir, [
                'analyse',
                'src/ChangedUnused.php',
                '--config',
                'gruff-test.yaml',
                '--no-baseline',
                '--changed-ranges',
                '5-5',
                '--changed-scope',
                'symbol',
                '--format',
                'json',
                '--fail-on',
                'none',
            ]);
            self::assertSame(['App\\ChangedUnused'], $this->symbolsFromJsonFindings($this->findingRows($inRangeReport)));
            self::assertSame(0, $this->suppressedCount($inRangeReport));
            self::assertSame(0, $this->diffSuppressedCount($inRangeReport));

            $outOfRangeReport = $this->runJsonAnalyse($tempDir, [
                'analyse',
                'src/ChangedUnused.php',
                '--config',
                'gruff-test.yaml',
                '--no-baseline',
                '--changed-ranges',
                '2-2',
                '--changed-scope',
                'symbol',
                '--format',
                'json',
                '--fail-on',
                'none',
            ]);
            $outOfRangeFindings = $this->findingRows($outOfRangeReport);
            self::assertSame([], $outOfRangeFindings);
            self::assertSame(1, $this->suppressedCount($outOfRangeReport));
            self::assertSame(1, $this->diffSuppressedCount($outOfRangeReport));
            self::assertSame(
                count($fullFindings),
                count($outOfRangeFindings) + $this->suppressedCount($outOfRangeReport),
            );
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify symbol scope treats file and class aggregate findings as anchor-local, while method findings still follow their changed symbol.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandChangedRangesSymbolScopeSuppressesAggregateFindingsAwayFromAnchors(): void
    {
        $tempDir = $this->tempDir();

        try {
            $this->writeSizeAggregateFixture($tempDir);

            $report = $this->runChangedScopeAnalyse($tempDir, '30-30', 'symbol');

            $ruleIds            = $this->ruleIdsFromJsonFindings($this->findingRows($report));
            $expectedSuppressed = 5;

            self::assertSame(['size.method-length', 'size.parameter-count'], $ruleIds);
            self::assertSame($expectedSuppressed, $this->suppressedCount($report));
            self::assertSame($expectedSuppressed, $this->diffSuppressedCount($report));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify aggregate findings survive symbol scope when the edit touches their representative anchor.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandChangedRangesSymbolScopeKeepsAggregateFindingOnAnchorEdit(): void
    {
        $tempDir = $this->tempDir();

        try {
            $this->writeSizeAggregateFixture($tempDir);

            $fileAnchorReport = $this->runChangedScopeAnalyse($tempDir, '1-1', 'symbol');
            self::assertSame(['size.file-length'], $this->ruleIdsFromJsonFindings($this->findingRows($fileAnchorReport)));

            $classAnchorReport = $this->runChangedScopeAnalyse($tempDir, '7-7', 'symbol');
            self::assertSame(
                [
                    'size.average-method-length',
                    'size.class-length',
                    'size.property-count',
                    'size.public-method-count',
                ],
                $this->ruleIdsFromJsonFindings($this->findingRows($classAnchorReport)),
            );
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify file scope preserves the previous changed-file aggregate span signal for CI review workflows.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandChangedRangesFileScopeKeepsAggregateSpanFindings(): void
    {
        $tempDir = $this->tempDir();

        try {
            $this->writeSizeAggregateFixture($tempDir);

            $report = $this->runChangedScopeAnalyse($tempDir, '30-30', 'file');

            self::assertSame(
                [
                    'size.file-length',
                    'size.average-method-length',
                    'size.class-length',
                    'size.property-count',
                    'size.public-method-count',
                    'size.method-length',
                    'size.parameter-count',
                ],
                $this->ruleIdsFromJsonFindings($this->findingRows($report)),
            );
            self::assertSame(0, $this->suppressedCount($report));
            self::assertSame(0, $this->diffSuppressedCount($report));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify full scans still report whole-file findings when changed-region filtering is inactive.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandFullScanStillReportsFileLengthFinding(): void
    {
        $tempDir = $this->tempDir();

        try {
            $this->writeFileLengthFixture($tempDir);

            $report = $this->runJsonAnalyse($tempDir, [
                'analyse',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--no-baseline',
                '--format',
                'json',
                '--fail-on',
                'none',
            ]);

            self::assertSame(['size.file-length'], $this->ruleIdsFromJsonFindings($this->findingRows($report)));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify file-aggregate TODO density uses its first-marker anchor rather than the enclosing class span.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandChangedRangesSymbolScopeUsesTodoDensityAnchor(): void
    {
        $tempDir = $this->tempDir();

        try {
            $this->writeTodoDensityFixture($tempDir);

            $outOfAnchorReport = $this->runChangedScopeAnalyse($tempDir, '12-12', 'symbol');
            self::assertSame([], $this->findingRows($outOfAnchorReport));
            self::assertSame(1, $this->suppressedCount($outOfAnchorReport));
            self::assertSame(1, $this->diffSuppressedCount($outOfAnchorReport));

            $anchorReport = $this->runChangedScopeAnalyse($tempDir, '9-9', 'symbol');
            self::assertSame(['docs.todo-density'], $this->ruleIdsFromJsonFindings($this->findingRows($anchorReport)));
            self::assertSame(0, $this->suppressedCount($anchorReport));

            $fileScopeReport = $this->runChangedScopeAnalyse($tempDir, '12-12', 'file');
            self::assertSame(['docs.todo-density'], $this->ruleIdsFromJsonFindings($this->findingRows($fileScopeReport)));
            self::assertSame(0, $this->suppressedCount($fileScopeReport));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Extract symbol strings from JSON finding rows.
     *
     * @param list<array<string, mixed>> $findings - Finding rows decoded from the CLI JSON report.
     *
     * @return list<string> - symbol names of findings that carry one, in finding order; entries without a string symbol are omitted
     */
    private function symbolsFromJsonFindings(array $findings): array
    {
        $symbols = [];

        foreach ($findings as $finding) {
            if (is_string($finding['symbol'] ?? null)) {
                $symbols[] = $finding['symbol'];
            }
        }

        return $symbols;
    }

    /**
     * Extract rule ids from JSON finding rows.
     *
     * @param list<array<string, mixed>> $findings - Finding rows decoded from the CLI JSON report.
     *
     * @return list<string> - rule ids in finding order
     */
    private function ruleIdsFromJsonFindings(array $findings): array
    {
        $ruleIds = [];

        foreach ($findings as $finding) {
            if (is_string($finding['ruleId'] ?? null)) {
                $ruleIds[] = $finding['ruleId'];
            }
        }

        return $ruleIds;
    }

    /**
     * Return decoded finding rows after narrowing their mixed JSON type.
     *
     * @param array<string, mixed> $report - Decoded JSON report.
     *
     * @return list<array<string, mixed>> - Finding rows.
     */
    private function findingRows(array $report): array
    {
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);

        $rows = [];
        foreach ($findings as $finding) {
            self::assertIsArray($finding);
            $findingRow = [];
            foreach ($finding as $key => $value) {
                if (is_string($key)) {
                    $findingRow[$key] = $value;
                }
            }

            $rows[] = $findingRow;
        }

        return $rows;
    }

    /**
     * Return the top-level changed-region suppression count from a decoded report.
     *
     * @param array<string, mixed> $report - Decoded JSON report.
     *
     * @return int - Top-level suppressedCount value.
     */
    private function suppressedCount(array $report): int
    {
        $suppressedCount = $report['suppressedCount'] ?? null;
        self::assertIsInt($suppressedCount);

        return $suppressedCount;
    }

    /**
     * Return the diff-local changed-region suppression count from a decoded report.
     *
     * @param array<string, mixed> $report - Decoded JSON report.
     *
     * @return int - diff.suppressedCount value.
     */
    private function diffSuppressedCount(array $report): int
    {
        $diff = $report['diff'] ?? null;
        self::assertIsArray($diff);

        $suppressedCount = $diff['suppressedCount'] ?? null;
        self::assertIsInt($suppressedCount);

        return $suppressedCount;
    }

    /**
     * Run gruff in a fixture project and decode its JSON report.
     *
     * @param string       $workingDirectory - Project root to run the command in.
     * @param list<string> $arguments - CLI arguments after the PHP binary and bin path.
     *
     * @return array<string, mixed> - Decoded JSON report.
     * @throws JsonException
     */
    private function runJsonAnalyse(string $workingDirectory, array $arguments): array
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $arguments), $workingDirectory);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $this->decodeJsonOutput($process);
    }

    /**
     * Run a changed-region analyse over the fixture's Example.php and decode the report.
     *
     * @param string $workingDirectory - Project root to run the command in.
     * @param string $ranges           - Value passed to --changed-ranges.
     * @param string $scope            - Value passed to --changed-scope.
     *
     * @return array<string, mixed> - Decoded JSON report.
     * @throws JsonException
     */
    private function runChangedScopeAnalyse(string $workingDirectory, string $ranges, string $scope): array
    {
        return $this->runJsonAnalyse($workingDirectory, [
            'analyse',
            'Example.php',
            '--config',
            'gruff-test.yaml',
            '--no-baseline',
            '--changed-ranges',
            $ranges,
            '--changed-scope',
            $scope,
            '--format',
            'json',
            '--fail-on',
            'none',
        ]);
    }

    /**
     * Build a minimal config selecting only the rules under test.
     *
     * @param list<string> $ruleIds - Rule ids to include.
     *
     * @return string - YAML config content.
     */
    private function ruleSelectionConfig(array $ruleIds): string
    {
        $lines = [
            'schemaVersion: gruff-php.config.v0.1',
            'selection:',
            '    rules:',
        ];

        foreach ($ruleIds as $ruleId) {
            $lines[] = '        - ' . $ruleId;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Create a tiny project whose project-wide dead-code findings include one changed-file and one other-file symbol.
     *
     * @param string $projectRoot - Temporary project root to populate.
     *
     * @return void
     */
    private function writeProjectWideChangedRegionFixture(string $projectRoot): void
    {
        self::assertTrue(mkdir($projectRoot . '/src', 0777, true));
        file_put_contents($projectRoot . '/composer.json', "{\"autoload\":{\"psr-4\":{\"App\\\\\":\"src/\"}}}\n");
        file_put_contents($projectRoot . '/gruff-test.yaml', $this->ruleSelectionConfig(['dead-code.unused-internal-class']));
        file_put_contents($projectRoot . '/src/ChangedUnused.php', $this->changedUnusedProjectSource());
        file_put_contents($projectRoot . '/src/OtherUnused.php', "<?php\n\nnamespace App;\n\nfinal class OtherUnused\n{\n}\n");
        file_put_contents($projectRoot . '/src/Used.php', "<?php\n\nnamespace App;\n\nfinal class Used\n{\n}\n");
        file_put_contents($projectRoot . '/src/references.php', "<?php\n\nnamespace App;\n\nnew Used();\n");
    }

    /**
     * Create a size-rule fixture with only aggregate and method-size rules enabled.
     *
     * @param string $projectRoot - Temporary project root to populate.
     *
     * @return void
     */
    private function writeSizeAggregateFixture(string $projectRoot): void
    {
        $source = file_get_contents(self::PROJECT_ROOT . '/tests/Fixtures/Size/cumulative-violations.php');
        self::assertIsString($source);

        file_put_contents($projectRoot . '/Example.php', $source);
        file_put_contents($projectRoot . '/gruff-test.yaml', <<<'YAML'
schemaVersion: gruff-php.config.v0.1
selection:
    rules:
        - size.file-length
        - size.average-method-length
        - size.class-length
        - size.property-count
        - size.public-method-count
        - size.method-length
        - size.parameter-count
rules:
    size.file-length:
        threshold: 5
        severity: warning
    size.method-length:
        threshold: 3
        severity: warning
    size.class-length:
        threshold: 5
        severity: warning
    size.parameter-count:
        threshold: 2
        severity: warning
    size.public-method-count:
        threshold: 3
        severity: warning
    size.property-count:
        threshold: 3
        severity: warning
    size.average-method-length:
        threshold: 2
        severity: warning
YAML);
    }

    /**
     * Create a file-length-only fixture for full-scan assertions.
     *
     * @param string $projectRoot - Temporary project root to populate.
     *
     * @return void
     */
    private function writeFileLengthFixture(string $projectRoot): void
    {
        $source = file_get_contents(self::PROJECT_ROOT . '/tests/Fixtures/Size/cumulative-violations.php');
        self::assertIsString($source);

        file_put_contents($projectRoot . '/Example.php', $source);
        file_put_contents($projectRoot . '/gruff-test.yaml', <<<'YAML'
schemaVersion: gruff-php.config.v0.1
selection:
    rules:
        - size.file-length
rules:
    size.file-length:
        threshold: 5
        severity: warning
YAML);
    }

    /**
     * Create a TODO-density-only fixture for aggregate anchor assertions.
     *
     * @param string $projectRoot - Temporary project root to populate.
     *
     * @return void
     */
    private function writeTodoDensityFixture(string $projectRoot): void
    {
        $source = file_get_contents(self::PROJECT_ROOT . '/tests/Fixtures/Docs/todo-density.php');
        self::assertIsString($source);

        file_put_contents($projectRoot . '/Example.php', $source);
        file_put_contents($projectRoot . '/gruff-test.yaml', <<<'YAML'
schemaVersion: gruff-php.config.v0.1
selection:
    rules:
        - docs.todo-density
YAML);
    }

    /**
     * Build PHP source with one edited and one untouched method.
     *
     * @return string - PHP source with one edited and one untouched method, so diff-mode tests can target a changed region
     */
    private function changedRegionSource(): string
    {
        return <<<'PHP'
<?php
final class Example
{
    public function unchanged(): void
    {
        echo 'old';
    }

    public function changed(): void
    {
        echo 'new';
    }
}
PHP;
    }

    /**
     * Build PHP source with two empty methods in different symbols.
     *
     * @return string - Source whose empty methods produce one finding each.
     */
    private function multiMethodIntraFileSource(): string
    {
        return <<<'PHP'
<?php
final class Example
{
    public function first(): void
    {
    }

    public function second(): void
    {
    }
}
PHP;
    }

    /**
     * Build the changed project-wide dead-code fixture file.
     *
     * @return string - Source with an unused class-like declaration anchored on line 5.
     */
    private function changedUnusedProjectSource(): string
    {
        return <<<'PHP'
<?php

namespace App;

final class ChangedUnused
{
}
PHP;
    }
}
