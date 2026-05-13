<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Diff;

use GruffPhp\Diff\ChangedLineRange;
use GruffPhp\Diff\DiffException;
use GruffPhp\Diff\DiffFindingFilter;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Diff\GitDiffProvider;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers GitDiffProviderTest behavior.
 */
final class GitDiffProviderTest extends TestCase
{
    /**
     * Verify diff finding filter keeps only touched changed line findings.
     *
     * @return void No return value.
     */
    public function testDiffFindingFilterKeepsOnlyTouchedChangedLineFindings(): void
    {
        $diff = new DiffResult(
            active:       true,
            mode:         'unstaged',
            base:         null,
            changedLines: ['src/Example.php' => [new ChangedLineRange(10, 12)]],
            changedFiles: ['src/Example.php'],
            message:      'test',
        );
        $findings = [
            $this->finding('src/Example.php', 11),
            $this->finding('src/Example.php', 20),
            $this->finding('src/Other.php', 11),
        ];

        $filtered = (new DiffFindingFilter())->filter($findings, $diff);

        self::assertCount(1, $filtered);
        self::assertSame(11, $filtered[0]->line);
    }

    /**
     * Verify Git diff provider parses unstaged changed lines.
     *
     * @return void No return value.
     */
    public function testGitDiffProviderParsesUnstagedChangedLines(): void
    {
        $this->skipWhenGitIsUnavailable();
        $tempDir = $this->tempDir();

        try {
            $this->runGit($tempDir, 'init');
            $this->runGit($tempDir, 'config', 'user.email', 'test@example.com');
            $this->runGit($tempDir, 'config', 'user.name', 'Gruff Test');
            file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n");
            $this->runGit($tempDir, 'add', 'Example.php');
            $this->runGit($tempDir, 'commit', '-m', 'initial');

            file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function value(): int\n    {\n        return 2;\n    }\n}\n");

            $diff = (new GitDiffProvider())->changedLines($tempDir, 'unstaged');

            self::assertTrue($diff->active);
            self::assertSame(['Example.php'], $diff->changedFiles);
            self::assertNotSame([], $diff->rangesFor('Example.php'));
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify Git diff provider parses staged, working-tree, and base-ref modes.
     *
     * @return void No return value.
     */
    public function testGitDiffProviderParsesModesAndDeletedFiles(): void
    {
        $this->skipWhenGitIsUnavailable();
        $tempDir = $this->tempDir();

        try {
            $this->initialiseRepository($tempDir);
            file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function value(): int\n    {\n        return 2;\n    }\n}\n");
            file_put_contents($tempDir . '/Added.php', "<?php\nfinal class Added {}\n");
            unlink($tempDir . '/DeleteMe.php');
            $this->runGit($tempDir, 'add', 'Example.php', 'Added.php', 'DeleteMe.php');

            $staged = (new GitDiffProvider())->changedLines($tempDir, 'staged');

            self::assertSame('staged', $staged->mode);
            self::assertNull($staged->base);
            self::assertSame(['Added.php', 'DeleteMe.php', 'Example.php'], $staged->changedFiles);
            self::assertSame(0, $staged->rangesFor('DeleteMe.php')[0]->startLine);
            self::assertSame(0, $staged->rangesFor('DeleteMe.php')[0]->endLine);
            self::assertNotSame([], $staged->rangesFor('Added.php'));
            self::assertNotSame([], $staged->rangesFor('Example.php'));

            $this->runGit($tempDir, 'commit', '-m', 'second');
            file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function value(): int\n    {\n        return 3;\n    }\n}\n");

            $workingTree = (new GitDiffProvider())->changedLines($tempDir, 'working-tree');
            $baseRef = (new GitDiffProvider())->changedLines($tempDir, 'HEAD~1');

            self::assertSame('working-tree', $workingTree->mode);
            self::assertSame(['Example.php'], $workingTree->changedFiles);
            self::assertSame('base-ref', $baseRef->mode);
            self::assertSame('HEAD~1', $baseRef->base);
            self::assertContains('Added.php', $baseRef->changedFiles);
            self::assertContains('DeleteMe.php', $baseRef->changedFiles);
            self::assertContains('Example.php', $baseRef->changedFiles);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify diff finding filter uses changed files when line ranges are unavailable.
     *
     * @return void No return value.
     */
    public function testDiffFindingFilterFallsBackToChangedFiles(): void
    {
        $diff = new DiffResult(
            active:       true,
            mode:         'staged',
            base:         null,
            changedLines: ['src/Example.php' => []],
            changedFiles: ['src/Example.php'],
            message:      'test',
        );
        $findings = [
            $this->finding('src/Example.php', 20),
            $this->finding('src/Other.php', 20),
        ];

        $filtered = (new DiffFindingFilter())->filter($findings, $diff);

        self::assertCount(1, $filtered);
        self::assertSame('src/Example.php', $filtered[0]->filePath);
    }

    /**
     * Verify inactive diff leaves findings unchanged.
     *
     * @return void No return value.
     */
    public function testDiffFindingFilterLeavesInactiveDiffUnchanged(): void
    {
        $findings = [$this->finding('src/Example.php', 20)];

        self::assertSame($findings, (new DiffFindingFilter())->filter($findings, DiffResult::inactive()));
    }

    /**
     * Verify Git diff provider reports non Git directory.
     *
     * @return void No return value.
     */
    public function testGitDiffProviderReportsNonGitDirectory(): void
    {
        $tempDir = $this->tempDir();

        try {
            $this->expectException(DiffException::class);
            $this->expectExceptionMessage('Diff mode requires a git working tree.');

            (new GitDiffProvider())->changedLines($tempDir, 'unstaged');
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeDiffModeProvider(): array
    {
        return [
            'no-renames option' => ['--no-renames'],
            'upload-pack option' => ['--upload-pack=anything'],
            'leading hyphen ref' => ['-x'],
            'whitespace ref' => ['feature branch'],
        ];
    }

    /**
     * Verify Git diff provider rejects unsafe base refs.
     *
     * @param string $mode Unsafe diff mode argument.
     * @return void No return value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeDiffModeProvider')]
    public function testGitDiffProviderRejectsUnsafeBaseRefs(string $mode): void
    {
        $this->skipWhenGitIsUnavailable();
        $tempDir = $this->tempDir();

        try {
            $this->runGit($tempDir, 'init');

            self::expectException(DiffException::class);
            self::expectExceptionMessage('safe git ref name');

            (new GitDiffProvider())->changedLines($tempDir, $mode);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Build a finding fixture for assertions.
     *
     * @param string $filePath Finding file path.
     * @param int $line Finding line number.
     * @return Finding Fixture value.
     */
    private function finding(string $filePath, int $line): Finding
    {
        return new Finding(
            ruleId:     'docs.missing-public-phpdoc',
            message:    'Example finding.',
            filePath:   $filePath,
            line:       $line,
            severity:   Severity::Advisory,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
    }

    /**
     * Skip the current test when Git is unavailable.
     *
     * @return void No return value.
     */
    private function skipWhenGitIsUnavailable(): void
    {
        $process = new Process(['git', '--version']);
        $process->run();

        if (!$process->isSuccessful()) {
            self::markTestSkipped('git is not available.');
        }
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string Fixture value.
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-diff-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Run a Git command in a fixture repository.
     *
     * @param string $cwd Working directory.
     * @param string $args Command arguments.
     * @return void No return value.
     */
    private function runGit(string $cwd, string ...$args): void
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Initialise a repository with two committed PHP files.
     *
     * @param string $tempDir Fixture repository root.
     * @return void No return value.
     */
    private function initialiseRepository(string $tempDir): void
    {
        $this->runGit($tempDir, 'init');
        $this->runGit($tempDir, 'config', 'user.email', 'test@example.com');
        $this->runGit($tempDir, 'config', 'user.name', 'Gruff Test');
        file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n");
        file_put_contents($tempDir . '/DeleteMe.php', "<?php\nfinal class DeleteMe {}\n");
        $this->runGit($tempDir, 'add', 'Example.php', 'DeleteMe.php');
        $this->runGit($tempDir, 'commit', '-m', 'initial');
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Filesystem path.
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
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
