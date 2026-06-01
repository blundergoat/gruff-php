<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers the failureConditions count gate end-to-end: counted pass/fail, failure
 * reason rendering, total caps, --fail-on precedence, and legacy back-compat.
 */
final class FailureConditionsCliTest extends CliTestCase
{
    /**
     * Number of error findings the gate fixture's three undocumented methods emit.
     */
    private const FIXTURE_ERROR_COUNT = 3;

    /**
     * Project root of the throwaway gate fixture created per test.
     */
    private string $project = '';

    /**
     * Build a temporary project whose single source file emits three error findings.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->project = $this->tempDir();
        $this->writeProjectFile('README.md', "Gate fixture.\n");
        $this->writeProjectFile(
            'src/Gate.php',
            "<?php\n\nnamespace Demo;\n\n/**\n * Gate fixture with three undocumented public methods.\n */\nclass Gate\n{\n    public function alpha(int \$first): int\n    {\n        return \$first;\n    }\n\n    public function bravo(int \$second): int\n    {\n        return \$second;\n    }\n\n    public function charlie(int \$third): int\n    {\n        return \$third;\n    }\n}\n",
        );
    }

    /**
     * Remove the temporary gate fixture.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->project);
    }

    /**
     * Verify a severity cap above the finding count passes with no failure reason.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testCountGatePassesWhenUnderCap(): void
    {
        $this->writeProjectFile('gate.yaml', "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n  severityThresholds:\n    error: 5\n");

        $process = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--format', 'json']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertArrayNotHasKey('failureReason', $this->decodeJsonOutput($process));
    }

    /**
     * Verify exceeding a severity cap reports a structured failure reason in JSON output.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testCountGateReportsStructuredFailureReason(): void
    {
        $errorCap = 2;
        $this->writeProjectFile('gate.yaml', sprintf(
            "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n  severityThresholds:\n    error: %d\n",
            $errorCap,
        ));

        $jsonRun = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--format', 'json']);
        $jsonRun->run();

        self::assertSame(1, $jsonRun->getExitCode());
        $failureReason = $this->decodeJsonOutput($jsonRun)['failureReason'] ?? null;
        self::assertIsArray($failureReason);
        self::assertSame('error', $failureReason['thresholdKind'] ?? null);
        self::assertSame(self::FIXTURE_ERROR_COUNT, $failureReason['count'] ?? null);
        self::assertSame($errorCap, $failureReason['cap'] ?? null);
    }

    /**
     * Verify the rendered text output explains the tripped severity cap.
     *
     * @return void
     */
    public function testCountGateRendersFailureReasonInText(): void
    {
        $this->writeProjectFile('gate.yaml', "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n  severityThresholds:\n    error: 2\n");

        $textRun = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--format', 'text']);
        $textRun->run();

        self::assertStringContainsString('Failed: 3 error finding(s) exceed the cap of 2.', $textRun->getOutput());
    }

    /**
     * Verify the total cap fails the run regardless of severity distribution.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testTotalCapFails(): void
    {
        $this->writeProjectFile('gate.yaml', "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n  total: 2\n");

        $process = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--format', 'json']);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        $failureReason = $this->decodeJsonOutput($process)['failureReason'] ?? null;
        self::assertIsArray($failureReason);
        self::assertSame('total', $failureReason['thresholdKind'] ?? null);
    }

    /**
     * Verify an explicit --fail-on overrides a configured failureConditions block.
     *
     * @return void
     */
    public function testExplicitFailOnOverridesFailureConditions(): void
    {
        $this->writeProjectFile('gate.yaml', "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n  severityThresholds:\n    error: 2\n");

        $process = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--fail-on', 'none', '--format', 'json']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Verify legacy --fail-on error still fails on any error finding.
     *
     * @return void
     */
    public function testFailOnErrorRemainsBackCompatible(): void
    {
        $process = $this->runGruff(['analyse', 'src', '--no-config', '--fail-on', 'error', '--format', 'json']);
        $process->run();

        self::assertSame(1, $process->getExitCode());
    }

    /**
     * Build a gruff-php subprocess rooted at the temporary fixture project.
     *
     * @param list<string> $args - CLI arguments passed after the binary.
     *
     * @return Process - Configured but unstarted process.
     */
    private function runGruff(array $args): Process
    {
        return new Process(
            array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $args),
            $this->project,
        );
    }

    /**
     * Write a fixture file, creating parent directories as needed.
     *
     * @param string $path - Project-relative file path.
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
