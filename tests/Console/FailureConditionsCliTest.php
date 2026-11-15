<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Integration coverage for CLI finding-count gates and their reported failure reasons.
 * Checks severity and total caps through exit codes, JSON fields, and rendered text.
 * Also checks explicit --fail-on precedence and the tested legacy exit status.
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
     * Build a temporary project whose source file contains three undocumented public methods.
     *
     * @return void - Writes the README and the three-method Gate fixture into a new temporary project.
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
     * Remove fixture state so later tests cannot observe this temporary project.
     *
     * @return void - Removes the temporary project created for the test.
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->project);
    }

    /**
     * Verify an under-cap run succeeds without an array-valued failure reason.
     *
     * @throws JsonException - When the CLI output cannot be decoded as JSON.
     *
     * @return void - Asserts exit code 0 and a null result from failureReason().
     */
    public function testCountGatePassesWhenUnderCap(): void
    {
        $this->writeProjectFile('gate.yaml', "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n  severityThresholds:\n    error: 5\n");

        $process = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--format', 'json']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertNull($this->failureReason($this->decodeJsonOutput($process)));
    }

    /**
     * Verify exceeding a severity cap reports a structured failure reason in JSON output.
     *
     * @throws JsonException - When the CLI output cannot be decoded as JSON.
     *
     * @return void - Asserts exit code 1 and the error threshold kind, fixture count, and configured cap.
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
        $failureReason = $this->failureReason($this->decodeJsonOutput($jsonRun));
        self::assertIsArray($failureReason);
        self::assertSame('error', $failureReason['thresholdKind'] ?? null);
        self::assertSame(self::FIXTURE_ERROR_COUNT, $failureReason['count'] ?? null);
        self::assertSame($errorCap, $failureReason['cap'] ?? null);
    }

    /**
     * Verify the rendered text output explains the tripped severity cap.
     *
     * @return void - Asserts the expected error count and cap appear in the failure message.
     */
    public function testCountGateRendersFailureReasonInText(): void
    {
        $this->writeProjectFile('gate.yaml', "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n  severityThresholds:\n    error: 2\n");

        $textRun = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--format', 'text']);
        $textRun->run();

        self::assertStringContainsString('Failed: 3 error finding(s) exceed the cap of 2.', $textRun->getOutput());
    }

    /**
     * Verify a total cap below the fixture's finding count fails the run.
     *
     * @throws JsonException - When the CLI output cannot be decoded as JSON.
     *
     * @return void - Asserts exit code 1 and a failure reason whose threshold kind is total.
     */
    public function testTotalCapFails(): void
    {
        $this->writeProjectFile('gate.yaml', "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n  total: 2\n");

        $process = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--format', 'json']);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        $failureReason = $this->failureReason($this->decodeJsonOutput($process));
        self::assertIsArray($failureReason);
        self::assertSame('total', $failureReason['thresholdKind'] ?? null);
    }

    /**
     * Verify --fail-on none overrides the configured error cap.
     *
     * @return void - Asserts a successful exit with --fail-on none and an error cap of two.
     */
    public function testExplicitFailOnOverridesFailureConditions(): void
    {
        $this->writeProjectFile('gate.yaml', "schemaVersion: gruff-php.config.v0.1\nfailureConditions:\n  severityThresholds:\n    error: 2\n");

        $process = $this->runGruff(['analyse', 'src', '--config', 'gate.yaml', '--fail-on', 'none', '--format', 'json']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Verify --fail-on error fails for the undocumented-method fixture without configuration.
     *
     * @return void - Asserts exit code 1 with --no-config and --fail-on error.
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
     * Read the PHP failure-reason array, asserting that any returned entries have string keys.
     *
     * @param array<string, mixed> $report - Decoded analysis report to inspect for extensions.php.topLevel.failureReason.
     *
     * @return array<string, mixed>|null - The array, including an empty array; null if any path component is absent or not an array.
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
     * Write a fixture file, creating parent directories as needed.
     *
     * @param string $path - Project-relative file path.
     * @param string $contents - Bytes to write, replacing any existing file contents.
     *
     * @return void - Writes the fixture file, creating and checking missing parent directories first.
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
