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

final class GitDiffProviderTest extends TestCase
{
    public function testDiffFindingFilterKeepsOnlyTouchedChangedLineFindings(): void
    {
        $diff = new DiffResult(
            active: true,
            mode: 'unstaged',
            base: null,
            changedLines: ['src/Example.php' => [new ChangedLineRange(10, 12)]],
            changedFiles: ['src/Example.php'],
            message: 'test',
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

    private function finding(string $filePath, int $line): Finding
    {
        return new Finding(
            ruleId: 'docs.missing-public-phpdoc',
            message: 'Example finding.',
            filePath: $filePath,
            line: $line,
            severity: Severity::Advisory,
            pillar: Pillar::Documentation,
            tier: RuleTier::V01,
            confidence: Confidence::High,
        );
    }

    private function skipWhenGitIsUnavailable(): void
    {
        $process = new Process(['git', '--version']);
        $process->run();

        if (!$process->isSuccessful()) {
            self::markTestSkipped('git is not available.');
        }
    }

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-diff-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
    }

    private function runGit(string $cwd, string ...$args): void
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

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
