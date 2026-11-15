<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers default analyse discovery in a temporary Git repository.
 * The fixture mixes tracked files, an untracked skill, and ignored local state.
 * Checks the discovery count and rejects ignored state paths in reported findings.
 */
final class AnalyseCliGitDiscoveryTest extends CliTestCase
{
    /** Expected discovery count for tracked files plus the untracked skill file. */
    private const TRACKED_FIXTURE_FILES = 5;

    /**
     * Verify analyse command uses Git-visible files for default discovery.
     *
     * @return void - Asserts five discovered files and no findings referencing the ignored state file.
     * @throws JsonException - If decoding the CLI JSON report fails.
     */
    public function testAnalyseCommandUsesGitVisibleFilesForDefaultDiscovery(): void
    {
        $this->requireGit();

        $project = $this->tempDir();

        try {
            $this->runGit($project, ['init', '-q']);
            $this->writeProjectFile($project, '.gitignore', "*.local.json\n");
            $this->writeProjectFile($project, '.agents/skills/goat/SKILL.md', "# Skill\n");
            $this->writeProjectFile($project, '.codex/config.toml', "model = \"gpt\"\n");
            $this->writeProjectFile($project, '.github/workflows/ci.yml', "name: ci\n");
            $this->writeProjectFile($project, '.codex/state.local.json', "{\"local\":true}\n");
            $this->writeProjectFile($project, 'src/App.php', "<?php\n");
            $this->runGit($project, [
                'add',
                '.gitignore',
                '.codex/config.toml',
                '.github/workflows/ci.yml',
                'src/App.php',
            ]);

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                '.',
                '--no-config',
                '--no-baseline',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $report  = $this->decodeJsonOutput($process);
            $summary = $report['summary'] ?? null;
            self::assertIsArray($summary);
            self::assertSame(self::TRACKED_FIXTURE_FILES, $summary['discoveredFiles'] ?? null);
            $findings = $report['findings'] ?? null;
            self::assertIsArray($findings);
            foreach ($findings as $index => $finding) {
                self::assertIsArray($finding, "finding #{$index} must be an array");
                self::assertNotSame(
                    '.codex/state.local.json',
                    $finding['file'] ?? null,
                    "finding #{$index} unexpectedly references the git-ignored state file",
                );
            }
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Require the git executable for Git-backed CLI tests.
     *
     * @return void - Skips the test when git --version exits unsuccessfully.
     */
    private function requireGit(): void
    {
        $process = new Process(['git', '--version']);
        $process->run();

        if (!$process->isSuccessful()) {
            self::markTestSkipped('git is not available.');
        }
    }

    /**
     * Run a git command in a temporary project.
     *
     * @param string       $project - Temporary project root used as the Git working directory.
     * @param list<string> $args    - Git subcommand and options appended to the invocation.
     *
     * @return void - Asserts that the Git command exits successfully.
     */
    private function runGit(string $project, array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $project);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Write a temporary project file, creating parent directories as needed.
     *
     * @param string $project  - Temporary project root that receives the file.
     * @param string $path     - Project-relative path.
     * @param string $contents - Fixture content written to the resolved project path.
     *
     * @return void - Writes fixture contents after asserting any required directory creation succeeds.
     */
    private function writeProjectFile(string $project, string $path, string $contents): void
    {
        $absolutePath = $project . '/' . $path;
        $directory    = dirname($absolutePath);

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }

        file_put_contents($absolutePath, $contents);
    }
}
