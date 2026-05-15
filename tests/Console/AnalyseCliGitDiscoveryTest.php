<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers analyse CLI behavior backed by Git-aware source discovery.
 */
final class AnalyseCliGitDiscoveryTest extends CliTestCase
{
    /**
     * Verify analyse command uses Git-visible files for default discovery.
     *
     * @return void No return value.
     * @throws JsonException
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
            self::assertSame(5, $summary['filesDiscovered'] ?? null);
            $findings = $report['findings'] ?? null;
            self::assertIsArray($findings);
            foreach ($findings as $finding) {
                self::assertIsArray($finding);
                self::assertNotSame('.codex/state.local.json', $finding['file'] ?? null);
            }
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Require the git executable for Git-backed CLI tests.
     *
     * @return void No return value.
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
     * @param string       $project Project root.
     * @param list<string> $args    Git arguments.
     * @return void No return value.
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
     * @param string $project  Project root.
     * @param string $path     Project-relative path.
     * @param string $contents File contents.
     * @return void No return value.
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
