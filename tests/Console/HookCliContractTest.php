<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers the gruff.hook.v1 agent-hook CLI contract.
 */
final class HookCliContractTest extends CliTestCase
{
    /**
     * Verify hook capabilities advertise the cross-analyzer contract.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookCapabilitiesAdvertiseContract(): void
    {
        [$process, $report] = $this->runHook(self::PROJECT_ROOT, ['hook', '--capabilities', '--format', 'json']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('gruff.hook.v1', $report['contractVersion'] ?? null);
        self::assertSame('any', $report['flagOrder'] ?? null);

        $flags = $report['flags'] ?? null;
        self::assertIsArray($flags);
        self::assertSame('--changed-ranges', $flags['changedRanges'] ?? null);
        self::assertSame('--diff', $flags['diff'] ?? null);
        self::assertSame('--baseline', $flags['baseline'] ?? null);
        self::assertSame('--deep-scan-budget', $flags['deepScanBudget'] ?? null);

        $supports = $report['supports'] ?? null;
        self::assertIsArray($supports);
        foreach (['changedRanges', 'diff', 'baseline', 'scopeField', 'metadata', 'stableIdentity', 'ignoreReport', 'newOnly', 'deepScanBudget'] as $capability) {
            self::assertTrue($supports[$capability] ?? false, $capability);
        }
    }

    /** Verify hook JSON keeps the bounded-scan diagnostic and sensitive-data findings. */
    public function testHookReportsBoundedDeepScanWithoutInvalidatingRun(): void
    {
        [$process, $report] = $this->runHook(self::PROJECT_ROOT, [
            'hook',
            '--no-config',
            '--deep-scan-budget',
            '1:1',
            '--include-rule',
            'sensitive-data.aws-access-key',
            '--format',
            'json',
            'tests/Fixtures/SensitiveData/synthetic-secrets.php',
        ]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertNotNull($this->firstFindingByRule($this->findingRows($report), 'sensitive-data.aws-access-key'));
        $diagnostics = $report['diagnostics'] ?? null;
        self::assertIsArray($diagnostics);
        $diagnostic = $diagnostics[0] ?? null;
        self::assertIsArray($diagnostic);
        self::assertSame('bounded-deep-scan', $diagnostic['type'] ?? null);
        self::assertSame(false, $diagnostic['invalidatesRun'] ?? null);
    }

    /**
     * Verify full scan keeps file findings but changed-region hook output suppresses them with no anchor residual.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookChangedRangesSuppressesFileScopeFindingsWithoutAnchorResidual(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->focusedConfig(5));
            file_put_contents($tempDir . '/Example.php', $this->fileAndSymbolSource());

            [, $fullReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);

            $fullFindings = $this->findingRows($fullReport);
            $fileLength   = $this->firstFindingByRule($fullFindings, 'size.file-length');
            self::assertNotNull($fileLength);
            self::assertSame('file', $fileLength['scope'] ?? null);
            self::assertNotNull($this->firstFindingByRule($fullFindings, 'waste.empty-method'));

            [, $changedReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--changed-ranges',
                '9-9',
                '--format',
                'json',
            ]);
            $changedFindings = $this->findingRows($changedReport);
            self::assertNull($this->firstFindingByRule($changedFindings, 'size.file-length'));
            $expectedSuppressedCount = 2;
            self::assertSame($expectedSuppressedCount, $this->suppressedCount($changedReport));
            self::assertSame(['Example::empty()'], $this->symbols($changedFindings));

            [, $anchorReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--changed-ranges',
                '1-1',
                '--format',
                'json',
            ]);
            self::assertNull($this->firstFindingByRule($this->findingRows($anchorReport), 'size.file-length'));
            self::assertGreaterThanOrEqual(1, $this->suppressedCount($anchorReport));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify hook findings carry scope, remediation, stable identity, and normalized threshold metadata.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookFindingShapeIncludesScopeRemediationStableIdentityAndThresholdMetadata(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->focusedConfig(5));
            file_put_contents($tempDir . '/Example.php', $this->fileAndSymbolSource());

            [, $report] = $this->runHook($tempDir, [
                'hook',
                '--format',
                'json',
                '--config',
                'gruff-test.yaml',
                'Example.php',
            ]);

            self::assertSame('gruff.hook.v1', $report['contractVersion'] ?? null);
            self::assertSame(0, $this->suppressedCount($report));

            $fileLength = $this->firstFindingByRule($this->findingRows($report), 'size.file-length');
            self::assertNotNull($fileLength);
            self::assertSame('size', $fileLength['pillar'] ?? null);
            self::assertSame('error', $fileLength['severity'] ?? null);
            self::assertSame('file', $fileLength['scope'] ?? null);
            self::assertIsString($fileLength['remediation'] ?? null);
            self::assertNotSame('', $fileLength['remediation']);
            self::assertIsString($fileLength['stableIdentity'] ?? null);
            self::assertIsString($fileLength['fingerprint'] ?? null);

            $metadata = $fileLength['metadata'] ?? null;
            self::assertIsArray($metadata);
            // Substantive count: the fixture's blank and comment-only lines are free under file-length.
            $expectedMeasuredLines = 10;
            self::assertSame($expectedMeasuredLines, $metadata['measured'] ?? null);
            self::assertSame(5, $metadata['threshold'] ?? null);
            self::assertSame('lines', $metadata['unit'] ?? null);
            self::assertSame('above', $metadata['direction'] ?? null);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify hook findings transport canonical remediation action metadata unchanged.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookTransportsRemediationActionMetadata(): void
    {
        [$process, $report] = $this->runHook(self::PROJECT_ROOT, [
            'hook', 'tests/Fixtures/Naming/abbreviation-allowlist.php', '--no-config',
            '--include-rule', 'naming.abbreviation-allowlist', '--format', 'json',
        ]);
        $finding  = $this->firstFindingByRule($this->findingRows($report), 'naming.abbreviation-allowlist');
        $metadata = $finding['metadata'] ?? null;

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertNotNull($finding);
        self::assertIsArray($metadata);
        self::assertSame('CONSIDER', $metadata['remediationAction'] ?? null);
        self::assertSame('allowlists.acceptedAbbreviations', $metadata['configurationKey'] ?? null);
    }

    /**
     * Verify hook new-only matching is stable across measured-value changes but reports newly crossing findings.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookBaselineUsesStableIdentityIndependentOfMeasuredValues(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->focusedConfig(5, false));
            file_put_contents($tempDir . '/Example.php', $this->oversizedSource(7));

            [$baselineProcess, $baselineReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            file_put_contents($tempDir . '/baseline.json', $baselineProcess->getOutput());

            $baselineFinding = $this->firstFindingByRule($this->findingRows($baselineReport), 'size.file-length');
            self::assertNotNull($baselineFinding);

            file_put_contents($tempDir . '/Example.php', $this->oversizedSource(9));

            [, $changedFullReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            $changedFinding = $this->firstFindingByRule($this->findingRows($changedFullReport), 'size.file-length');
            self::assertNotNull($changedFinding);
            self::assertSame($baselineFinding['stableIdentity'] ?? null, $changedFinding['stableIdentity'] ?? null);
            self::assertNotSame($baselineFinding['fingerprint'] ?? null, $changedFinding['fingerprint'] ?? null);

            [, $filteredReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--baseline',
                'baseline.json',
                '--format',
                'json',
            ]);
            self::assertSame([], $this->findingRows($filteredReport));
            self::assertSame(1, $this->suppressedCount($filteredReport));

            file_put_contents($tempDir . '/gruff-new.yaml', $this->focusedConfig(7, false));
            file_put_contents($tempDir . '/New.php', $this->oversizedSource(5));
            [$cleanBaselineProcess] = $this->runHook($tempDir, [
                'hook',
                'New.php',
                '--config',
                'gruff-new.yaml',
                '--format',
                'json',
            ]);
            file_put_contents($tempDir . '/clean-baseline.json', $cleanBaselineProcess->getOutput());
            file_put_contents($tempDir . '/New.php', $this->oversizedSource(9));

            [, $newFindingReport] = $this->runHook($tempDir, [
                'hook',
                'New.php',
                '--config',
                'gruff-new.yaml',
                '--baseline',
                'clean-baseline.json',
                '--format',
                'json',
            ]);
            self::assertNotNull($this->firstFindingByRule($this->findingRows($newFindingReport), 'size.file-length'));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify hook stable identities survive line shifts for symbol findings.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookBaselineUsesStableIdentityIndependentOfLineShifts(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->symbolOnlyConfig());
            file_put_contents($tempDir . '/Example.php', $this->singleEmptyMethodSource());

            [$baselineProcess, $baselineReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            file_put_contents($tempDir . '/baseline.json', $baselineProcess->getOutput());
            $baselineFinding = $this->firstFindingByRule($this->findingRows($baselineReport), 'waste.empty-method');
            self::assertNotNull($baselineFinding);

            file_put_contents($tempDir . '/Example.php', "// shifted\n" . $this->singleEmptyMethodSource());

            [, $shiftedReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            $shiftedFinding = $this->firstFindingByRule($this->findingRows($shiftedReport), 'waste.empty-method');
            self::assertNotNull($shiftedFinding);
            self::assertSame($baselineFinding['stableIdentity'] ?? null, $shiftedFinding['stableIdentity'] ?? null);
            self::assertNotSame($baselineFinding['fingerprint'] ?? null, $shiftedFinding['fingerprint'] ?? null);

            [, $filteredReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--baseline',
                'baseline.json',
                '--format',
                'json',
            ]);
            self::assertSame([], $this->findingRows($filteredReport));
            self::assertSame(1, $this->suppressedCount($filteredReport));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify Symfony flag parsing works before and after the path and hook exits zero with findings.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookAllowsFlagsBeforeAndAfterPath(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->focusedConfig(5, false));
            file_put_contents($tempDir . '/Example.php', $this->oversizedSource(9));

            [$beforeProcess, $beforeReport] = $this->runHook($tempDir, [
                'hook',
                '--format',
                'json',
                '--config',
                'gruff-test.yaml',
                'Example.php',
            ]);
            [$afterProcess, $afterReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);

            self::assertSame(0, $beforeProcess->getExitCode(), $beforeProcess->getErrorOutput());
            self::assertSame(0, $afterProcess->getExitCode(), $afterProcess->getErrorOutput());
            self::assertNotSame([], $this->findingRows($beforeReport));
            self::assertSame($this->ruleIds($this->findingRows($beforeReport)), $this->ruleIds($this->findingRows($afterReport)));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify hook reports ignored paths and config errors in-band.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookReportsIgnoredPathsAndConfigErrors(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', <<<YAML
schemaVersion: gruff-php.config.v0.1
paths:
    ignore:
        - Ignored.php
selection:
    rules:
        - size.file-length
rules:
    size.file-length:
        threshold: 1
        severity:  error
YAML);
            file_put_contents($tempDir . '/Ignored.php', $this->oversizedSource(4));

            [, $ignoredReport] = $this->runHook($tempDir, [
                'hook',
                'Ignored.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            $ignored = $ignoredReport['ignored'] ?? null;
            self::assertIsArray($ignored);
            $paths = $ignored['paths'] ?? null;
            self::assertIsArray($paths);
            $ignoredPath = $paths[0] ?? null;
            self::assertIsArray($ignoredPath);
            self::assertSame('Ignored.php', $ignoredPath['path'] ?? null);
            self::assertSame('config', $ignoredPath['source'] ?? null);
            self::assertSame('Ignored.php', $ignoredPath['pattern'] ?? null);

            file_put_contents($tempDir . '/bad.yaml', "rules: {}\n");
            [$badProcess, $badReport] = $this->runHook($tempDir, [
                'hook',
                'Ignored.php',
                '--config',
                'bad.yaml',
                '--format',
                'json',
            ]);

            self::assertSame(2, $badProcess->getExitCode());
            $config = $badReport['config'] ?? null;
            self::assertIsArray($config);
            self::assertFalse($config['schemaOk'] ?? true);
            self::assertIsString($config['error'] ?? null);
            self::assertStringContainsString('schemaVersion', $config['error']);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify complexity findings report a score unit and the rule's measured complexity value.
     *
     * Regression: the presenter previously keyed threshold metadata on the non-existent rule
     * ids `complexity.cognitive-complexity` / `complexity.cyclomatic-complexity`, so the contract
     * emitted unit "count" and recovered `measured` from a fragile first-numeric-metadata fallback
     * instead of the rule's own `complexity` value.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookComplexityFindingReportsScoreUnitAndMeasuredComplexity(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->complexityConfig());
            file_put_contents($tempDir . '/Complex.php', $this->complexMethodSource());

            [, $report] = $this->runHook($tempDir, [
                'hook',
                'Complex.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);

            $finding = $this->firstFindingByRule($this->findingRows($report), 'complexity.cyclomatic');
            self::assertNotNull($finding);

            $metadata = $finding['metadata'] ?? null;
            self::assertIsArray($metadata);
            self::assertSame('score', $metadata['unit'] ?? null);
            self::assertSame('above', $metadata['direction'] ?? null);
            self::assertSame(1, $metadata['threshold'] ?? null);

            $complexity = $metadata['complexity'] ?? null;
            self::assertIsInt($complexity);
            self::assertGreaterThan(1, $complexity);
            self::assertSame($complexity, $metadata['measured'] ?? null);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify a malformed --baseline file returns the in-band hook error instead of crashing the contract.
     *
     * Regression: json_decode(JSON_THROW_ON_ERROR) on the baseline raised an uncaught JsonException that
     * escaped the DiffException|RuntimeException handler, breaking the JSON contract on a common operational error.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookReturnsInBandErrorForMalformedBaseline(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->focusedConfig(5, false));
            file_put_contents($tempDir . '/Example.php', $this->oversizedSource(9));
            file_put_contents($tempDir . '/baseline.json', '{ not valid json');

            [$process, $report] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--baseline',
                'baseline.json',
                '--format',
                'json',
            ]);

            self::assertSame(2, $process->getExitCode());
            self::assertSame('gruff.hook.v1', $report['contractVersion'] ?? null);
            $config = $report['config'] ?? null;
            self::assertIsArray($config);
            self::assertIsString($config['error'] ?? null);
            self::assertStringContainsString('baseline', $config['error']);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify --exclude-rule drops only the named rule and preserves the configured selection.
     *
     * Regression: an empty include list means "all rules", so replacing the config selection with a
     * bare --exclude-rule widened a focused config to the whole rule set instead of removing one rule.
     *
     * @return void
     * @throws JsonException
     */
    public function testHookExcludeRulePreservesConfiguredSelection(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->focusedConfig(5));
            file_put_contents($tempDir . '/Example.php', $this->fileAndSymbolSource());

            [, $report] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--exclude-rule',
                'size.file-length',
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
     * Return a focused config for hook tests.
     *
     * @param int  $fileLengthThreshold - Size threshold.
     * @param bool $shouldIncludeWaste  - Whether to include the empty-method rule.
     *
     * @return string - YAML config.
     */
    private function focusedConfig(int $fileLengthThreshold, bool $shouldIncludeWaste = true): string
    {
        $rules = $shouldIncludeWaste
            ? "        - size.file-length\n        - waste.empty-method\n"
            : "        - size.file-length\n";

        return <<<YAML
schemaVersion: gruff-php.config.v0.1
selection:
    rules:
$rules
rules:
    size.file-length:
        threshold: $fileLengthThreshold
        severity:  error
YAML;
    }

    /**
     * Return a config that runs only symbol-level empty-method findings.
     *
     * @return string - YAML config.
     */
    private function symbolOnlyConfig(): string
    {
        return <<<'YAML'
schemaVersion: gruff-php.config.v0.1
selection:
    rules:
        - waste.empty-method
YAML;
    }

    /**
     * Source that yields both a file-scope and symbol-scope finding.
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
     * Source with one empty-method finding.
     *
     * @return string - PHP source.
     */
    private function singleEmptyMethodSource(): string
    {
        return <<<'PHP'
<?php

final class Example
{
    public function empty(): void
    {
    }
}
PHP;
    }

    /**
     * Build source with a target physical line count.
     *
     * @param int $lines - Number of physical lines.
     *
     * @return string - PHP source.
     */
    private function oversizedSource(int $lines): string
    {
        // Every generated line is substantive: file-length counts non-blank, non-comment lines only.
        $sourceLines = [
            '<?php',
            'final class Example',
            '{',
            '}',
        ];

        $fillerIndex = 1;
        while (count($sourceLines) < $lines) {
            $sourceLines[] = '$filler' . $fillerIndex . " = 'filler';";
            ++$fillerIndex;
        }

        return implode("\n", $sourceLines) . "\n";
    }

    /**
     * Return a config that runs only the cyclomatic-complexity rule at a low threshold.
     *
     * @return string - YAML config.
     */
    private function complexityConfig(): string
    {
        return <<<'YAML'
schemaVersion: gruff-php.config.v0.1
selection:
    rules:
        - complexity.cyclomatic
rules:
    complexity.cyclomatic:
        threshold: 1
        severity:  warning
YAML;
    }

    /**
     * Source with one method whose cyclomatic complexity exceeds a low threshold.
     *
     * @return string - PHP source.
     */
    private function complexMethodSource(): string
    {
        return <<<'PHP'
<?php

final class Complex
{
    public function tangled(int $n): int
    {
        $total = 0;
        if ($n > 0) {
            $total++;
        } elseif ($n < 0) {
            $total--;
        } else {
            $total = 1;
        }

        for ($i = 0; $i < $n; $i++) {
            if ($i % 2 === 0) {
                $total += $i;
            } else {
                $total -= $i;
            }
        }

        while ($total > 100) {
            $total -= 10;
        }

        return $total > 0 ? $total : -$total;
    }
}
PHP;
    }

    /**
     * Extract finding rows.
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
     * Return the first finding matching a rule id.
     *
     * @param list<array<string, mixed>> $findings - Finding rows.
     * @param string                     $ruleId   - Rule id to find.
     *
     * @return array<string, mixed>|null - Finding row.
     */
    private function firstFindingByRule(array $findings, string $ruleId): ?array
    {
        foreach ($findings as $finding) {
            if (($finding['ruleId'] ?? null) === $ruleId) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * Extract symbols from finding rows.
     *
     * @param list<array<string, mixed>> $findings - Finding rows.
     *
     * @return list<string> - Symbols.
     */
    private function symbols(array $findings): array
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
     * Extract rule ids from finding rows.
     *
     * @param list<array<string, mixed>> $findings - Finding rows.
     *
     * @return list<string> - Rule ids.
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
}
