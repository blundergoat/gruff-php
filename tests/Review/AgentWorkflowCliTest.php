<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AgentWorkflowCliTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify list rules JSON includes identifier quality metadata.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testListRulesJsonIncludesIdentifierQualityMetadata(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff', 'list-rules', '--format=json'], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $payload  = $this->decodeJson($process);
        $ruleRows = $this->listValue($payload, 'rules');
        /** @var array<string, array<string, mixed>> $rules */
        $rules = [];

        foreach ($ruleRows as $ruleRow) {
            $rule                                   = $this->stringKeyedArray($ruleRow);
            $rules[$this->stringValue($rule, 'id')] = $rule;
        }

        self::assertArrayHasKey('naming.identifier-quality', $rules);
        self::assertSame('naming', $rules['naming.identifier-quality']['pillar']);
        self::assertSame('advisory', $rules['naming.identifier-quality']['defaultSeverity']);
        self::assertNotSame('', $rules['naming.identifier-quality']['description']);
    }

    /**
     * Verify display filters are report metadata and do not enable rules.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testDisplayFiltersAreReportMetadataAndDoNotEnableRules(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'analyse',
            'tests/Fixtures/Naming',
            '--format=json',
            '--fail-on=none',
            '--no-config',
            '--no-baseline',
            '--include-rule=naming.identifier-quality',
            '--min-severity=warning',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report    = $this->decodeJson($process);
        $summary   = $this->arrayValue($report, 'summary');
        $findings  = $this->arrayValue($summary, 'findings');
        $run       = $this->arrayValue($report, 'run');
        $filters   = $this->arrayValue($run, 'filters');
        $score     = $this->arrayValue($report, 'score');
        $composite = $this->arrayValue($score, 'composite');

        self::assertSame(0, $this->intValue($findings, 'total'));
        self::assertTrue($this->boolValue($filters, 'active'));
        self::assertSame('warning', $this->stringValue($filters, 'minSeverity'));
        self::assertSame(['naming.identifier-quality'], $this->listValue($filters, 'includeRules'));
        self::assertSame('C', $this->stringValue($composite, 'grade'));
    }

    /**
     * Verify SARIF output is JSON and contains findings.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testSarifOutputIsJsonAndContainsFindings(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'analyse',
            'tests/Fixtures/Naming',
            '--format=sarif',
            '--fail-on=none',
            '--no-config',
            '--no-baseline',
            '--include-rule=naming.identifier-quality',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $sarif    = $this->decodeJson($process);
        $runs     = $this->listValue($sarif, 'runs');
        $firstRun = $this->stringKeyedArray($runs[0] ?? null);

        self::assertSame('2.1.0', $this->stringValue($sarif, 'version'));
        self::assertNotSame([], $this->listValue($firstRun, 'results'));
    }

    /**
     * Verify paths relative to normalizes JSON finding files.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testPathsRelativeToNormalizesJsonFindingFiles(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'analyse',
            self::PROJECT_ROOT . '/tests/Fixtures/Naming',
            '--format=json',
            '--fail-on=none',
            '--no-config',
            '--no-baseline',
            '--include-rule=naming.identifier-quality',
            '--paths-relative-to=' . self::PROJECT_ROOT,
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report = $this->decodeJson($process);

        foreach ($this->listValue($report, 'findings') as $findingValue) {
            $finding = $this->stringKeyedArray($findingValue);
            self::assertStringStartsWith('tests/Fixtures/Naming/', $this->stringValue($finding, 'file'));
        }
    }

    /**
     * Verify branch review keeps line shifted finding unchanged and reports introduced.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testBranchReviewKeepsLineShiftedFindingUnchangedAndReportsIntroduced(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            $this->runGit($repo, 'init');
            $this->runGit($repo, 'config', 'user.email', 'test@example.com');
            $this->runGit($repo, 'config', 'user.name', 'Gruff Test');
            file_put_contents($repo . '/src/Example.php', $this->baseExampleSource());
            $this->runGit($repo, 'add', 'src/Example.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/Example.php', $this->changedExampleSource());

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff',
                'analyse',
                'src',
                '--format=json',
                '--fail-on=none',
                '--no-config',
                '--no-baseline',
                '--diff-vs=HEAD',
                '--changed-only',
            ], $repo);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report = $this->decodeJson($process);
            $review = $this->arrayValue($report, 'review');
            $counts = $this->arrayValue($review, 'counts');

            self::assertSame('HEAD', $this->stringValue($review, 'base'));
            self::assertSame(1, $this->intValue($counts, 'introduced'));
            self::assertGreaterThanOrEqual(1, $this->intValue($counts, 'unchanged'));

            $introducedSymbols = $this->symbolsFromFindings($review['introduced'] ?? null);
            $unchangedSymbols  = $this->symbolsFromFindings($review['unchanged'] ?? null);

            self::assertContains('Example::newRisk()', $introducedSymbols);
            self::assertContains('Example::calculate()', $unchangedSymbols);

            $markdown = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff',
                'analyse',
                'src',
                '--format=markdown',
                '--fail-on=none',
                '--no-config',
                '--no-baseline',
                '--diff-vs=HEAD',
                '--changed-only',
            ], $repo);
            $markdown->run();

            self::assertSame(0, $markdown->getExitCode(), $markdown->getOutput() . $markdown->getErrorOutput());
            self::assertStringContainsString('**Branch review:** base `HEAD`, 1 introduced, 0 removed', $markdown->getOutput());
            self::assertStringContainsString('### Introduced findings', $markdown->getOutput());
            self::assertStringContainsString('Example::newRisk()', $markdown->getOutput());
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify branch review reports removed findings.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testBranchReviewReportsRemovedFindings(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            $this->runGit($repo, 'init');
            $this->runGit($repo, 'config', 'user.email', 'test@example.com');
            $this->runGit($repo, 'config', 'user.name', 'Gruff Test');
            file_put_contents($repo . '/src/Example.php', $this->removedBaseExampleSource());
            $this->runGit($repo, 'add', 'src/Example.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/Example.php', $this->baseExampleSource());

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff',
                'analyse',
                'src',
                '--format=json',
                '--fail-on=none',
                '--no-config',
                '--no-baseline',
                '--diff-vs=HEAD',
                '--changed-only',
            ], $repo);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report = $this->decodeJson($process);
            $review = $this->arrayValue($report, 'review');
            $counts = $this->arrayValue($review, 'counts');

            self::assertSame(0, $this->intValue($counts, 'introduced'));
            self::assertSame(1, $this->intValue($counts, 'removed'));
            self::assertGreaterThanOrEqual(1, $this->intValue($counts, 'unchanged'));
            self::assertContains('Example::oldRisk()', $this->symbolsFromFindings($review['removed'] ?? null));
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify branch review added file does not fail base snapshot.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testBranchReviewAddedFileDoesNotFailBaseSnapshot(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            $this->runGit($repo, 'init');
            $this->runGit($repo, 'config', 'user.email', 'test@example.com');
            $this->runGit($repo, 'config', 'user.name', 'Gruff Test');
            file_put_contents($repo . '/src/Existing.php', $this->baseExampleSource());
            $this->runGit($repo, 'add', 'src/Existing.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/NewRisk.php', $this->addedRiskSource());
            $this->runGit($repo, 'add', 'src/NewRisk.php');

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff',
                'analyse',
                'src/NewRisk.php',
                '--format=json',
                '--fail-on=none',
                '--no-config',
                '--no-baseline',
                '--diff-vs=HEAD',
                '--changed-only',
            ], $repo);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report = $this->decodeJson($process);
            self::assertSame([], $this->diagnosticTypes($report));

            $review = $this->arrayValue($report, 'review');
            $counts = $this->arrayValue($review, 'counts');

            self::assertGreaterThanOrEqual(1, $this->intValue($counts, 'introduced'));
            self::assertSame(0, $this->intValue($counts, 'removed'));
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify branch review changed only without paths scopes current scan to changed files.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testBranchReviewChangedOnlyWithoutPathsScopesCurrentScanToChangedFiles(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            $this->runGit($repo, 'init');
            $this->runGit($repo, 'config', 'user.email', 'test@example.com');
            $this->runGit($repo, 'config', 'user.name', 'Gruff Test');
            file_put_contents($repo . '/src/Target.php', $this->baseExampleSource());
            file_put_contents($repo . '/src/Unrelated.php', $this->addedRiskSource());
            $this->runGit($repo, 'add', 'src/Target.php', 'src/Unrelated.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/Target.php', $this->changedExampleSource());

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff',
                'analyse',
                '--format=json',
                '--fail-on=none',
                '--no-config',
                '--no-baseline',
                '--diff-vs=HEAD',
                '--changed-only',
            ], $repo);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report = $this->decodeJson($process);
            self::assertSame([], $this->diagnosticTypes($report));

            $summary = $this->arrayValue($report, 'summary');
            self::assertSame(1, $this->intValue($summary, 'filesDiscovered'));

            $review = $this->arrayValue($report, 'review');
            $counts = $this->arrayValue($review, 'counts');

            self::assertSame(1, $this->intValue($counts, 'introduced'));
            self::assertContains('Example::newRisk()', $this->symbolsFromFindings($review['introduced'] ?? null));
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify branch review deleted file reports removed findings.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testBranchReviewDeletedFileReportsRemovedFindings(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            $this->runGit($repo, 'init');
            $this->runGit($repo, 'config', 'user.email', 'test@example.com');
            $this->runGit($repo, 'config', 'user.name', 'Gruff Test');
            file_put_contents($repo . '/src/Deleted.php', $this->removedBaseExampleSource());
            $this->runGit($repo, 'add', 'src/Deleted.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            unlink($repo . '/src/Deleted.php');

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff',
                'analyse',
                'src',
                '--format=json',
                '--fail-on=none',
                '--no-config',
                '--no-baseline',
                '--diff-vs=HEAD',
                '--changed-only',
            ], $repo);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report = $this->decodeJson($process);
            self::assertSame([], $this->diagnosticTypes($report));

            $review = $this->arrayValue($report, 'review');
            $counts = $this->arrayValue($review, 'counts');

            self::assertGreaterThanOrEqual(1, $this->intValue($counts, 'removed'));
            self::assertContains('Example::oldRisk()', $this->symbolsFromFindings($review['removed'] ?? null));

            $explicitPathProcess = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff',
                'analyse',
                'src/Deleted.php',
                '--format=json',
                '--fail-on=none',
                '--no-config',
                '--no-baseline',
                '--diff-vs=HEAD',
                '--changed-only',
            ], $repo);
            $explicitPathProcess->run();

            self::assertSame(0, $explicitPathProcess->getExitCode(), $explicitPathProcess->getOutput() . $explicitPathProcess->getErrorOutput());
            $explicitPathReport = $this->decodeJson($explicitPathProcess);
            self::assertSame([], $this->diagnosticTypes($explicitPathReport));

            $explicitPathReview = $this->arrayValue($explicitPathReport, 'review');
            $explicitPathCounts = $this->arrayValue($explicitPathReview, 'counts');

            self::assertGreaterThanOrEqual(1, $this->intValue($explicitPathCounts, 'removed'));
            self::assertContains('Example::oldRisk()', $this->symbolsFromFindings($explicitPathReview['removed'] ?? null));
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify review mode reports non Git diagnostic.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testReviewModeReportsNonGitDiagnostic(): void
    {
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            file_put_contents($repo . '/src/Example.php', $this->baseExampleSource());

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff',
                'analyse',
                'src',
                '--format=json',
                '--fail-on=none',
                '--no-config',
                '--no-baseline',
                '--diff-vs=HEAD',
            ], $repo);
            $process->run();

            self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report = $this->decodeJson($process);

            self::assertContains('diff-mode-error', $this->diagnosticTypes($report));
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify review mode invalid option combinations fail early.
     *
     * @return void No return value.
     */
    public function testReviewModeInvalidOptionCombinationsFailEarly(): void
    {
        $changedOnly = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'analyse',
            '--changed-only',
        ], self::PROJECT_ROOT);
        $changedOnly->run();

        self::assertSame(2, $changedOnly->getExitCode(), $changedOnly->getOutput() . $changedOnly->getErrorOutput());
        self::assertStringContainsString('--changed-only requires --diff-vs.', $changedOnly->getOutput());

        $diffConflict = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'analyse',
            '--diff',
            'working-tree',
            '--diff-vs=HEAD',
        ], self::PROJECT_ROOT);
        $diffConflict->run();

        self::assertSame(2, $diffConflict->getExitCode(), $diffConflict->getOutput() . $diffConflict->getErrorOutput());
        self::assertStringContainsString('--diff and --diff-vs are mutually exclusive.', $diffConflict->getOutput());
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function decodeJson(Process $process): array
    {
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        return $this->stringKeyedArray($decoded);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function arrayValue(array $payload, string $key): array
    {
        return $this->stringKeyedArray($payload[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function boolValue(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? null;
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intValue(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        self::assertIsInt($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<mixed>
     */
    private function listValue(array $payload, string $key): array
    {
        return $this->mixedList($payload[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function diagnosticTypes(array $payload): array
    {
        $types = [];

        foreach ($this->listValue($payload, 'diagnostics') as $diagnosticValue) {
            $diagnostic = $this->stringKeyedArray($diagnosticValue);
            $types[]    = $this->stringValue($diagnostic, 'type');
        }

        return $types;
    }

    /**
     * @return list<mixed>
     */
    private function symbolsFromFindings(mixed $findings): array
    {
        $symbols = [];

        foreach ($this->mixedList($findings) as $findingValue) {
            $finding   = $this->stringKeyedArray($findingValue);
            $symbols[] = $finding['symbol'] ?? null;
        }

        return $symbols;
    }

    /**
     * @return list<mixed>
     */
    private function mixedList(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        self::assertIsArray($value);

        $result = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * Return source code for the base review fixture.
     *
     * @return string Fixture value.
     */
    private function baseExampleSource(): string
    {
        return <<<'PHP'
<?php

final class Example
{
    public function calculate(string $left, string $right): string
    {
        return $left . $right;
    }
}
PHP;
    }

    /**
     * Return source code for the changed review fixture.
     *
     * @return string Fixture value.
     */
    private function changedExampleSource(): string
    {
        return <<<'PHP'
<?php



final class Example
{
    public function calculate(string $left, string $right): string
    {
        return $left . $right;
    }

    public function newRisk(string $left, string $right): string
    {
        return $left . $right;
    }
}
PHP;
    }

    /**
     * Return source code for the removed-base review fixture.
     *
     * @return string Fixture value.
     */
    private function removedBaseExampleSource(): string
    {
        return <<<'PHP'
<?php

final class Example
{
    public function calculate(string $left, string $right): string
    {
        return $left . $right;
    }

    public function oldRisk(string $left, string $right): string
    {
        return $left . $right;
    }
}
PHP;
    }

    /**
     * Return source code for an added risky review fixture.
     *
     * @return string Fixture value.
     */
    private function addedRiskSource(): string
    {
        return <<<'PHP'
<?php

final class NewRisk
{
    public function decode(string $payload): mixed
    {
        return unserialize($payload);
    }
}
PHP;
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
     * Create a temporary directory for filesystem assertions.
     *
     * @return string Fixture value.
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-review-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
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
