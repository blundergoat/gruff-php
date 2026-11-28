<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers rule metadata, display filters, SARIF output, and finding paths through the CLI.
 * Exercises branch-review changes, including line shifts and added or deleted files.
 * Checks changed-only discovery and the diagnostic returned outside a Git repository.
 */
final class AgentWorkflowCliTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify list rules JSON includes identifier quality metadata.
     *
     * @return void - Asserts rule presence, pillar, default severity, and a description value other than an empty string.
     * @throws JsonException - If the command's stdout is invalid JSON.
     */
    public function testListRulesJsonIncludesIdentifierQualityMetadata(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list-rules', '--format=json'], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $payload  = $this->decodeJson($process);
        $ruleRows = $this->listValue($payload, 'rules');
        /** @var array<string, array<string, mixed>> $rules - Rules indexed by ID for direct assertions. */
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
     * Check hidden findings, filter metadata, and the composite grade for an include-rule run.
     *
     * @return void - Asserts hidden-count agreement, active filter metadata, no displayed findings, single scored pillar, and composite grade F.
     * @throws JsonException - If the command's stdout is invalid JSON.
     */
    public function testIncludeRuleNarrowsExecutionWhileMinSeverityRemainsDisplayOnly(): void
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
        $display     = $this->arrayValue($report, 'displayFilter');
        $score       = $this->arrayValue($report, 'score');
        $composite   = $this->arrayValue($score, 'composite');
        $total       = $this->intValue($findings, 'total');

        self::assertSame([], $this->listValue($report, 'findings'));
        self::assertGreaterThan(0, $total);
        self::assertSame($total, $this->intValue($display, 'hiddenFindings'));
        self::assertTrue($filters['active'] ?? null);
        self::assertSame('warning', $this->stringValue($filters, 'minSeverity'));
        self::assertSame(['naming.identifier-quality'], $this->listValue($filters, 'includeRules'));
        // Only the naming pillar had an enabled rule, so unexecuted pillars do not inflate the composite above F.
        self::assertSame(['naming'], $this->listValue($score, 'scoredPillars'));
        self::assertSame('F', $this->stringValue($composite, 'grade'));
    }

    /**
     * Verify SARIF JSON declares version 2.1.0 and has nonempty results in its first run.
     *
     * @return void - Asserts successful CLI completion, the SARIF version, and nonempty first-run results.
     * @throws JsonException - If the command's stdout is invalid JSON.
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
     * Check that every returned finding path starts with the fixture-relative prefix.
     *
     * @return void - Asserts that no reported finding path lacks the tests/Fixtures/Naming/ prefix.
     * @throws JsonException - If the command's stdout is invalid JSON.
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

        $files = array_map(
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
     * @return void - Asserts HEAD comparison counts, expected symbols, and the Markdown introduction summary.
     * @throws JsonException - If a command's stdout is invalid JSON.
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
            $review = $this->phpTopLevelExtension($report, 'review');
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
     * Verify a symbol-less finding that only shifted lines is neither introduced nor removed.
     *
     * @return void - Asserts zero introductions, zero removals, and one unchanged finding after a line insertion.
     * @throws JsonException - If the command's stdout is invalid JSON.
     */
    public function testBranchReviewKeepsLineShiftedSymbolLessFindingUnchanged(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            $this->runGit($repo, 'init');
            $this->runGit($repo, 'config', 'user.email', 'test@example.com');
            $this->runGit($repo, 'config', 'user.name', 'Gruff Test');
            // security.dangerous-function-call emits no symbol, so this finding exercises the symbol-less identity.
            file_put_contents(
                $repo . '/src/Sink.php',
                "<?php\n\nfunction runSink(string \$code): mixed\n{\n    return eval(\$code);\n}\n",
            );
            $this->runGit($repo, 'add', 'src/Sink.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            // Insert one line above the finding so its source line shifts without changing its durable identity.
            file_put_contents(
                $repo . '/src/Sink.php',
                "<?php\n\n// Unrelated line inserted above the finding.\nfunction runSink(string \$code): mixed\n{\n    return eval(\$code);\n}\n",
            );

            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       'src',
                                       '--format=json',
                                       '--fail-on=none',
                                       '--config',
                                       self::PROJECT_ROOT . '/tests/Fixtures/Config/enable-default-off-rules.yaml',
                                       '--no-baseline',
                                       '--diff-vs=HEAD',
                                       '--include-rule=security.dangerous-function-call',
                                   ], $repo);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report = $this->decodeJson($process);
            $review = $this->phpTopLevelExtension($report, 'review');
            $counts = $this->arrayValue($review, 'counts');

            self::assertSame(0, $this->intValue($counts, 'introduced'));
            self::assertSame(0, $this->intValue($counts, 'removed'));
            self::assertSame(1, $this->intValue($counts, 'unchanged'));
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify branch review reports removed findings.
     *
     * @return void - Asserts one removal, zero introductions, at least one unchanged finding, and the oldRisk removal.
     * @throws JsonException - If the command's stdout is invalid JSON.
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
            $review = $this->phpTopLevelExtension($report, 'review');
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
     * @return void - Asserts successful analysis without diagnostics, at least one introduction, and no removals.
     * @throws JsonException - If the command's stdout is invalid JSON.
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

            $review = $this->phpTopLevelExtension($report, 'review');
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
     * @return void - Asserts one discovered file, one newRisk introduction, no diagnostics, and a non-null score delta.
     * @throws JsonException - If the command's stdout is invalid JSON.
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
            self::assertSame(1, $this->intValue($summary, 'discoveredFiles'));

            $review = $this->phpTopLevelExtension($report, 'review');
            $counts = $this->arrayValue($review, 'counts');

            self::assertSame(1, $this->intValue($counts, 'introduced'));
            self::assertContains('Example::newRisk()', $this->symbolsFromFindings($review['introduced'] ?? null));

            // A nonempty scan supplies source evidence for a score delta.
            self::assertNotNull($review['deltaScore'] ?? null);
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify branch review deleted file reports removed findings without a score delta.
     *
     * @return void - Asserts removals survive an empty scan, both JSON deltas are null, and text omits the score delta.
     * @throws JsonException - If a command's stdout is invalid JSON.
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
            self::assertSame(['empty-analysis'], $this->diagnosticTypes($report));

            $review = $this->phpTopLevelExtension($report, 'review');
            $counts = $this->arrayValue($review, 'counts');

            self::assertGreaterThanOrEqual(1, $this->intValue($counts, 'removed'));
            self::assertContains('Example::oldRisk()', $this->symbolsFromFindings($review['removed'] ?? null));

            // An empty current side has no source evidence to score, so its comparison delta must stay null.
            self::assertArrayHasKey('deltaScore', $review);
            self::assertNull($review['deltaScore']);

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
            self::assertSame(['empty-analysis'], $this->diagnosticTypes($explicitPathReport));

            $explicitPathReview = $this->phpTopLevelExtension($explicitPathReport, 'review');
            $explicitPathCounts = $this->arrayValue($explicitPathReview, 'counts');

            self::assertGreaterThanOrEqual(1, $this->intValue($explicitPathCounts, 'removed'));
            self::assertContains('Example::oldRisk()', $this->symbolsFromFindings($explicitPathReview['removed'] ?? null));
            self::assertArrayHasKey('deltaScore', $explicitPathReview);
            self::assertNull($explicitPathReview['deltaScore']);

            $textProcess = new Process([
                                           PHP_BINARY,
                                           self::PROJECT_ROOT . '/bin/gruff-php',
                                           'analyse',
                                           'src',
                                           '--fail-on=none',
                                           '--no-config',
                                           '--no-baseline',
                                           '--diff-vs=HEAD',
                                           '--changed-only',
                                           '--exclude-rule=docs.return-comment',
                                       ], $repo);
            $textProcess->run();

            self::assertSame(0, $textProcess->getExitCode(), $textProcess->getOutput() . $textProcess->getErrorOutput());
            $textReport = $textProcess->getOutput();

            // The text report keeps removals but omits score movement when the current scan cannot be scored.
            self::assertStringContainsString('the score is not applicable', $textReport);
            self::assertMatchesRegularExpression('/Findings: 0 introduced, [1-9]\d* removed/', $textReport);
            self::assertStringNotContainsString('Score delta', $textReport);
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Verify review mode fails with a changed-region diagnostic outside a Git repository.
     *
     * @return void - Asserts exit code 2 and inclusion of the changed-region diagnostic type.
     * @throws JsonException - If the command's stdout is invalid JSON.
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

            // A changed-region diagnostic tells callers that the requested review scope could not be read.
            self::assertContains('changed-region', $this->diagnosticTypes($report));
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Decode a finished CLI process's stdout and assert string keys on its top-level array.
     *
     * @param Process $process - finished CLI process whose stdout holds the report JSON; caller must run it first.
     *
     * @return array<string, mixed> - Decoded array with string keys, possibly empty; scalars and numeric keys fail assertions.
     * @throws JsonException - If the process stdout is invalid JSON.
     */
    private function decodeJson(Process $process): array
    {
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        return $this->stringKeyedArray($decoded);
    }

    /**
     * Read an associative array from decoded JSON output.
     *
     * @param array<string, mixed> $payload - Decoded JSON object containing the nested key under test.
     * @param string               $key     - key whose value must itself be a string-keyed array; missing key asserts.
     *
     * @return array<string, mixed> - Nested string-keyed array; empty arrays pass, while missing or invalid values fail assertions.
     */
    private function arrayValue(array $payload, string $key): array
    {
        return $this->stringKeyedArray($payload[$key] ?? null);
    }

    /**
     * Read a PHP-owned v3 top-level extension from decoded analysis output.
     *
     * @param array<string, mixed> $payload - Decoded v3 analysis document.
     * @param string               $key     - PHP extension key to read.
     *
     * @return array<string, mixed> - The selected extension's string-keyed array; empty arrays are accepted.
     */
    private function phpTopLevelExtension(array $payload, string $key): array
    {
        $extensions    = $this->arrayValue($payload, 'extensions');
        $phpExtensions = $this->arrayValue($extensions, 'php');
        $topLevel      = $this->arrayValue($phpExtensions, 'topLevel');

        return $this->arrayValue($topLevel, $key);
    }

    /**
     * Read an integer field from decoded JSON, failing the test for missing or non-integer values.
     *
     * @param array<string, mixed> $payload - Decoded JSON object containing the integer key under test.
     * @param string               $key     - key whose value must be an int; a non-int (or missing) value fails the test.
     *
     * @return int - the value at that key, asserted to be an int so callers can use it without re-checking
     */
    private function intValue(array $payload, string $key): int
    {
        $payloadValue = $payload[$key] ?? null;
        self::assertIsInt($payloadValue);

        return $payloadValue;
    }

    /**
     * Read an array field from decoded JSON and reindex its values as a list.
     *
     * @param array<string, mixed> $payload - Decoded JSON payload containing the array field under test.
     * @param string               $key     - Key whose value must be an array; missing or null values fail assertions.
     *
     * @return list<mixed> - Array values in iteration order with zero-based keys; associative keys are discarded.
     */
    private function listValue(array $payload, string $key): array
    {
        return $this->mixedList($payload[$key] ?? null);
    }

    /**
     * Read a string field from decoded JSON, failing the test for missing or non-string values.
     *
     * @param array<string, mixed> $payload - Decoded JSON object containing the string key under test.
     * @param string               $key     - key whose value must be a string; a non-string (or missing) value fails.
     *
     * @return string - the value at that key, asserted to be a string so callers can use it without re-checking
     */
    private function stringValue(array $payload, string $key): string
    {
        $payloadValue = $payload[$key] ?? null;
        self::assertIsString($payloadValue);

        return $payloadValue;
    }

    /**
     * Extract diagnostic type names from decoded JSON output.
     *
     * @param array<string, mixed> $payload - Decoded analysis payload whose diagnostics list is inspected.
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

        return $types;
    }

    /**
     * Collect the symbol field from each branch-review finding without validating its type.
     *
     * @param mixed $findings - Array of finding records; non-array values fail the test.
     *
     * @return list<mixed> - Symbol values in finding order; absent or null symbols produce null, and other values pass through.
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
     * Assert that a decoded value is an array and return its values as a list.
     *
     * @param mixed $payload - decoded JSON value expected to be an array; a scalar or null fails the assertion.
     *
     * @return list<mixed> - the same values reindexed to a 0-based list, never object-style keys
     */
    private function mixedList(mixed $payload): array
    {
        self::assertIsArray($payload);

        return array_values($payload);
    }

    /**
     * Assert that a decoded value is an array containing only string keys.
     *
     * @param mixed $payload - Decoded JSON value; non-arrays and numeric keys fail assertions, while an empty array passes.
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

        return $result;
    }

    /**
     * Skip the current test when Git is unavailable.
     *
     * @return void - Marks the test skipped when Git fails; otherwise allows execution to continue.
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
     * @param string $cwd  - Fixture repository where the Git command runs.
     * @param string $args - Git subcommand and arguments, passed in order.
     *
     * @return void - Asserts that the Git process exits with code 0.
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

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path - Temporary fixture directory to clean; non-directory paths are ignored.
     *
     * @return void - Attempts recursive cleanup without following child directory symlinks; ignores non-directory paths.
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {

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
