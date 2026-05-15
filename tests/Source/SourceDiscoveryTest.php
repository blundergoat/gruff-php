<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Source;

use GruffPhp\Source\SourceDiscovery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers SourceDiscoveryTest behavior.
 */
final class SourceDiscoveryTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /**
     * Remove temporary discovery projects created by tests.
     *
     * @return void No return value.
     */
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $tempDir) {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify discovers PHP files deterministically and ignores default directories.
     *
     * @return void No return value.
     */
    public function testNonGitDiscoveryDiscoversPhpFilesDeterministicallyAndIgnoresDefaultDirectories(): void
    {
        $root = $this->tempDir();
        $this->writeFile($root, 'alpha.php', "<?php\n");
        $this->writeFile($root, 'nested/beta.php', "<?php\n");
        $this->writeFile($root, 'vendor/ignored.php', "<?php\n");
        $this->writeFile($root, 'cache/ignored.php', "<?php\n");
        $this->writeFile($root, 'build/ignored.php', "<?php\n");
        $this->writeFile($root, 'generated/ignored.php', "<?php\n");

        $result = (new SourceDiscovery($root))->discover(['.']);

        self::assertSame([
            'alpha.php',
            'nested/beta.php',
        ], array_map(static fn ($file): string => $file->displayPath, $result->files));

        self::assertContains('vendor', $result->ignoredPaths);
        self::assertContains('cache', $result->ignoredPaths);
        self::assertContains('build', $result->ignoredPaths);
        self::assertContains('generated', $result->ignoredPaths);
        self::assertSame([], $result->missingPaths);
    }

    /**
     * Verify Git worktree discovery follows Git visibility and nested ignore files.
     *
     * @return void No return value.
     */
    public function testGitWorkTreeDiscoveryUsesGitVisibleFilesAndNestedIgnores(): void
    {
        $this->requireGit();

        $root = $this->tempDir();
        $this->runGit($root, ['init', '-q']);
        $this->writeFile($root, '.gitignore', "*.local.json\n.goat-flow/dashboard-state.json\n");
        $this->writeFile($root, '.agents/skills/goat/SKILL.md', "# Skill\n");
        $this->writeFile($root, '.claude/hooks/deny-dangerous.sh', "#!/usr/bin/env bash\n");
        $this->writeFile($root, '.claude/settings.json', "{}\n");
        $this->writeFile($root, '.claude/settings.local.json', "{}\n");
        $this->writeFile($root, '.codex/config.toml', "model = \"gpt\"\n");
        $this->writeFile($root, '.github/workflows/ci.yml', "name: ci\n");
        $this->writeFile($root, '.goat-flow/dashboard-state.json', "{}\n");
        $this->writeFile($root, '.goat-flow/tasks/.gitignore', "*\n!.gitignore\n!README.md\n");
        $this->writeFile($root, '.goat-flow/tasks/README.md', "# Tasks\n");
        $this->writeFile($root, '.goat-flow/tasks/M41.md', "# Local task\n");
        $this->writeFile($root, 'src/Tracked.php', "<?php\n");
        $this->writeFile($root, 'src/Untracked.php', "<?php\n");
        $this->runGit($root, [
            'add',
            '.gitignore',
            '.agents/skills/goat/SKILL.md',
            '.claude/hooks/deny-dangerous.sh',
            '.claude/settings.json',
            '.codex/config.toml',
            '.github/workflows/ci.yml',
            '.goat-flow/tasks/.gitignore',
            '.goat-flow/tasks/README.md',
            'src/Tracked.php',
        ]);

        $result = (new SourceDiscovery($root))->discover(['.']);
        $paths  = array_map(static fn ($file): string => $file->displayPath, $result->files);

        self::assertSame([
            '.agents/skills/goat/SKILL.md',
            '.claude/hooks/deny-dangerous.sh',
            '.claude/settings.json',
            '.codex/config.toml',
            '.github/workflows/ci.yml',
            '.gitignore',
            '.goat-flow/tasks/.gitignore',
            '.goat-flow/tasks/README.md',
            'src/Tracked.php',
            'src/Untracked.php',
        ], $paths);
        self::assertNotContains('.claude/settings.local.json', $paths);
        self::assertNotContains('.goat-flow/dashboard-state.json', $paths);
        self::assertNotContains('.goat-flow/tasks/M41.md', $paths);
        self::assertSame([], $result->missingPaths);
    }

    /**
     * Verify include-ignored opts Git worktrees back into filesystem traversal.
     *
     * @return void No return value.
     */
    public function testIncludeIgnoredScansGitIgnoredFilesThroughFilesystem(): void
    {
        $this->requireGit();

        $root = $this->tempDir();
        $this->runGit($root, ['init', '-q']);
        $this->writeFile($root, '.gitignore', "*.local.json\n");
        $this->writeFile($root, 'visible.php', "<?php\n");
        $this->writeFile($root, 'secret.local.json', "{}\n");

        $default = (new SourceDiscovery($root))->discover(['secret.local.json']);
        self::assertSame([], $default->files);
        self::assertContains('secret.local.json', $default->ignoredPaths);

        $included = (new SourceDiscovery($root))->discover(['secret.local.json'], includeIgnored: true);
        self::assertSame(['secret.local.json'], array_map(static fn ($file): string => $file->displayPath, $included->files));
    }

    /**
     * Verify can include ignored directories explicitly.
     *
     * @return void No return value.
     */
    public function testCanIncludeIgnoredDirectoriesExplicitly(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['.'], includeIgnored: true);

        self::assertSame([
            'alpha.php',
            'build/ignored.php',
            'cache/ignored.php',
            'generated/ignored.php',
            'nested/beta.php',
            'package-lock.json',
            'vendor/ignored.php',
        ], array_map(static fn ($file): string => $file->displayPath, $result->files));
    }

    /**
     * Verify default ignores well known lockfile names.
     *
     * @return void No return value.
     */
    public function testDefaultIgnoresWellKnownLockfileNames(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['.']);

        $paths = array_map(static fn ($file): string => $file->displayPath, $result->files);
        self::assertNotContains('package-lock.json', $paths, 'package-lock.json must be ignored by default.');
        self::assertNotContains('composer.lock', $paths, 'composer.lock must be ignored by default.');
    }

    /**
     * Verify explicit lockfile path is still ignored without include flag.
     *
     * @return void No return value.
     */
    public function testExplicitLockfilePathIsStillIgnoredWithoutIncludeFlag(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['package-lock.json']);

        self::assertSame([], $result->files);
        self::assertContains('package-lock.json', $result->ignoredPaths);
    }

    /**
     * Verify configured ignores use project relative glob patterns.
     *
     * @return void No return value.
     */
    public function testConfiguredIgnoresUseProjectRelativeGlobPatterns(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(
            ['.'],
            includeIgnored:           true,
            configuredIgnorePatterns: ['nested/**', 'build'],
        );

        self::assertSame([
            'alpha.php',
            'cache/ignored.php',
            'generated/ignored.php',
            'package-lock.json',
            'vendor/ignored.php',
        ], array_map(static fn ($file): string => $file->displayPath, $result->files));
        self::assertContains('nested/beta.php', $result->ignoredPaths);
        self::assertContains('build', $result->ignoredPaths);
    }

    /**
     * Verify reports missing paths.
     *
     * @return void No return value.
     */
    public function testReportsMissingPaths(): void
    {
        $root   = $this->fixtureRoot('mixed');
        $result = (new SourceDiscovery($root))->discover(['missing.php']);

        self::assertSame(['missing.php'], $result->missingPaths);
        self::assertTrue($result->hasInputErrors());
    }

    /**
     * Verify source discovery classifies PHP, text config, env, and unsupported files.
     *
     * @return void No return value.
     */
    public function testClassifiesSupportedSourceTypesAndSkipsUnsupportedFiles(): void
    {
        $root = $this->tempDir();
        file_put_contents($root . '/.editorconfig', "root = true\n");
        file_put_contents($root . '/.gitignore', "vendor/\n");
        file_put_contents($root . '/README.md', "# Fixture\n");
        self::assertTrue(mkdir($root . '/bin'));
        file_put_contents($root . '/bin/hook.sh', "#!/usr/bin/env bash\n");
        file_put_contents($root . '/config.toml', "name = \"fixture\"\n");
        file_put_contents($root . '/index.php', "<?php\n");
        file_put_contents($root . '/settings.yaml', "rules: {}\n");
        file_put_contents($root . '/.env.local', "APP_ENV=test\n");
        file_put_contents($root . '/notes.txt', "plain text\n");

        $result = (new SourceDiscovery($root))->discover(['']);
        $files = array_map(
            static fn ($file): array => [$file->displayPath, $file->type, $file->isPhp()],
            $result->files,
        );

        self::assertSame([
            ['.editorconfig', 'text', false],
            ['.env.local', 'text', false],
            ['.gitignore', 'text', false],
            ['README.md', 'text', false],
            ['bin/hook.sh', 'text', false],
            ['config.toml', 'text', false],
            ['index.php', 'php', true],
            ['settings.yaml', 'text', false],
        ], $files);
        self::assertSame([], $result->missingPaths);
        self::assertSame([], $result->ignoredPaths);
        self::assertFalse($result->hasInputErrors());
    }

    /**
     * Verify duplicate absolute and relative requests collapse to canonical files.
     *
     * @return void No return value.
     */
    public function testCanonicalisesDuplicateAbsoluteAndRelativeRequests(): void
    {
        $root = $this->tempDir();
        file_put_contents($root . '/alpha.php', "<?php\n");

        $result = (new SourceDiscovery($root))->discover(['alpha.php', $root . '/alpha.php', './alpha.php']);

        self::assertSame(['alpha.php'], array_map(static fn ($file): string => $file->displayPath, $result->files));
    }

    /**
     * Verify default ignores nested root paths and configured question-mark globs.
     *
     * @return void No return value.
     */
    public function testDefaultAndConfiguredIgnorePatternsAreAppliedToNestedPaths(): void
    {
        $root = $this->tempDir();
        self::assertTrue(mkdir($root . '/src', 0777, true));
        self::assertTrue(mkdir($root . '/.goat-flow/logs', 0777, true));
        file_put_contents($root . '/src/A.php', "<?php\n");
        file_put_contents($root . '/src/B.php', "<?php\n");
        file_put_contents($root . '/.goat-flow/logs/ignored.php', "<?php\n");

        $result = (new SourceDiscovery($root))->discover(['.'], configuredIgnorePatterns: ['src/?.php']);

        self::assertSame([], $result->files);
        self::assertContains('.goat-flow/logs', $result->ignoredPaths);
        self::assertContains('src/A.php', $result->ignoredPaths);
        self::assertContains('src/B.php', $result->ignoredPaths);
    }

    /**
     * Resolve a source-discovery fixture root.
     *
     * @param string $name Fixture name.
     * @return string Fixture value.
     */
    private function fixtureRoot(string $name): string
    {
        $root = realpath(__DIR__ . '/../Fixtures/Source/' . $name);

        self::assertIsString($root);

        return $root;
    }

    /**
     * Create a temporary source-discovery project.
     *
     * @return string Fixture value.
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-source-discovery-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));
        $this->tempDirs[] = $path;

        return $path;
    }

    /**
     * Require the git executable for Git worktree discovery tests.
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
     * Run a git command inside a temporary fixture project.
     *
     * @param string       $root Fixture root.
     * @param list<string> $args Git arguments.
     * @return void No return value.
     */
    private function runGit(string $root, array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $root);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Write a fixture file, creating parent directories as needed.
     *
     * @param string $root     Fixture root.
     * @param string $path     Project-relative file path.
     * @param string $contents File contents.
     * @return void No return value.
     */
    private function writeFile(string $root, string $path, string $contents): void
    {
        $absolutePath = $root . '/' . $path;
        $directory    = dirname($absolutePath);

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }

        file_put_contents($absolutePath, $contents);
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Directory path.
     * @return void No return value.
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            is_dir($child) && !is_link($child) ? $this->removeDir($child) : unlink($child);
        }

        rmdir($path);
    }
}
