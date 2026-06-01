<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers branch-review behaviour for project-wide dead-code rules.
 */
final class AgentWorkflowDeadCodeCliTest extends TestCase
{
    /**
     * Repository root used to invoke the local CLI under test.
     */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify changed-only review gives internal dead-code rules full context before filtering.
     *
     * @return void
     * @throws JsonException
     */
    public function testBranchReviewChangedOnlyUsesFullProjectContextForInternalDeadCodeRules(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repository = $this->temporaryDirectory();

        try {
            $this->initializeRepository($repository);
            $changedClassPath = $repository . '/src/UsedOnlyFromContext.php';
            $contextCallerPath = $repository . '/src/ContextCaller.php';
            $composerPath = $repository . '/composer.json';
            file_put_contents($composerPath, AgentWorkflowFixtureSources::projectDeadCodeComposerSource());
            file_put_contents($changedClassPath, AgentWorkflowFixtureSources::referencedInternalClassSource());
            file_put_contents($contextCallerPath, AgentWorkflowFixtureSources::internalClassReferenceSource());
            $this->runGit($repository, 'add', 'composer.json', 'src/UsedOnlyFromContext.php', 'src/ContextCaller.php');
            $this->runGit($repository, 'commit', '-m', 'base');

            file_put_contents($changedClassPath, AgentWorkflowFixtureSources::changedReferencedInternalClassSource());

            $report   = $this->runChangedOnlyInternalDeadCodeReview($repository);
            $summary  = $this->objectValue($report, 'summary');
            $findings = $this->objectValue($summary, 'findings');

            self::assertSame([], $this->diagnosticTypes($report));
            self::assertSame(1, $this->intValue($summary, 'filesDiscovered'));
            self::assertSame(0, $this->intValue($findings, 'total'));
        } finally {
            $this->removeDirectory($repository);
        }
    }

    /**
     * Verify changed-only review reports a newly added dead internal declaration.
     *
     * @return void
     * @throws JsonException
     */
    public function testBranchReviewChangedOnlyReportsAddedDeadInternalDeclaration(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repository = $this->temporaryDirectory();

        try {
            $this->initializeRepository($repository);
            $composerPath = $repository . '/composer.json';
            file_put_contents($composerPath, AgentWorkflowFixtureSources::projectDeadCodeComposerSource());
            $this->runGit($repository, 'add', 'composer.json');
            $this->runGit($repository, 'commit', '-m', 'base');

            $addedClassPath = $repository . '/src/AddedDeadInternal.php';
            file_put_contents($addedClassPath, AgentWorkflowFixtureSources::addedDeadInternalClassSource());
            $this->runGit($repository, 'add', 'src/AddedDeadInternal.php');

            $report = $this->runChangedOnlyInternalDeadCodeReview($repository);
            $review = $this->objectValue($report, 'review');
            $counts = $this->objectValue($review, 'counts');

            self::assertSame([], $this->diagnosticTypes($report));
            self::assertSame(1, $this->intValue($counts, 'introduced'));
            self::assertContains('App\\AddedDeadInternal', $this->symbolsFromFindings($review['introduced'] ?? null));
        } finally {
            $this->removeDirectory($repository);
        }
    }

    /**
     * Run the changed-only internal dead-code branch-review command.
     *
     * @param string $repository Working repository path.
     *
     * @return array<string, mixed> - decoded JSON analysis report
     * @throws JsonException
     */
    private function runChangedOnlyInternalDeadCodeReview(string $repository): array
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   '--format=json',
                                   '--fail-on=none',
                                   '--no-config',
                                   '--no-baseline',
                                   '--diff-vs=HEAD',
                                   '--changed-only',
                                   '--include-rule=dead-code.unused-internal-class',
                               ], $repository);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        return $this->objectFromMixed($decoded);
    }

    /**
     * Initialize a Git repository with a source directory.
     *
     * @param string $repository Working repository path.
     *
     * @return void
     */
    private function initializeRepository(string $repository): void
    {
        self::assertTrue(mkdir($repository . '/src', 0777, true));
        $this->runGit($repository, 'init');
        $this->runGit($repository, 'config', 'user.email', 'test@example.com');
        $this->runGit($repository, 'config', 'user.name', 'Gruff Test');
    }

    /**
     * Read an object payload by key.
     *
     * @param array<string, mixed> $payload Decoded JSON object.
     * @param string               $key     Key whose value must be a JSON object.
     *
     * @return array<string, mixed> - nested object payload
     */
    private function objectValue(array $payload, string $key): array
    {
        return $this->objectFromMixed($payload[$key] ?? null);
    }

    /**
     * Read an integer payload by key.
     *
     * @param array<string, mixed> $payload Decoded JSON object.
     * @param string               $key     Key whose value must be an integer.
     *
     * @return int - integer value from the payload
     */
    private function intValue(array $payload, string $key): int
    {
        $payloadValue = $payload[$key] ?? null;
        self::assertIsInt($payloadValue);

        return $payloadValue;
    }

    /**
     * Extract diagnostic type names from decoded JSON output.
     *
     * @param array<string, mixed> $payload Decoded JSON report.
     *
     * @return list<string> - diagnostic type names in report order
     */
    private function diagnosticTypes(array $payload): array
    {
        $types = [];
        foreach ($this->listFromMixed($payload['diagnostics'] ?? null) as $diagnosticValue) {
            $diagnostic = $this->objectFromMixed($diagnosticValue);
            $type       = $diagnostic['type'] ?? null;
            self::assertIsString($type);
            $types[] = $type;
        }

        return $types;
    }

    /**
     * Build symbols from findings for the branch-review workflow.
     *
     * @param mixed $findings Decoded findings value.
     *
     * @return list<mixed> - symbol values in finding order
     */
    private function symbolsFromFindings(mixed $findings): array
    {
        $symbols = [];
        foreach ($this->listFromMixed($findings) as $findingValue) {
            $finding   = $this->objectFromMixed($findingValue);
            $symbols[] = $finding['symbol'] ?? null;
        }

        return $symbols;
    }

    /**
     * Validate a decoded JSON value is a list.
     *
     * @param mixed $payload Decoded JSON value.
     *
     * @return list<mixed> - reindexed list value
     */
    private function listFromMixed(mixed $payload): array
    {
        self::assertIsArray($payload);

        return array_values($payload);
    }

    /**
     * Validate a decoded JSON value is a string-keyed object.
     *
     * @param mixed $payload Decoded JSON value.
     *
     * @return array<string, mixed> - string-keyed object value
     */
    private function objectFromMixed(mixed $payload): array
    {
        self::assertIsArray($payload);

        $object = [];
        foreach ($payload as $key => $entryValue) {
            self::assertIsString($key);
            $object[$key] = $entryValue;
        }

        return $object;
    }

    /**
     * Skip the current test when Git is unavailable.
     *
     * @return void
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
     * Run a Git command in a fixture repository.
     *
     * @param string $workingDirectory Working directory.
     * @param string $arguments        Command arguments.
     *
     * @return void
     */
    private function runGit(string $workingDirectory, string ...$arguments): void
    {
        $process = new Process(array_merge(['git'], $arguments), $workingDirectory);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string - absolute path to a new temporary directory
     */
    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/gruff-review-dead-code-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Filesystem path.
     *
     * @return void
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $directoryEntries = scandir($path);
        self::assertIsArray($directoryEntries);

        foreach ($directoryEntries as $directoryEntry) {
            if ($directoryEntry === '.' || $directoryEntry === '..') {
                continue;
            }

            $childPath = $path . '/' . $directoryEntry;
            if (is_dir($childPath) && !is_link($childPath)) {
                $this->removeDirectory($childPath);
                continue;
            }

            unlink($childPath);
        }

        rmdir($path);
    }
}
