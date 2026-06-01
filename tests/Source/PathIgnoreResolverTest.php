<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Source;

use GruffPhp\Source\PathIgnoreResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers the shared ignore engine: configured globs stay authoritative under
 * include-ignored, built-in directory and generated-file sources, and git rules.
 */
final class PathIgnoreResolverTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /**
     * Remove temporary git repositories created for git-rule tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $tempDir) {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify a configured pattern is reported as the config source with its glob.
     *
     * @return void
     */
    public function testConfiguredPatternIsReportedWithGlob(): void
    {
        $decision = (new PathIgnoreResolver('/project'))->decide('legacy/Bad.php', '/project/legacy/Bad.php', ['legacy/**'], false);

        self::assertTrue($decision->ignored);
        self::assertSame('config', $decision->source);
        self::assertSame('legacy/**', $decision->pattern);
    }

    /**
     * Verify configured ignores remain authoritative even when ignored files are included.
     *
     * @return void
     */
    public function testConfiguredPatternStaysAuthoritativeUnderIncludeIgnored(): void
    {
        $decision = (new PathIgnoreResolver('/project'))->decide('legacy/Bad.php', '/project/legacy/Bad.php', ['legacy/**'], true);

        self::assertTrue($decision->ignored);
        self::assertSame('config', $decision->source);
    }

    /**
     * Verify a built-in directory is reported as the default source and is bypassed by include-ignored.
     *
     * @return void
     */
    public function testDefaultDirectorySourceAndIncludeIgnoredBypass(): void
    {
        $resolver = new PathIgnoreResolver('/project');

        $ignored = $resolver->decide('vendor/acme/V.php', '/project/vendor/acme/V.php', [], false);
        self::assertTrue($ignored->ignored);
        self::assertSame('default', $ignored->source);
        self::assertSame('vendor', $ignored->pattern);

        $included = $resolver->decide('vendor/acme/V.php', '/project/vendor/acme/V.php', [], true);
        self::assertFalse($included->ignored);
    }

    /**
     * Verify a known lockfile is reported as the generated source.
     *
     * @return void
     */
    public function testGeneratedFilenameSource(): void
    {
        $decision = (new PathIgnoreResolver('/project'))->decide('composer.lock', '/project/composer.lock', [], false);

        self::assertTrue($decision->ignored);
        self::assertSame('generated', $decision->source);
        self::assertSame('composer.lock', $decision->pattern);
    }

    /**
     * Verify an unmatched path is reported as not ignored.
     *
     * @return void
     */
    public function testUnmatchedPathIsNotIgnored(): void
    {
        $decision = (new PathIgnoreResolver('/project'))->decide('src/Good.php', '/project/src/Good.php', ['legacy/**'], false);

        self::assertFalse($decision->ignored);
        self::assertNull($decision->source);
    }

    /**
     * Verify the git rule lookup returns the matching pattern and null for tracked paths.
     *
     * @return void
     */
    public function testGitIgnoreRuleReturnsMatchingPattern(): void
    {
        $this->requireGit();

        $root = $this->tempDir();
        $this->runGit($root, ['init', '-q']);
        file_put_contents($root . '/.gitignore', "*.log\n");

        $resolver = new PathIgnoreResolver($root);

        self::assertSame('*.log', $resolver->gitIgnoreRule('debug.log'));
        self::assertNull($resolver->gitIgnoreRule('src/Good.php'));
    }

    /**
     * Require the git executable for git-rule tests.
     *
     * @return void
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
     * Run a git command inside a temporary repository.
     *
     * @param string       $root - Repository root.
     * @param list<string> $args - Git arguments.
     *
     * @return void
     */
    private function runGit(string $root, array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $root);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Create a temporary repository directory tracked for teardown.
     *
     * @return string - absolute path to the freshly created directory, already registered for teardown
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-ignore-resolver-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));
        $this->tempDirs[] = $path;

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path - Directory path.
     *
     * @return void
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            // Nothing to remove when the path was never created or already gone.
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
