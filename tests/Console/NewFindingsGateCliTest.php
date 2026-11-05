<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers the --fail-on-new gate end-to-end: passes when nothing new, fails with a
 * "new" scope against a baseline or a --diff-vs ref, and errors when no reference
 * point is configured.
 */
final class NewFindingsGateCliTest extends CliTestCase
{
    /**
     * Project root of the throwaway new-findings fixture created per test.
     */
    private string $project = '';

    /**
     * Build a temporary project whose Calc fixture has one undocumented method.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->project = $this->tempDir();
        $this->writeProjectFile('README.md', "New-findings gate fixture.\n");
        $this->writeCalc(false);
    }

    /**
     * Remove the temporary fixture.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->project);
    }

    /**
     * Verify --fail-on-new passes (exit 0, zero new) when every finding is baselined.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testPassesWhenNoNewFindings(): void
    {
        $this->runGruff(['analyse', 'src', '--no-config', '--fail-on', 'none', '--generate-baseline', 'gruff-baseline.json']);

        $process = $this->runGruff(['analyse', 'src', '--no-config', '--baseline', 'gruff-baseline.json', '--fail-on-new', '--format', 'json']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report = $this->decodeJsonOutput($process);
        self::assertSame(0, $this->newFindingsCount($report));
        self::assertNull($this->failureReason($report));
    }

    /**
     * Verify --fail-on-new does not also apply the default total-findings gate.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testFailOnNewDoesNotApplyDefaultTotalGate(): void
    {
        $this->writeCalc(true);
        $this->git(['init', '-q']);
        $this->git(['add', 'src/Calc.php', 'README.md']);
        $this->git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', 'base']);

        $process = $this->runGruff(['analyse', 'src', '--no-config', '--no-baseline', '--diff-vs', 'HEAD', '--fail-on-new', '--format', 'json']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report = $this->decodeJsonOutput($process);
        self::assertSame(0, $this->newFindingsCount($report));
        self::assertNull($this->failureReason($report));
    }

    /**
     * Verify explicit --fail-on overrides a configured new-findings gate unless --fail-on-new is also set.
     *
     * @return void
     */
    public function testExplicitFailOnOverridesConfiguredNewFindingsGate(): void
    {
        $this->writeProjectFile(
            'gate.yaml',
            "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n    newFindings:\n        severityThresholds:\n            error: 0\n",
        );

        $process = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--no-baseline', '--fail-on', 'none', '--format', 'text']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringNotContainsString('new-findings gate needs a reference point', $process->getOutput());
    }

    /**
     * Verify --fail-on-new fails with a "new" scope on a finding not in the baseline.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testFailsOnBaselineNewFinding(): void
    {
        $this->runGruff(['analyse', 'src', '--no-config', '--fail-on', 'none', '--generate-baseline', 'gruff-baseline.json']);
        $this->writeCalc(true);

        $process = $this->runGruff(['analyse', 'src', '--no-config', '--baseline', 'gruff-baseline.json', '--fail-on-new', '--format', 'json']);

        self::assertSame(1, $process->getExitCode());
        $failureReason = $this->failureReason($this->decodeJsonOutput($process));
        self::assertIsArray($failureReason);
        self::assertSame('new', $failureReason['scope'] ?? null);
        self::assertSame('error', $failureReason['thresholdKind'] ?? null);
    }

    /**
     * Verify --fail-on-new fails on a finding introduced versus a --diff-vs base ref.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testFailsOnDiffVsIntroducedFinding(): void
    {
        $this->git(['init', '-q']);
        $this->git(['add', 'src/Calc.php', 'README.md']);
        $this->git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', 'base']);
        $this->writeCalc(true);

        $process = $this->runGruff(['analyse', 'src', '--no-config', '--no-baseline', '--diff-vs', 'HEAD', '--fail-on-new', '--format', 'json']);

        self::assertSame(1, $process->getExitCode());
        $failureReason = $this->failureReason($this->decodeJsonOutput($process));
        self::assertIsArray($failureReason);
        self::assertSame('new', $failureReason['scope'] ?? null);
    }

    /**
     * Verify --fail-on-new errors at setup when no baseline or --diff-vs reference exists.
     *
     * @return void
     */
    public function testErrorsWithoutReferencePoint(): void
    {
        $process = $this->runGruff(['analyse', 'src', '--no-config', '--no-baseline', '--fail-on-new', '--format', 'text']);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('new-findings gate needs a reference point', $process->getOutput());
    }

    /**
     * Write the Calc fixture, optionally with a second undocumented method.
     *
     * @param bool $shouldIncludeBeta - Whether to include a second undocumented public method.
     *
     * @return void
     */
    private function writeCalc(bool $shouldIncludeBeta): void
    {
        $beta = $shouldIncludeBeta
            ? "\n    public function beta(int \$amount): int\n    {\n        return \$amount - 1;\n    }\n"
            : '';

        $this->writeProjectFile(
            'src/Calc.php',
            "<?php\n\nnamespace Demo;\n\n/**\n * Calc fixture for the new-findings gate.\n */\nclass Calc\n{\n    /**\n     * Add one to the amount.\n     *\n     * @param int \$amount Amount to increment.\n     * @return int Incremented amount.\n     */\n    public function alpha(int \$amount): int\n    {\n        return \$amount + 1;\n    }\n{$beta}}\n",
        );
    }

    /**
     * Reads the canonical new-finding total from a report used by the gate scenarios.
     *
     * @param array<string, mixed> $report - Decoded analysis report containing a baseline section.
     *
     * @return int - Number of findings considered new by the configured gate.
     */
    private function newFindingsCount(array $report): int
    {
        $baseline = $report['baseline'] ?? null;
        self::assertIsArray($baseline);
        $count = $baseline['newFindings'] ?? null;
        self::assertIsInt($count);

        return $count;
    }

    /**
     * Reads PHP's named failure-reason extension when a new-findings gate trips.
     *
     * @param array<string, mixed> $report - Decoded v3 analysis document.
     *
     * @return array<string, mixed>|null - Failure reason when the report contains one, otherwise null.
     */
    private function failureReason(array $report): ?array
    {
        $extensions = $report['extensions'] ?? null;
        if (!is_array($extensions)) {
            return null;
        }

        $phpExtensions = $extensions['php'] ?? null;
        if (!is_array($phpExtensions)) {
            return null;
        }

        $topLevel = $phpExtensions['topLevel'] ?? null;
        if (!is_array($topLevel)) {
            return null;
        }

        $failureReason = $topLevel['failureReason'] ?? null;

        return is_array($failureReason) ? $this->decodedJsonObject($failureReason) : null;
    }

    /**
     * Run a gruff-php subprocess rooted at the fixture project and return it completed.
     *
     * @param list<string> $args - CLI arguments passed after the binary.
     *
     * @return Process - Completed process.
     */
    private function runGruff(array $args): Process
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $args), $this->project);
        $process->run();

        return $process;
    }

    /**
     * Run a git command inside the fixture project.
     *
     * @param list<string> $args - Git arguments.
     *
     * @return void
     */
    private function git(array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $this->project);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Write a fixture file, creating parent directories as needed.
     *
     * @param string $path     - Project-relative file path.
     * @param string $contents - File contents.
     *
     * @return void
     */
    private function writeProjectFile(string $path, string $contents): void
    {
        $absolutePath = $this->project . '/' . $path;
        $directory    = dirname($absolutePath);

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }

        file_put_contents($absolutePath, $contents);
    }
}
