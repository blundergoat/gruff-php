<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers agent workflow CLI flows: list-rules metadata, display filters, SARIF output, branch-review introduced/removed/line-shifted findings,
 * changed-only scoping, and review-mode option validation.
 */
final class AgentWorkflowCliTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify list rules JSON includes identifier quality metadata.
     *
     * @return void
     * @throws JsonException
     */
    public function testListRulesJsonIncludesIdentifierQualityMetadata(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list-rules', '--format=json'], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $payload  = $this->decodeJson($process);
        $ruleRows = $this->listValue($payload, 'rules');
        /** @var array<string, array<string, mixed>> $rules Rules indexed by ID for direct assertions. */
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
     * @return void
     * @throws JsonException
     */
    public function testDisplayFiltersAreReportMetadataAndDoNotEnableRules(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
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
        $report      = $this->decodeJson($process);
        $summary     = $this->arrayValue($report, 'summary');
        $findings    = $this->arrayValue($summary, 'findings');
        $runMetadata = $this->arrayValue($report, 'run');
        $filters     = $this->arrayValue($runMetadata, 'filters');
        $score       = $this->arrayValue($report, 'score');
        $composite   = $this->arrayValue($score, 'composite');

        self::assertSame(0, $this->intValue($findings, 'total'));
        self::assertTrue($filters['active'] ?? null);
        self::assertSame('warning', $this->stringValue($filters, 'minSeverity'));
        self::assertSame(['naming.identifier-quality'], $this->listValue($filters, 'includeRules'));
        self::assertSame('F', $this->stringValue($composite, 'grade'));
    }

    /**
     * Verify SARIF output is JSON and contains findings.
     *
     * @return void
     * @throws JsonException
     */
    public function testSarifOutputIsJsonAndContainsFindings(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
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
     * @return void
     * @throws JsonException
     */
    public function testPathsRelativeToNormalizesJsonFindingFiles(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
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

        $files           = array_map(
            fn(mixed $findingValue): string => $this->stringValue($this->stringKeyedArray($findingValue), 'file'),
            $this->listValue($report, 'findings'),
        );
        $unexpectedFiles = array_values(array_filter(
                                            $files,
                                            static fn(string $file): bool => !str_starts_with($file, 'tests/Fixtures/Naming/'),
                                        ));

        self::assertSame([], $unexpectedFiles, sprintf('Unexpected finding files: %s', implode(', ', $unexpectedFiles)));
    }

    /**
     * Verify branch review keeps line shifted finding unchanged and reports introduced.
     *
     * @return void
     * @throws JsonException
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
            file_put_contents($repo . '/src/Example.php', AgentWorkflowFixtureSources::baseExampleSource());
            $this->runGit($repo, 'add', 'src/Example.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/Example.php', AgentWorkflowFixtureSources::changedExampleSource());

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       'src',
                                       '--format=json',
                                       '--fail-on=none',
                                       '--no-config',
                                       '--no-baseline',
                                       '--diff-vs=HEAD',
                                       '--changed-only',
                                       '--exclude-rule=docs.return-comment',
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

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       'src',
                                       '--format=markdown',
                                       '--fail-on=none',
                                       '--no-config',
                                       '--no-baseline',
                                       '--diff-vs=HEAD',
                                       '--changed-only',
                                       '--exclude-rule=docs.return-comment',
                                   ], $repo);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            self::assertStringContainsString('**Branch review:** base `HEAD`, 1 introduced, 0 removed', $process->getOutput());
            self::assertStringContainsString('### Introduced findings', $process->getOutput());
            self::assertStringContainsString('Example::newRisk()', $process->getOutput());
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify branch review reports removed findings.
     *
     * @return void
     * @throws JsonException
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
            file_put_contents($repo . '/src/Example.php', AgentWorkflowFixtureSources::removedBaseExampleSource());
            $this->runGit($repo, 'add', 'src/Example.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/Example.php', AgentWorkflowFixtureSources::baseExampleSource());

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       'src',
                                       '--format=json',
                                       '--fail-on=none',
                                       '--no-config',
                                       '--no-baseline',
                                       '--diff-vs=HEAD',
                                       '--changed-only',
                                       '--exclude-rule=docs.return-comment',
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
     * @return void
     * @throws JsonException
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
            file_put_contents($repo . '/src/Existing.php', AgentWorkflowFixtureSources::baseExampleSource());
            $this->runGit($repo, 'add', 'src/Existing.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/NewRisk.php', AgentWorkflowFixtureSources::addedRiskSource());
            $this->runGit($repo, 'add', 'src/NewRisk.php');

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       'src/NewRisk.php',
                                       '--format=json',
                                       '--fail-on=none',
                                       '--no-config',
                                       '--no-baseline',
                                       '--diff-vs=HEAD',
                                       '--changed-only',
                                       '--exclude-rule=docs.return-comment',
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
     * @return void
     * @throws JsonException
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
            file_put_contents($repo . '/src/Target.php', AgentWorkflowFixtureSources::baseExampleSource());
            file_put_contents($repo . '/src/Unrelated.php', AgentWorkflowFixtureSources::addedRiskSource());
            $this->runGit($repo, 'add', 'src/Target.php', 'src/Unrelated.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/Target.php', AgentWorkflowFixtureSources::changedExampleSource());

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
                                       '--exclude-rule=docs.return-comment',
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
     * Verify changed-only review gives project rules full context before changed-file filtering.
     *
     * @return void
     * @throws JsonException
     */
    public function testBranchReviewChangedOnlyUsesFullProjectContextForProjectRules(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src/Contracts', 0777, true));
            self::assertTrue(mkdir($repo . '/src/Infrastructure', 0777, true));
            $this->runGit($repo, 'init');
            $this->runGit($repo, 'config', 'user.email', 'test@example.com');
            $this->runGit($repo, 'config', 'user.name', 'Gruff Test');
            file_put_contents($repo . '/src/Contracts/BookingGatewayInterface.php', AgentWorkflowFixtureSources::bookingGatewayInterfaceSource());
            file_put_contents($repo . '/src/Infrastructure/BookingOtpGateway.php', AgentWorkflowFixtureSources::bookingOtpGatewaySource());
            $this->runGit($repo, 'add', 'src/Contracts/BookingGatewayInterface.php', 'src/Infrastructure/BookingOtpGateway.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/Contracts/BookingGatewayInterface.php', AgentWorkflowFixtureSources::changedBookingGatewayInterfaceSource());

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
                                       '--include-rule=design.single-implementor-interface',
                                   ], $repo);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report = $this->decodeJson($process);
            self::assertSame([], $this->diagnosticTypes($report));

            $summary = $this->arrayValue($report, 'summary');
            self::assertSame(1, $this->intValue($summary, 'filesDiscovered'));

            $review = $this->arrayValue($report, 'review');
            $counts = $this->arrayValue($review, 'counts');

            self::assertSame(0, $this->intValue($counts, 'introduced'));
            self::assertSame(0, $this->intValue($counts, 'removed'));
            self::assertSame(1, $this->intValue($counts, 'unchanged'));
            self::assertContains(
                'App\\Contracts\\BookingGatewayInterface',
                $this->symbolsFromFindings($review['unchanged'] ?? null),
            );
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify branch review deleted file reports removed findings.
     *
     * @return void
     * @throws JsonException
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
            file_put_contents($repo . '/src/Deleted.php', AgentWorkflowFixtureSources::removedBaseExampleSource());
            $this->runGit($repo, 'add', 'src/Deleted.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            unlink($repo . '/src/Deleted.php');

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       'src',
                                       '--format=json',
                                       '--fail-on=none',
                                       '--no-config',
                                       '--no-baseline',
                                       '--diff-vs=HEAD',
                                       '--changed-only',
                                       '--exclude-rule=docs.return-comment',
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
                                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                                   'analyse',
                                                   'src/Deleted.php',
                                                   '--format=json',
                                                   '--fail-on=none',
                                                   '--no-config',
                                                   '--no-baseline',
                                                   '--diff-vs=HEAD',
                                                   '--changed-only',
                                                   '--exclude-rule=docs.return-comment',
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
     * @return void
     * @throws JsonException
     */
    public function testReviewModeReportsNonGitDiagnostic(): void
    {
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            file_put_contents($repo . '/src/Example.php', AgentWorkflowFixtureSources::baseExampleSource());

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
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
     * @return void
     */
    public function testReviewModeInvalidOptionCombinationsFailEarly(): void
    {
        $changedOnlyProcess = new Process([
                                              PHP_BINARY,
                                              self::PROJECT_ROOT . '/bin/gruff-php',
                                              'analyse',
                                              '--changed-only',
                                          ], self::PROJECT_ROOT);
        $changedOnlyProcess->run();

        self::assertSame(2, $changedOnlyProcess->getExitCode(), $changedOnlyProcess->getOutput() . $changedOnlyProcess->getErrorOutput());
        self::assertStringContainsString('--changed-only requires --diff-vs.', $changedOnlyProcess->getOutput());

        $diffConflictProcess = new Process([
                                               PHP_BINARY,
                                               self::PROJECT_ROOT . '/bin/gruff-php',
                                               'analyse',
                                               '--diff',
                                               'working-tree',
                                               '--diff-vs=HEAD',
                                           ], self::PROJECT_ROOT);
        $diffConflictProcess->run();

        self::assertSame(2, $diffConflictProcess->getExitCode(), $diffConflictProcess->getOutput() . $diffConflictProcess->getErrorOutput());
        self::assertStringContainsString('--diff, --since, --changed-ranges, and --diff-vs are mutually exclusive.', $diffConflictProcess->getOutput());
    }

    /**
     * Decode a finished CLI process's stdout into a string-keyed payload.
     *
     * @param Process $process - finished CLI process whose stdout holds the report JSON; caller must run it first.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function decodeJson(Process $process): array
    {
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        // Route through stringKeyedArray so a non-object top level fails the test instead of slipping through.
        return $this->stringKeyedArray($decoded);
    }

    /**
     * Read an associative array from decoded JSON output.
     *
     * @param array<string, mixed> $payload
     * @param string               $key - key whose value must itself be a string-keyed array; missing key asserts.
     *
     * @return array<string, mixed> - the nested JSON object at that key, ready for further key reads
     */
    private function arrayValue(array $payload, string $key): array
    {
        // Absent key yields null, which stringKeyedArray rejects, so a missing nested object fails the test.
        return $this->stringKeyedArray($payload[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     * @param string               $key - key whose value must be an int; a non-int (or missing) value fails the test.
     *
     * @return int - the value at that key, asserted to be an int so callers can use it without re-checking
     */
    private function intValue(array $payload, string $key): int
    {
        $payloadValue = $payload[$key] ?? null;
        self::assertIsInt($payloadValue);

        // Returned only after assertIsInt narrows the value, so callers receive a guaranteed int.
        return $payloadValue;
    }

    /**
     * Read a list from decoded JSON output.
     *
     * @param array<string, mixed> $payload
     * @param string               $key - key whose value must be a list; missing key resolves to null and asserts.
     *
     * @return list<mixed> - the JSON array at that key, reindexed to 0-based order
     */
    private function listValue(array $payload, string $key): array
    {
        // Absent key yields null, which mixedList rejects, so a missing list value fails the test rather than passing empty.
        return $this->mixedList($payload[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     * @param string               $key - key whose value must be a string; a non-string (or missing) value fails.
     *
     * @return string - the value at that key, asserted to be a string so callers can use it without re-checking
     */
    private function stringValue(array $payload, string $key): string
    {
        $payloadValue = $payload[$key] ?? null;
        self::assertIsString($payloadValue);

        // Returned only after assertIsString narrows the value, so callers receive a guaranteed string.
        return $payloadValue;
    }

    /**
     * Extract diagnostic type names from decoded JSON output.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string> - diagnostic type names in diagnostics order; empty when the report has no diagnostics
     */
    private function diagnosticTypes(array $payload): array
    {
        $types = [];

        foreach ($this->listValue($payload, 'diagnostics') as $diagnosticValue) {
            $diagnostic = $this->stringKeyedArray($diagnosticValue);
            $types[]    = $this->stringValue($diagnostic, 'type');
        }

        // Collected type names in diagnostics order, so assertions can check presence and sequence together.
        return $types;
    }

    /**
     * Build symbols from findings for the branch-review workflow.
     *
     * @param mixed $findings - decoded `findings` value expected to be a list of finding objects; non-list fails the test.
     *
     * @return list<mixed> - one symbol string per finding, in finding order, with null where a finding has no symbol
     */
    private function symbolsFromFindings(mixed $findings): array
    {
        $symbols = [];

        foreach ($this->mixedList($findings) as $findingValue) {
            $finding   = $this->stringKeyedArray($findingValue);
            $symbols[] = $finding['symbol'] ?? null;
        }

        // Preserves a null per finding that lacks a symbol, so assertions can detect missing symbols positionally.
        return $symbols;
    }

    /**
     * Validate that a decoded JSON value is a list.
     *
     * @param mixed $payload - decoded JSON value expected to be an array; a scalar or null fails the assertion.
     *
     * @return list<mixed> - the same values reindexed to a 0-based list, never object-style keys
     */
    private function mixedList(mixed $payload): array
    {
        self::assertIsArray($payload);

        // array_values reindexes so callers always get a 0-based list even if JSON gave object-style keys.
        return array_values($payload);
    }

    /**
     * Validate that a decoded JSON value is an associative array.
     *
     * @param mixed $payload - decoded JSON value expected to be a JSON object; arrays and scalars fail the assertion.
     *
     * @return array<string, mixed> - only the confirmed string-keyed entries, narrowed for type-safe callers
     */
    private function stringKeyedArray(mixed $payload): array
    {
        self::assertIsArray($payload);

        $result = [];
        foreach ($payload as $key => $entryValue) {
            self::assertIsString($key);
            $result[$key] = $entryValue;
        }

        // Rebuilt array carries only the string-key entries the assertions confirmed, narrowing the type for callers.
        return $result;
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
     * @param string $cwd  Working directory.
     * @param string $args Command arguments.
     *
     * @return void
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
     * @return string - absolute path to the freshly created unique temp directory
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-review-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        // Hand back the path only after mkdir succeeds, so callers never operate on a directory that was not created.
        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Filesystem path.
     *
     * @return void
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            // Nothing to clean up when the fixture directory was never created (e.g. an early test failure).
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $directoryEntry) {
            if ($directoryEntry === '.' || $directoryEntry === '..') {
                continue;
            }

            $child = $path . '/' . $directoryEntry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
