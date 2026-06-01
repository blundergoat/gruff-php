<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers the path-scope guarantee end-to-end: configured paths.ignore is
 * authoritative for explicit args, changed-region diffs, and under
 * --include-ignored, and the check-ignore command shares the same engine.
 */
final class IgnoreAuthoritativeCliTest extends CliTestCase
{
    /**
     * Project root of the throwaway ignore fixture created per test.
     */
    private string $project = '';

    /**
     * Build a temporary git project that ignores legacy/** via config and *.log via gitignore.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->project = $this->tempDir();
        $this->runGit(['init', '-q']);
        $this->writeProjectFile('.gruff-php.yaml', "schemaVersion: gruff-php.config.v0.1\npaths:\n    ignore:\n        - 'legacy/**'\n");
        $this->writeProjectFile('.gitignore', "*.log\n");
        $this->writeProjectFile('README.md', "Ignore fixture.\n");
        $this->writeProjectFile('legacy/Bad.php', "<?php\n\nnamespace Demo;\n\nclass Bad\n{\n    public function foo(\$x)\n    {\n        return \$x + 1;\n    }\n}\n");
        $this->writeProjectFile('src/Good.php', "<?php\n\nnamespace Demo;\n\nclass Good\n{\n    public function bar(\$y)\n    {\n        return \$y - 1;\n    }\n}\n");
        $this->writeProjectFile('debug.log', "secret\n");
    }

    /**
     * Remove the temporary ignore fixture.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->project);
    }

    /**
     * Verify an explicit configured-ignored file yields no findings and is reported with its pattern.
     *
     * @return void
     * @throws JsonException
     */
    public function testExplicitIgnoredFileProducesNoFindingsAndReportsPattern(): void
    {
        $process = $this->runGruff(['analyse', 'legacy/Bad.php', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'];
        self::assertIsArray($findings);
        self::assertCount(0, $findings);
        self::assertSame(['legacy/Bad.php'], $report['ignoredPaths']);
        self::assertSame(
            [['path' => 'legacy/Bad.php', 'source' => 'config', 'pattern' => 'legacy/**']],
            $report['ignoredPathDetails'],
        );
    }

    /**
     * Verify the same file produces findings once the configured ignore no longer applies.
     *
     * @return void
     * @throws JsonException
     */
    public function testSameFileProducesFindingsWhenNotConfiguredIgnored(): void
    {
        $process = $this->runGruff(['analyse', 'legacy/Bad.php', '--no-config', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'];
        self::assertIsArray($findings);
        self::assertGreaterThan(0, count($findings));
        self::assertSame([], $report['ignoredPathDetails']);
    }

    /**
     * Verify a changed-region diff touching an ignored file still yields no findings for it.
     *
     * @return void
     * @throws JsonException
     */
    public function testChangedRangesOnIgnoredFileProducesNoFindings(): void
    {
        $process = $this->runGruff(['analyse', 'legacy/Bad.php', '--changed-ranges', '1-100', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'];
        self::assertIsArray($findings);
        self::assertCount(0, $findings);
        self::assertSame(['legacy/Bad.php'], $report['ignoredPaths']);
    }

    /**
     * Verify --include-ignored never overrides a configured paths.ignore match.
     *
     * @return void
     * @throws JsonException
     */
    public function testIncludeIgnoredStillHonoursConfiguredIgnore(): void
    {
        $process = $this->runGruff(['analyse', 'legacy/Bad.php', '--include-ignored', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'];
        self::assertIsArray($findings);
        self::assertCount(0, $findings);
        self::assertSame(
            [['path' => 'legacy/Bad.php', 'source' => 'config', 'pattern' => 'legacy/**']],
            $report['ignoredPathDetails'],
        );
    }

    /**
     * Verify check-ignore reports the verdict, source, and pattern for every input path.
     *
     * @return void
     * @throws JsonException
     */
    public function testCheckIgnoreReportsVerdictSourceAndPattern(): void
    {
        $process = $this->runGruff(['check-ignore', '--format', 'json', 'legacy/Bad.php', 'src/Good.php', 'debug.log']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
                             ['path' => 'legacy/Bad.php', 'ignored' => true, 'source' => 'config', 'pattern' => 'legacy/**'],
                             ['path' => 'src/Good.php', 'ignored' => false, 'source' => null, 'pattern' => null],
                             ['path' => 'debug.log', 'ignored' => true, 'source' => 'gitignore', 'pattern' => '*.log'],
                         ], $this->decodeJsonList($process));
    }

    /**
     * Verify check-ignore and analyse agree on source and pattern for the same path (shared engine).
     *
     * @return void
     * @throws JsonException
     */
    public function testCheckIgnoreSharesEngineWithAnalyse(): void
    {
        $analyse = $this->runGruff(['analyse', 'legacy/Bad.php', '--format', 'json', '--no-baseline', '--fail-on', 'none']);
        $analyse->run();
        self::assertSame(
            [['path' => 'legacy/Bad.php', 'source' => 'config', 'pattern' => 'legacy/**']],
            $this->decodeJsonOutput($analyse)['ignoredPathDetails'],
        );

        $checkIgnore = $this->runGruff(['check-ignore', '--format', 'json', 'legacy/Bad.php']);
        $checkIgnore->run();
        self::assertSame(
            [['path' => 'legacy/Bad.php', 'ignored' => true, 'source' => 'config', 'pattern' => 'legacy/**']],
            $this->decodeJsonList($checkIgnore),
        );
    }

    /**
     * Verify check-ignore exit codes mirror git check-ignore (1 when nothing matches, 2 on error).
     *
     * @return void
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
     *
     * @param Process $process - Completed check-ignore process whose stdout holds the JSON array.
     *
     * @return list<array{path: string, ignored: bool, source: string|null, pattern: string|null}> - one row per input path in argument order;
     *                          source/pattern are null when the path is not ignored
     * @throws JsonException
     */
    private function decodeJsonList(Process $process): array
    {
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var list<array{path: string, ignored: bool, source: string|null, pattern: string|null}> $decoded The check-ignore JSON output is always a list of path-decision rows. */
        return $decoded;
    }

    /**
     * Run a git command inside the fixture project.
     *
     * @param list<string> $args - Git arguments.
     *
     * @return void
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
