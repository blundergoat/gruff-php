<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers gruff.hook.v1 rule-selection and new-only identity edge cases.
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
                    $config['error'] ?? null,
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

            [$baselineProcess, $baselineReport] = $this->runHook($tempDir, [
                'hook',
                'E.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            file_put_contents($tempDir . '/baseline.json', $baselineProcess->getOutput());
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
            self::assertNotSame($fullRows[0]['stableIdentity'] ?? null, $fullRows[1]['stableIdentity'] ?? null);

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
            self::assertSame(1, $this->suppressedCount($filteredReport));
        } finally {
            $this->removeDir($tempDir);
        }
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
     * Read the hook suppressed count.
     *
     * @param array<string, mixed> $report - Decoded hook report.
     *
     * @return int - Suppressed count.
     */
    private function suppressedCount(array $report): int
    {
        $suppressed = $report['suppressed'] ?? null;
        self::assertIsArray($suppressed);
        self::assertIsInt($suppressed['count'] ?? null);

        return $suppressed['count'];
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
