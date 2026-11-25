<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers analyse rule selection through reported findings and CLI exit status.
 * Checks exclusions under current and legacy rule IDs, configured selection, and security profiles.
 * Checks that an unsupported profile produces a usage error.
 */
final class AnalyseCliRuleSelectionTest extends CliTestCase
{
    /**
     * Verify current and legacy exclusion IDs remove reported findings and clear the advisory exit gate.
     *
     * @return void - Asserts the control exits 1 and both exclusion IDs yield exit 0 with zero reported findings.
     * @throws JsonException - If decoding an exclusion run's JSON output fails.
     */
    public function testAnalyseCommandExcludeRuleSkipsExecutionAndExitCode(): void
    {
        $baseArguments = [
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--no-config',
            '--no-baseline',
            '--format',
            'json',
            '--fail-on',
            'advisory',
        ];

        $controlProcess = new Process($baseArguments, __DIR__ . '/../..');
        $controlProcess->run();
        self::assertSame(1, $controlProcess->getExitCode(), 'the fixture must trip the advisory gate for the exclusion proof to mean anything');

        // A flag written before the rename must keep excluding the same rule.
        foreach (['docs.missing-phpdoc', 'docs.missing-public-phpdoc'] as $excludedRuleId) {
            $excludedProcess = new Process([...$baseArguments, '--exclude-rule', $excludedRuleId], __DIR__ . '/../..');
            $excludedProcess->run();
            self::assertSame(0, $excludedProcess->getExitCode(), $excludedRuleId . ': ' . $excludedProcess->getErrorOutput());

            $report  = $this->decodeJsonOutput($excludedProcess);
            $summary = $report['summary'] ?? null;
            self::assertIsArray($summary, $excludedRuleId);
            $findingCounts = $summary['findings'] ?? null;
            self::assertIsArray($findingCounts, $excludedRuleId);
            self::assertSame(0, $findingCounts['total'] ?? null, $excludedRuleId);
        }
    }

    /**
     * Verify analyse reports no findings with the configured rule-selection fixture.
     *
     * @return void - Asserts exit status 0 and a reported findings total of zero.
     * @throws JsonException - If decoding the configured run's JSON output fails.
     */
    public function testAnalyseCommandAppliesConfiguredRuleSelection(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--config',
                                   'tests/Fixtures/Config/only-size-rules.yaml',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report  = $this->decodeJsonOutput($process);
        $summary = $report['summary'] ?? null;
        self::assertIsArray($summary);
        $findingCounts = $summary['findings'] ?? null;
        self::assertIsArray($findingCounts);
        self::assertSame(0, $findingCounts['total'] ?? null);
    }

    /**
     * Verify the security profile reports nonempty security or sensitive-data findings and grade C.
     *
     * @return void - Asserts success, grade C, and allowed rule ID prefixes for every reported finding.
     * @throws JsonException - If decoding the security-profile report fails.
     */
    public function testAnalyseCommandSecurityProfileRunsSecurityRulesOnly(): void
    {
        // Security rules skip paths under tests/, so the fixture is scanned from a production path.
        $project = $this->cumulativeSecurityProject();
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   'src/cumulative-security.php',
                                   '--no-config',
                                   '--profile',
                                   'security',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], $project);

        try {
            $process->run();
        } finally {
            $this->removeDir($project);
        }

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        $score    = $report['score'] ?? null;
        self::assertIsArray($findings);
        self::assertIsArray($score);
        $composite = $score['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertNotCount(0, $findings);
        self::assertSame('C', $composite['grade'] ?? null);

        foreach ($findings as $index => $finding) {
            self::assertIsArray($finding, sprintf('Finding %d should be an array.', $index));
            $ruleId = $finding['ruleId'] ?? null;
            self::assertIsString($ruleId, sprintf('Finding %d should include a string ruleId.', $index));
            self::assertTrue(
                str_starts_with($ruleId, 'security.') || str_starts_with($ruleId, 'sensitive-data.'),
                'Unexpected rule from security profile: ' . $ruleId,
            );
        }
    }

    /**
     * Verify a security-profile run reports unsafe XML when a rule-selection config is supplied.
     *
     * @return void - Asserts success, an unsafe-XML finding, and no docs.missing-file-phpdoc finding.
     * @throws JsonException - If decoding the configured security-profile report fails.
     */
    public function testAnalyseCommandSecurityProfileOverridesConfiguredSelection(): void
    {
        // Security rules skip paths under tests/, so the fixture is scanned from a production path.
        $project = $this->cumulativeSecurityProject();
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   'src/cumulative-security.php',
                                   '--config',
                                   self::PROJECT_ROOT . '/tests/Fixtures/Config/only-size-rules.yaml',
                                   '--profile',
                                   'security',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], $project);

        try {
            $process->run();
        } finally {
            $this->removeDir($project);
        }

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        $ruleIds = array_map(
            static fn(mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
            $findings,
        );

        self::assertContains('security.unsafe-xml-loading', $ruleIds);
        self::assertNotContains('docs.missing-file-phpdoc', $ruleIds);
    }

    /**
     * Verify analyse command rejects unknown execution profiles.
     *
     * @return void - Asserts exit status 2 and the usage error listing default and security.
     */
    public function testAnalyseCommandReportsInvalidProfile(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   '--profile',
                                   'security-plus',
                                   'tests/Fixtures/Source/Code',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[USAGE-ERROR] Unsupported profile "security-plus". Use default or security.', $process->getOutput());
    }

    /**
     * Verify --file targeting a PHP file outside the launch directory produces a valid v3 JSON report without crashing.
     *
     * @return void - Asserts exit code 0 and a decoded v3 JSON report containing the external file's findings.
     * @throws JsonException - If decoding the analyse JSON output fails.
     */
    public function testAnalyseFileOptionOutsideLaunchDirectoryProducesValidJson(): void
    {
        $externalDir = $this->tempDir();

        try {
            $externalFile = $externalDir . '/ExternalSample.php';
            file_put_contents($externalFile, "<?php\nfinal class ExternalSample {}\n");

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                '--file',
                $externalFile,
                '--no-config',
                '--no-baseline',
                '--no-cache',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], self::PROJECT_ROOT);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $report = $this->decodeJsonOutput($process);
            self::assertSame('gruff.analysis.v3', $report['schemaVersion'] ?? null);
            $findings = $report['findings'] ?? null;
            self::assertIsArray($findings);
            self::assertNotSame([], $findings);
        } finally {
            $this->removeDir($externalDir);
        }
    }

    /**
     * Verify --show-rule narrows visible findings instead of unioning with --include-rule.
     *
     * @return void - Asserts only the requested --show-rule finding is visible while the other included rule is counted as hidden.
     * @throws JsonException - If decoding the analyse JSON output fails.
     */
    public function testAnalyseShowRuleNarrowsIncludedRules(): void
    {
        $project = $this->tempDir();

        try {
            file_put_contents($project . '/Sample.php', "<?php\nfinal class Sample {}\n");

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                'Sample.php',
                '--no-config',
                '--no-baseline',
                '--no-cache',
                '--include-rule',
                'docs.missing-file-phpdoc',
                '--include-rule',
                'docs.missing-class-phpdoc',
                '--show-rule',
                'docs.missing-file-phpdoc',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $report   = $this->decodeJsonOutput($process);
            $findings = $report['findings'] ?? null;
            self::assertIsArray($findings);
            self::assertCount(1, $findings);
            $firstFinding = $findings[0] ?? null;
            self::assertIsArray($firstFinding);
            self::assertSame('docs.missing-file-phpdoc', $firstFinding['ruleId'] ?? null);

            $displayFilter = $report['displayFilter'] ?? null;
            self::assertIsArray($displayFilter);
            self::assertSame(1, $displayFilter['hiddenFindings'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify whitespace-only --file operands fail as usage errors instead of resolving to the whole project root.
     *
     * @return void - Asserts exit code 2 and the non-empty --file usage error message.
     */
    public function testAnalyseRejectsWhitespaceOnlyFileOption(): void
    {
        $project = $this->tempDir();

        try {
            file_put_contents($project . '/Sample.php', "<?php\nfinal class Sample {}\n");

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                '--file',
                '   ',
                '--no-config',
                '--no-baseline',
                '--no-cache',
                '--format',
                'text',
                '--fail-on',
                'none',
            ], $project);
            $process->run();

            self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            self::assertStringContainsString('--file requires a non-empty value.', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }
}
