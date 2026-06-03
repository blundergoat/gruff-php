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
