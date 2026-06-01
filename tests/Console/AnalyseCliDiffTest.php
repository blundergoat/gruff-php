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
     * Extract symbol strings from JSON finding rows.
     *
     * @param list<array{symbol?: string|null}> $findings - Finding rows decoded from the CLI JSON report.
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
}
