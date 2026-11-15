<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers gruff.hook.v2 rule-selection and new-only identity edge cases.
 */
final class HookCliFilteringTest extends CliTestCase
{
    /**
     * Verify that an explicit hook rule replaces the configured pillar selection.
     * Retaining both selections would also run the configured pillar's rules.
     *
     * @return void - Asserts that only waste.empty-method findings remain.
     * @throws JsonException - When the hook output cannot be decoded as JSON.
     */
    public function testHookIncludeRuleNarrowsPastConfiguredPillar(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', <<<'YAML'
schemaVersion: gruff-php.config.v0.1
selection:
    pillars:
        - size
rules:
    size.file-length:
        threshold: 3
        severity: error
YAML);
            file_put_contents($tempDir . '/Example.php', $this->fileAndSymbolSource());

            [, $report] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--include-rule',
                'waste.empty-method',
                '--format',
                'json',
            ]);

            $ruleIds = $this->ruleIds($this->findingRows($report));
            self::assertNotContains('size.file-length', $ruleIds);
            self::assertSame(['waste.empty-method'], array_values(array_unique($ruleIds)));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify unknown hook rule filters return an in-band error instead of running zero rules.
     *
     * @return void - Asserts exit code 2, an unknown-rule schema error, and no findings for both filters.
     * @throws JsonException - When the hook output cannot be decoded as JSON.
     */
    public function testHookRejectsUnknownExecutionRuleFilters(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/Example.php', $this->fileAndSymbolSource());

            foreach (['--include-rule', '--exclude-rule'] as $option) {
                [$process, $report] = $this->runHook($tempDir, [
                    'hook',
                    'Example.php',
                    '--no-config',
                    $option,
                    'docs.missing-public-phpdox',
                    '--format',
                    'json',
                ]);

                self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

                $config = $report['config'] ?? null;
                self::assertIsArray($config, sprintf('Expected config payload for %s.', $option));
                self::assertFalse($config['schemaOk'] ?? true, sprintf('Expected schema failure for %s.', $option));
                self::assertSame(
                    sprintf('Unknown rule id "docs.missing-public-phpdox" for %s.', $option),
                    is_array($config['error'] ?? null) ? ($config['error']['message'] ?? null) : null,
                    sprintf('Expected unknown-rule message for %s.', $option),
                );
                self::assertSame([], $this->findingRows($report), sprintf('Expected no findings for %s.', $option));
            }
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify that a baseline covering one occurrence leaves one duplicate finding visible.
     * The duplicate findings share a stable identity, so baseline matching must preserve their counts.
     *
     * @return void - Asserts a shared identity, one remaining finding, and an applied baseline.
     * @throws JsonException - When the hook output cannot be decoded as JSON.
     */
    public function testHookDisambiguatesDuplicateLineFindingsForNewOnly(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', <<<'YAML'
schemaVersion: gruff-php.config.v0.1
selection:
    rules:
        - security.error-suppression
YAML);
            file_put_contents($tempDir . '/E.php', $this->singleSuppressionSource());

            [, $baselineReport] = $this->runHook($tempDir, [
                'hook',
                'E.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            $this->generateBaseline($tempDir, 'gruff-test.yaml', 'baseline.json');
            self::assertCount(1, $this->findingRows($baselineReport));

            file_put_contents($tempDir . '/E.php', $this->doubleSuppressionSource());
            [, $fullReport] = $this->runHook($tempDir, [
                'hook',
                'E.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            $fullRows = $this->findingRows($fullReport);
            self::assertCount(2, $fullRows);
            // Baseline v3 matches duplicates by count rather than by naming each one separately: two findings on one
            // declaration share an identity, and a baseline recording one occurrence leaves exactly one of them new.
            self::assertSame($fullRows[0]['stableIdentity'] ?? null, $fullRows[1]['stableIdentity'] ?? null);

            [, $filteredReport] = $this->runHook($tempDir, [
                'hook',
                'E.php',
                '--config',
                'gruff-test.yaml',
                '--baseline',
                'baseline.json',
                '--format',
                'json',
            ]);
            self::assertCount(1, $this->findingRows($filteredReport));
            // A baseline match is reviewed debt, not a changed-region drop, so the run block is what records it.
            self::assertTrue($this->runBaselineBlock($filteredReport)['applied'] ?? false);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Read the applied-baseline block a v2 hook payload reports, so a test can assert what classified the run.
     *
     * @param array<string, mixed> $report - Decoded hook report.
     *
     * @return array<string, mixed> - The run.baseline array; missing or non-array blocks fail assertions, and fields are not validated.
     */
    private function runBaselineBlock(array $report): array
    {
        $runAudit = $report['run'] ?? null;
        self::assertIsArray($runAudit);
        $baseline = $runAudit['baseline'] ?? null;
        self::assertIsArray($baseline);

        /** @var array<string, mixed> $baseline - Decoded JSON object, asserted as an array above. */
        return $baseline;
    }

    /**
     * Run the gruff CLI hook command.
     *
     * @param string       $cwd  - Directory in which the command resolves project files.
     * @param list<string> $args - CLI argv after the binary.
     *
     * @return array{0: Process, 1: array<string, mixed>} - Completed command process and its decoded JSON output.
     * @throws JsonException - When the command output cannot be decoded as JSON.
     */
    private function runHook(string $cwd, array $args): array
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $args), $cwd);
        $process->run();

        return [$process, $this->decodeJsonOutput($process)];
    }

    /**
     * Read the report's findings array after asserting only its outer array shape.
     *
     * @param array<string, mixed> $report - Hook payload whose findings field is asserted.
     *
     * @return list<array<string, mixed>> - Rows read from the report's findings field after its array assertion.
     */
    private function findingRows(array $report): array
    {
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);

        /** @var list<array<string, mixed>> $findings - Decoded JSON finding rows, asserted as an array above. */
        return $findings;
    }

    /**
     * Extract rule ids from finding rows.
     *
     * @param list<array<string, mixed>> $findings - Rows whose ruleId fields are extracted.
     *
     * @return list<string> - Rule IDs in row order, with empty strings for missing or non-string IDs.
     */
    private function ruleIds(array $findings): array
    {
        return array_map(
            static fn(array $finding): string => is_string($finding['ruleId'] ?? null) ? $finding['ruleId'] : '',
            $findings,
        );
    }

    /**
     * Generate a baseline through analyse so hook tests use the public baseline-writing path.
     *
     * @param string $cwd          - Project directory the baseline is generated in.
     * @param string $configFile   - Config the generating scan runs under, so the baseline covers the same rules.
     * @param string $baselineFile - Project-relative destination for the generated baseline.
     *
     * @return void - Asserts that the generating scan wrote the requested baseline file.
     */
    private function generateBaseline(string $cwd, string $configFile, string $baselineFile): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            '.',
            '--config',
            $configFile,
            '--generate-baseline',
            $baselineFile,
        ], $cwd);
        $process->run();

        // Findings can cause a nonzero exit after generation succeeds, so check the file directly.
        self::assertFileExists($cwd . '/' . $baselineFile, $process->getErrorOutput());
    }

    /**
     * PHP fixture with two empty methods, used to test configured pillar and explicit rule selection.
     *
     * @return string - Fixture text written to Example.php for the rule-selection tests.
     */
    private function fileAndSymbolSource(): string
    {
        return <<<'PHP'
<?php

final class Example
{
    public function ok(): void
    {
    }

    public function empty(): void
    {
    }
}
PHP;
    }

    /**
     * Source with one error-suppression operator.
     *
     * @return string - Fixture text written to E.php before generating the baseline.
     */
    private function singleSuppressionSource(): string
    {
        return <<<'PHP'
<?php

function reader(): void
{
    $first = @file_get_contents('a');
}
PHP;
    }

    /**
     * Source with two error-suppression operators sharing one message.
     *
     * @return string - Fixture text written to E.php after the single-finding baseline is recorded.
     */
    private function doubleSuppressionSource(): string
    {
        return <<<'PHP'
<?php

function reader(): void
{
    $first  = @file_get_contents('a');
    $second = @file_get_contents('b');
}
PHP;
    }
}
