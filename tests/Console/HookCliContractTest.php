<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers the gruff.hook.v2 agent-hook CLI contract.
 *
 * Exercises capability advertisements, finding metadata, changed ranges, and baseline filtering.
 * Checks JSON diagnostics and errors alongside the exit codes asserted by individual tests.
 */
final class HookCliContractTest extends CliTestCase
{
    /**
     * Verify hook capabilities advertise the cross-analyzer contract.
     *
     * @return void - Asserts the hook version, flag names, flag ordering, and enabled capabilities.
     * @throws JsonException - When decoding hook stdout fails.
     */
    public function testHookCapabilitiesAdvertiseContract(): void
    {
        [$process, $report] = $this->runHook(self::PROJECT_ROOT, ['hook', '--capabilities', '--format', 'json']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('gruff.hook.v2', $report['contractVersion'] ?? null);
        self::assertSame('any', $report['flagOrder'] ?? null);

        $flags = $report['flags'] ?? null;
        self::assertIsArray($flags);
        self::assertSame('--changed-ranges', $flags['changedRanges'] ?? null);
        self::assertSame('--diff', $flags['diff'] ?? null);
        self::assertSame('--baseline', $flags['baseline'] ?? null);
        self::assertSame('--deep-scan-budget', $flags['deepScanBudget'] ?? null);

        $supports = $report['supports'] ?? null;
        self::assertIsArray($supports);

        // Verify the advertised features so hook consumers know which scope and gates they can request.
        foreach ([
            'baseline', 'baselineV3', 'changedRanges', 'confidenceGate', 'deepScanBudget', 'diagnostics',
            'diff', 'ignoreReport', 'metadata', 'newOnly', 'scopeField', 'stableIdentity',
        ] as $capability) {
            self::assertTrue($supports[$capability] ?? false, $capability);
        }
    }

    /**
     * Verify bounded-scan JSON includes an AWS-key finding and a diagnostic that does not invalidate the run.
     *
     * @return void - Asserts exit code zero, an AWS-key finding, and a non-invalidating bounded-scan diagnostic.
     */
    public function testHookReportsBoundedDeepScanWithoutInvalidatingRun(): void
    {
        $project = $this->syntheticSecretsProject();

        try {
            [$process, $report] = $this->runHook($project, [
                'hook',
                '--no-config',
                '--deep-scan-budget',
                '1:1',
                '--include-rule',
                'sensitive-data.aws-access-key',
                '--format',
                'json',
                'src/synthetic-secrets.php',
            ]);
        } finally {
            $this->removeDir($project);
        }

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
     * @return void - Asserts file-length suppression, the selected empty-method symbol, and suppression counts.
     * @throws JsonException - When decoding hook stdout fails.
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
     * Distinguishes the three scopes when a changed line shares a function with a finding.
     *
     * @return void - Checks the reported scope and the gate verdict for each option.
     */
    public function testHookGateUsesRequestedChangedScope(): void
    {
        $project = $this->tempDir();

        try {
            file_put_contents($project . '/Example.php', <<<'PHP'
                <?php
                function example(): void {
                    $text = 'sample';
                    echo @strlen($text);
                }
                PHP);

            // Use one edited function to show that widening from hunk to symbol or file changes the gate result.
            foreach (['symbol' => 1, 'hunk' => 0, 'file' => 1] as $scope => $expectedExit) {
                [$process, $report] = $this->runHook($project, [
                    'hook', 'Example.php', '--no-config', '--include-rule=security.error-suppression',
                    '--changed-ranges=3-3', '--changed-scope=' . $scope, '--fail-on=warning',
                ]);

                self::assertSame($expectedExit, $process->getExitCode(), $process->getOutput());
                self::assertSame($scope, $this->decodedJsonObject($report['run'] ?? null)['scope'] ?? null);
            }
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Proves a missing sole operand cannot count as a successful scan.
     *
     * @return void - Checks exit 2 and the machine-readable missing-path diagnostic.
     */
    public function testHookRefusesMissingSoleOperand(): void
    {
        $project = $this->tempDir();

        try {
            [$process, $report] = $this->runHook($project, ['hook', 'missing.php', '--no-config']);
            self::assertSame(2, $process->getExitCode());
            self::assertSame(0, $this->decodedJsonObject($report['run'] ?? null)['analysedFiles'] ?? null);
            $diagnostics = $report['diagnostics'] ?? null;
            self::assertIsArray($diagnostics);
            $diagnostic = $this->decodedJsonObject($diagnostics[0] ?? null);
            self::assertSame('missing-path', $diagnostic['type'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify the file-length hook finding carries scope, remediation, string-valued identity fields, and normalized threshold metadata.
     *
     * @return void - Asserts the file-length finding's fields and its measured, threshold, unit, and direction metadata.
     * @throws JsonException - When decoding hook stdout fails.
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

            self::assertSame('gruff.hook.v2', $report['contractVersion'] ?? null);
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
            // Substantive count: the fixture's blank lines are free under file-length.
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
     * Verify hook output exposes the expected remediation action and configuration key.
     *
     * @return void - Asserts exit code zero and the expected abbreviation-allowlist remediation metadata values.
     * @throws JsonException - When decoding hook stdout fails.
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
     * Verify baseline filtering hides an existing size finding after growth and reports a newly oversized file.
     *
     * @return void - Asserts equal optional identity values, unequal fingerprints, baseline suppression, and a new size finding.
     * @throws JsonException - When decoding hook stdout fails.
     */
    public function testHookBaselineUsesStableIdentityIndependentOfMeasuredValues(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->focusedConfig(5, false));
            file_put_contents($tempDir . '/Example.php', $this->oversizedSource(7));

            [, $baselineReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            $this->generateBaseline($tempDir, 'gruff-test.yaml', 'baseline.json');

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
            $runBaselineBlock = $this->runBaselineBlock($filteredReport);
            self::assertTrue($runBaselineBlock['applied'] ?? false);
            self::assertSame('gruff.baseline.v3', $runBaselineBlock['schemaVersion'] ?? null);

            file_put_contents($tempDir . '/gruff-new.yaml', $this->focusedConfig(7, false));
            file_put_contents($tempDir . '/New.php', $this->oversizedSource(5));
            $this->generateBaseline($tempDir, 'gruff-new.yaml', 'clean-baseline.json');
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
     * Compare identity values and baseline filtering before and after a symbol's line shift.
     *
     * @return void - Asserts equal optional identity values, unequal fingerprint values, and baseline suppression.
     * @throws JsonException - When decoding hook stdout fails.
     */
    public function testHookBaselineUsesStableIdentityIndependentOfLineShifts(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/gruff-test.yaml', $this->symbolOnlyConfig());
            file_put_contents($tempDir . '/Example.php', $this->singleEmptyMethodSource());

            [, $baselineReport] = $this->runHook($tempDir, [
                'hook',
                'Example.php',
                '--config',
                'gruff-test.yaml',
                '--format',
                'json',
            ]);
            $this->generateBaseline($tempDir, 'gruff-test.yaml', 'baseline.json');
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
            // A baseline match is reviewed debt, not a changed-region drop, so the run block is what records it.
            self::assertTrue($this->runBaselineBlock($filteredReport)['applied'] ?? false);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify hook accepts flags before and after the path and returns matching nonempty rule lists.
     *
     * @return void - Asserts zero exits and matching, nonempty rule lists for both flag positions.
     * @throws JsonException - When decoding hook stdout fails.
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
     * @return void - Asserts ignored-path provenance and a structured schema error with exit code two.
     * @throws JsonException - When decoding hook stdout fails.
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
            // The structured error keeps remediation available when configuration validation fails.
            self::assertIsArray($config['error'] ?? null);
            $configError = $config['error'];
            self::assertIsString($configError['message'] ?? null);
            self::assertStringContainsString('schemaVersion', $configError['message']);
            self::assertNotSame('', $config['error']['remediation'] ?? '');
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify a cyclomatic finding reports a score unit and the rule's measured complexity value.
     *
     * @return void - Asserts the score unit, above direction, threshold one, and matching measured and complexity values.
     * @throws JsonException - When decoding hook stdout fails.
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
     * Verify malformed baseline JSON produces an in-band hook error with exit code two.
     *
     * @return void - Asserts the hook contract version and a structured error message mentioning the baseline.
     * @throws JsonException - When decoding hook stdout fails.
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
            self::assertSame('gruff.hook.v2', $report['contractVersion'] ?? null);
            $config = $report['config'] ?? null;
            self::assertIsArray($config);
            self::assertIsArray($config['error'] ?? null);
            $configError = $config['error'];
            self::assertIsString($configError['message'] ?? null);
            self::assertStringContainsString('baseline', $configError['message']);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify --exclude-rule drops only the named rule and preserves the configured selection.
     * An exclusion must not widen the configured rule selection.
     *
     * @return void - Asserts file-length is absent and the unique remaining rule is waste.empty-method.
     * @throws JsonException - When decoding hook stdout fails.
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
     * Run analyse --generate-baseline for a project and assert that the destination exists.
     *
     * @param string $cwd          - Project directory the baseline is generated in.
     * @param string $configFile   - Config the generating scan runs under, so the baseline covers the same rules.
     * @param string $baselineFile - Project-relative destination for the generated baseline.
     *
     * @return void - Asserts that the requested baseline file exists after the generating command finishes.
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
     * Read baseline audit data, whether or not a baseline was applied.
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
     * @return array{0: Process, 1: array<string, mixed>} - Process and decoded JSON.
     * @throws JsonException - When decoding hook stdout fails.
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
     * @param int  $fileLengthThreshold - Substantive-line threshold for the file-length rule.
     * @param bool $shouldIncludeWaste  - Whether to include the empty-method rule.
     *
     * @return string - YAML selecting file-length at error severity and optionally the empty-method rule.
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
     * @return string - YAML selecting only waste.empty-method.
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
     * Provide two empty methods for hook tests that combine file and symbol findings.
     *
     * @return string - PHP source declaring Example::ok() and Example::empty() with empty bodies.
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
     * Provide a single empty method for the hook line-shift test.
     *
     * @return string - PHP source declaring only Example::empty() inside Example.
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
     * Build a four-line PHP skeleton, padding it to the requested physical line count.
     *
     * @param int $lines - Target physical line count; the four-line skeleton is the minimum.
     *
     * @return string - PHP source with at least four substantive lines and a trailing newline.
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
     * @return string - YAML selecting complexity.cyclomatic with threshold one and warning severity.
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
     * @return string - PHP source with branches, loops, and a ternary in Complex::tangled().
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
     * @return list<array<string, mixed>> - Findings as supplied; only the outer array is asserted, without validating rows or list keys.
     */
    private function findingRows(array $report): array
    {
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);

        /** @var list<array<string, mixed>> $findings - Decoded JSON finding rows, asserted as an array above. */
        return $findings;
    }

    /**
     * Return the first finding matching a rule id.
     *
     * @param list<array<string, mixed>> $findings - Finding rows to search in their supplied order.
     * @param string                     $ruleId   - Rule id to find.
     *
     * @return array<string, mixed>|null - First matching row in input order, or null for empty input or no match.
     */
    private function firstFindingByRule(array $findings, string $ruleId): ?array
    {
        // Find a published finding by the rule a hook caller asked to run.
        foreach ($findings as $finding) {
            // The first matching rule row is enough for this test to inspect its payload.
            if (($finding['ruleId'] ?? null) === $ruleId) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * Extract symbols from finding rows.
     *
     * @param list<array<string, mixed>> $findings - Finding rows whose optional symbol fields are inspected.
     *
     * @return list<string> - String-valued symbols in input order, including empty strings and duplicates; nonstrings are skipped.
     */
    private function symbols(array $findings): array
    {
        $symbols = [];
        // Inspect only findings with a named declaration when checking symbol coverage.
        foreach ($findings as $finding) {
            // A line or file finding without a symbol cannot contribute a declaration name.
            if (is_string($finding['symbol'] ?? null)) {
                $symbols[] = $finding['symbol'];
            }
        }

        return $symbols;
    }

    /**
     * Extract rule ids from finding rows.
     *
     * @param list<array<string, mixed>> $findings - Finding rows whose ruleId fields are read.
     *
     * @return list<string> - One rule ID per input row in order; missing or nonstring IDs become empty strings.
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
     * @return int - Count of findings removed by hook filtering; missing or non-integer counts fail assertions.
     */
    private function suppressedCount(array $report): int
    {
        $suppressed = $report['suppressed'] ?? null;
        self::assertIsArray($suppressed);
        self::assertIsInt($suppressed['count'] ?? null);

        return $suppressed['count'];
    }
}
