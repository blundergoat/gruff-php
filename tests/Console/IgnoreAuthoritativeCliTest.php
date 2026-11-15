<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Exercises configured paths.ignore through the analyse and check-ignore CLI commands.
 * Covers explicit-file exclusion, --changed-ranges, and --include-ignored.
 * Compares ignore details across commands and checks check-ignore exit codes.
 */
final class IgnoreAuthoritativeCliTest extends CliTestCase
{
    /**
     * Project root of the throwaway ignore fixture created per test.
     */
    private string $project = '';

    /**
     * Build a temporary git project with config, file, and directory ignore cases.
     *
     * @return void - Prepares the project root and ignore-policy fixtures for each test.
     */
    protected function setUp(): void
    {
        $this->project = $this->tempDir();
        $this->runGit(['init', '-q']);
        $this->writeProjectFile('.gruff-php.yaml', "schemaVersion: gruff-php.config.v0.1\npaths:\n    ignore:\n        - 'legacy/**'\n");
        $this->writeProjectFile('.gitignore', "*.local.json\nignored-dir/\n");
        $this->writeProjectFile('README.md', "Ignore fixture.\n");
        $this->writeProjectFile('legacy/Bad.php', "<?php\n\nnamespace Demo;\n\nclass Bad\n{\n    public function foo(\$x)\n    {\n        return \$x + 1;\n    }\n}\n");
        $this->writeProjectFile('src/Good.php', "<?php\n\nnamespace Demo;\n\nclass Good\n{\n    public function bar(\$y)\n    {\n        return \$y - 1;\n    }\n}\n");
        $this->writeProjectFile('secret.local.json', "{}\n");
        $this->writeProjectFile('ignored-dir/Hidden.php', "<?php\n");
    }

    /**
     * Remove the temporary ignore fixture.
     *
     * @return void - Cleans up the temporary project when it exists.
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->project);
    }

    /**
     * Verify an explicit configured-ignored file yields no findings and is reported with its pattern.
     *
     * @return void - Asserts no findings and the configured ignore path, source, and pattern.
     * @throws JsonException - If the command's stdout cannot be decoded as JSON.
     */
    public function testExplicitIgnoredFileProducesNoFindingsAndReportsPattern(): void
    {
        $process = $this->runGruff(['analyse', 'legacy/Bad.php', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'];
        $paths    = $this->decodedJsonObjectAt($report, 'paths');
        self::assertIsArray($findings);
        self::assertCount(0, $findings);
        self::assertSame(['legacy/Bad.php'], $paths['ignoredPaths']);
        self::assertSame(
            [['path' => 'legacy/Bad.php', 'reason' => 'config-ignore', 'source' => 'config', 'pattern' => 'legacy/**']],
            $paths['details'],
        );
    }

    /**
     * Verify the same file produces findings once the configured ignore no longer applies.
     *
     * @return void - Asserts findings appear and no ignore details are reported with --no-config.
     * @throws JsonException - If the command's stdout cannot be decoded as JSON.
     */
    public function testSameFileProducesFindingsWhenNotConfiguredIgnored(): void
    {
        $process = $this->runGruff(['analyse', 'legacy/Bad.php', '--no-config', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'];
        $paths    = $this->decodedJsonObjectAt($report, 'paths');
        self::assertIsArray($findings);
        self::assertGreaterThan(0, count($findings));
        self::assertSame([], $paths['details']);
    }

    /**
     * Verify explicit changed ranges do not cause analysis of a configured-ignored file.
     *
     * @return void - Asserts no findings and the file's presence in ignoredPaths.
     * @throws JsonException - If the command's stdout cannot be decoded as JSON.
     */
    public function testChangedRangesOnIgnoredFileProducesNoFindings(): void
    {
        $process = $this->runGruff(['analyse', 'legacy/Bad.php', '--changed-ranges', '1-100', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'];
        $paths    = $this->decodedJsonObjectAt($report, 'paths');
        self::assertIsArray($findings);
        self::assertCount(0, $findings);
        self::assertSame(['legacy/Bad.php'], $paths['ignoredPaths']);
    }

    /**
     * Verify --include-ignored leaves the configured ignore active for the explicit file.
     *
     * @return void - Asserts no findings and the configured ignore details for the file.
     * @throws JsonException - If the command's stdout cannot be decoded as JSON.
     */
    public function testIncludeIgnoredStillHonoursConfiguredIgnore(): void
    {
        $process = $this->runGruff(['analyse', 'legacy/Bad.php', '--include-ignored', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'];
        $paths    = $this->decodedJsonObjectAt($report, 'paths');
        self::assertIsArray($findings);
        self::assertCount(0, $findings);
        self::assertSame(
            [['path' => 'legacy/Bad.php', 'reason' => 'config-ignore', 'source' => 'config', 'pattern' => 'legacy/**']],
            $paths['details'],
        );
    }

    /**
     * Verify check-ignore reports the verdict, source, and pattern for every input path.
     *
     * @return void - Asserts ordered verdicts, sources, and patterns for the supplied paths.
     * @throws JsonException - If the command's stdout cannot be decoded as JSON.
     */
    public function testCheckIgnoreReportsVerdictSourceAndPattern(): void
    {
        $process = $this->runGruff([
            'check-ignore',
            '--format',
            'json',
            'legacy/Bad.php',
            'src/Good.php',
            'secret.local.json',
            'ignored-dir',
            '.git/config',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
                             ['path' => 'legacy/Bad.php', 'ignored' => true, 'source' => 'config', 'pattern' => 'legacy/**'],
                             ['path' => 'src/Good.php', 'ignored' => false, 'source' => null, 'pattern' => null],
                             ['path' => 'secret.local.json', 'ignored' => false, 'source' => null, 'pattern' => null],
                             ['path' => 'ignored-dir', 'ignored' => true, 'source' => 'gitignore', 'pattern' => 'ignored-dir/'],
                             ['path' => '.git/config', 'ignored' => true, 'source' => 'default', 'pattern' => '.git'],
                         ], $this->decodeJsonList($process));
    }

    /**
     * Verify check-ignore and analyse report the same configured source and pattern for one path.
     *
     * @return void - Asserts both commands report the expected configured-ignore result for legacy/Bad.php.
     * @throws JsonException - If either command's stdout cannot be decoded as JSON.
     */
    public function testCheckIgnoreSharesEngineWithAnalyse(): void
    {
        $analyse = $this->runGruff(['analyse', 'legacy/Bad.php', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $analyse->run();
        $analyseReport = $this->decodeJsonOutput($analyse);
        $analysePaths  = $this->decodedJsonObjectAt($analyseReport, 'paths');
        self::assertSame(
            [['path' => 'legacy/Bad.php', 'reason' => 'config-ignore', 'source' => 'config', 'pattern' => 'legacy/**']],
            $analysePaths['details'],
        );

        $checkIgnore = $this->runGruff(['check-ignore', '--format', 'json', 'legacy/Bad.php']);
        $checkIgnore->run();
        self::assertSame(
            [['path' => 'legacy/Bad.php', 'ignored' => true, 'source' => 'config', 'pattern' => 'legacy/**']],
            $this->decodeJsonList($checkIgnore),
        );
    }

    /**
     * Verify check-ignore returns 1 for an unignored path and 2 for an unsupported format.
     *
     * @return void - Asserts the expected exit codes for a no-match result and an invalid-format invocation.
     */
    public function testCheckIgnoreExitCodesMirrorGit(): void
    {
        $noneIgnored = $this->runGruff(['check-ignore', '--format', 'json', 'src/Good.php']);
        $noneIgnored->run();
        self::assertSame(1, $noneIgnored->getExitCode());

        $badFormat = $this->runGruff(['check-ignore', '--format', 'bogus', 'src/Good.php']);
        $badFormat->run();
        self::assertSame(2, $badFormat->getExitCode());
    }

    /**
     * Build a gruff-php subprocess rooted at the temporary fixture project.
     *
     * @param list<string> $args - CLI arguments passed after the binary.
     *
     * @return Process - configured but not yet started, so the caller decides when and how it runs
     */
    private function runGruff(array $args): Process
    {
        return new Process(
            array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $args),
            $this->project,
        );
    }

    /**
     * Decode a check-ignore JSON array response into a list of result rows.
     * Paths not ignored have null source and pattern values.
     *
     * @param Process $process - Completed check-ignore process whose stdout holds the JSON array.
     *
     * @return list<array{path: string, ignored: bool, source: string|null, pattern: string|null}> - One row per input path, in argument order.
     * @throws JsonException - If stdout cannot be decoded as JSON.
     */
    private function decodeJsonList(Process $process): array
    {
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /**
         * @var list<array{path: string, ignored: bool, source: string|null, pattern: string|null}> $decoded - Expected check-ignore result rows.
         */
        return $decoded;
    }

    /**
     * Run a git command inside the fixture project.
     *
     * @param list<string> $args - Arguments passed to git in the fixture project.
     *
     * @return void - Runs the command and asserts a successful exit code.
     */
    private function runGit(array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $this->project);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Write a fixture file, creating parent directories as needed.
     *
     * @param string $path     - Project-relative file path.
     * @param string $contents - Text written to the fixture file.
     *
     * @return void - Writes the supplied text after creating missing parent directories.
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
