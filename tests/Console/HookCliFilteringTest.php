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
     * Verify --include-rule narrows to exactly the named rule even when the config selects a pillar.
     *
     * Regression: preserving the config's tier/pillar includes alongside the hook --include-rule made
     * RuleSelection::allows() OR them in, so a focused `--include-rule X` also ran the configured pillar.
     *
     * @return void
     * @throws JsonException
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
     * @return void
     * @throws JsonException
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
     * Verify repeated same-rule line findings get distinct identities so a new duplicate still surfaces.
     *
     * Regression: line-scoped identities omit the line, so two symbol-less same-message findings (e.g.
     * security.error-suppression) collapsed to one stableIdentity, letting a baseline holding one
     * suppress a newly added duplicate elsewhere in the same file.
     *
     * @return void
     * @throws JsonException
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
     * @return array<string, mixed> - The run block's baseline map: applied, schemaVersion, and path.
     */
    private function runBaselineBlock(array $report): array
    {
        $runAudit = $report['run'] ?? null;
        self::assertIsArray($runAudit);
        $baseline = $runAudit['baseline'] ?? null;
        self::assertIsArray($baseline);

        /** @var array<string, mixed> $baseline Decoded JSON object, asserted as an array above. */
        return $baseline;
    }

    /**
     * Run the gruff CLI hook command.
     *
     * @param string       $cwd  - Working directory.
     * @param list<string> $args - CLI argv after the binary.
     *
     * @return array{0: Process, 1: array<string, mixed>} - Process and decoded JSON.
     * @throws JsonException
     */
    private function runHook(string $cwd, array $args): array
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $args), $cwd);
        $process->run();

        return [$process, $this->decodeJsonOutput($process)];
    }

    /**
     * Return decoded finding rows after asserting the payload shape.
     *
     * @param array<string, mixed> $report - Decoded hook report.
     *
     * @return list<array<string, mixed>> - Finding rows.
     */
    private function findingRows(array $report): array
    {
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);

        /** @var list<array<string, mixed>> $findings Decoded JSON finding rows, asserted as an array above. */
        return $findings;
    }

    /**
     * Extract rule ids from finding rows.
     *
     * @param list<array<string, mixed>> $findings - Finding rows.
     *
     * @return list<string> - Rule ids in finding order.
     */
    private function ruleIds(array $findings): array
    {
        return array_map(
            static fn(array $finding): string => is_string($finding['ruleId'] ?? null) ? $finding['ruleId'] : '',
            $findings,
        );
    }

    /**
     * Write a ratified baseline v3 file the hook can apply, the same file `analyse --generate-baseline` gives a user.
     *
     * @param string $cwd          - Project directory the baseline is generated in.
     * @param string $configFile   - Config the generating scan runs under, so the baseline covers the same rules.
     * @param string $baselineFile - Project-relative destination for the generated baseline.
     *
     * @return void
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

        // A generating scan still reports its findings, so its exit code says nothing about whether the file was written.
        self::assertFileExists($cwd . '/' . $baselineFile, $process->getErrorOutput());
    }

    /**
     * Source that yields one file-length finding and two empty-method findings.
     *
     * @return string - PHP source.
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
     * @return string - PHP source.
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
     * @return string - PHP source.
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
