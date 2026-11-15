<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Exercises built-in skips for sensitive-data and security findings on test, fixture and example paths.
 * Checks production findings and built-in audit rows required by FAMILY-CONTRACT.md section 13a.
 * Also checks the example-file suppression count on analyse and summary text output.
 */
final class BuiltInTestPathSkipCliTest extends CliTestCase
{
    /**
     * Checks which fixture paths retain the sample-key finding and which receive built-in audit rows.
     * The rows must identify the skipped paths and rule in the expected index order.
     *
     * @return void - Asserts the two production paths and three indexed built-in rows for the sample-key rule.
     */
    public function testTheTestPathClassSkipsSensitiveFindingsAndCountsThem(): void
    {
        $project = $this->createTestPathProject();

        try {
            $report   = $this->decodeJsonOutput($this->scan($project, 'json'));

            $keyFiles = array_values(array_filter(
                ['Tests/Fixtures/keys.json', 'examples/demo.json', 'src/LoginTest.php', 'src/config.json', 'src/latest.php'],
                fn(string $filePath): bool => in_array('sensitive-data.aws-access-key', $this->rulesFor($report, $filePath), true),
            ));

            self::assertSame(['src/config.json', 'src/latest.php'], $keyFiles);
            // Each skipped file gets its own built-in row, numbered from 0 in byte order of its path.
            $builtIn = array_map(static fn(array $auditRow): array => [$auditRow['index'] ?? null, $auditRow['paths'] ?? null, $auditRow['rule'] ?? null], $this->builtInRows($report));
            self::assertSame([
                [0, ['Tests/Fixtures/keys.json'], 'sensitive-data.aws-access-key'],
                [1, ['examples/demo.json'], 'sensitive-data.aws-access-key'],
                [2, ['src/LoginTest.php'], 'sensitive-data.aws-access-key'],
            ], $builtIn);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Checks that security findings remain in production code and are absent from the test and example paths.
     * Present security audit rows must name a skipped path and carry the security suppression reason.
     *
     * @return void - Asserts rule presence, empty skipped-path results, and nonempty audit rows with the expected reason and paths.
     */
    public function testTheTestPathClassSkipsSecurityFindingsAndCountsThem(): void
    {
        $project = sys_get_temp_dir() . '/gruff-test-path-security-' . bin2hex(random_bytes(6));
        $source  = "<?php\n\nfunction run(string \$arg): string\n{\n    return (string)shell_exec('sh -c ' . \$arg);\n}\n";
        $this->writeProjectFiles($project, [
            'src/Runner.php'         => $source,
            'tests/RunnerTest.php'   => $source,
            'examples/run_demo.php'  => $source,
        ]);

        try {
            $report           = $this->decodeJsonOutput($this->scan($project, 'json'));
            $securityRulesFor = fn(string $filePath): array => array_values(array_filter(
                $this->rulesFor($report, $filePath),
                static fn(string $rule): bool => str_starts_with($rule, 'security.'),
            ));

            // This checks the section 13c rule's presence; it does not assert that the call produces only one finding.
            self::assertContains('security.process-command-construction', $this->rulesFor($report, 'src/Runner.php'));
            self::assertSame([], $securityRulesFor('tests/RunnerTest.php'));
            self::assertSame([], $securityRulesFor('examples/run_demo.php'));

            $securityRows = array_values(array_filter(
                $this->builtInRows($report),
                static fn(array $auditRow): bool => is_string($auditRow['rule'] ?? null) && str_starts_with($auditRow['rule'], 'security.'),
            ));
            self::assertNotSame([], $securityRows);

            foreach ($securityRows as $auditRow) {
                self::assertSame('Test, fixture and example files exercise insecure calls on purpose, so security rules skip them by path.', $auditRow['reason'] ?? null);
                self::assertContains($auditRow['paths'] ?? null, [['examples/run_demo.php'], ['tests/RunnerTest.php']]);
            }
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verifies both text surfaces print the skip's count, so a terminal reader sees what the built-in class hid.
     * Example: analyse on a project with a sample key in examples/demo.json prints a builtInTestPath[examples/demo.json] line.
     *
     * @return void - Asserts the example-file suppression line includes count 1 in both text outputs.
     */
    public function testTheSkipIsCountedOnAnalyseAndSummaryText(): void
    {
        $project  = $this->createTestPathProject();
        $expected = 'builtInTestPath[examples/demo.json] sensitive-data.aws-access-key: 1 (';

        try {
            self::assertStringContainsString($expected, $this->scan($project, 'text')->getOutput());
            self::assertStringContainsString($expected, $this->summary($project)->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Writes a temporary project holding one AWS-shaped key in three test-path files and two production files.
     *
     * @return string - Root of the new project under the system temporary directory.
     */
    private function createTestPathProject(): string
    {
        $project = sys_get_temp_dir() . '/gruff-test-path-skip-' . bin2hex(random_bytes(6));
        // Assembled here, so no whole secret-shaped literal is stored in this file.
        $accessKey = 'AKIA' . '2222333344445555';
        $this->writeProjectFiles($project, [
            'Tests/Fixtures/keys.json' => sprintf("{\n  \"accessKeyId\": \"%s\"\n}\n", $accessKey),
            'examples/demo.json'       => sprintf("{\n  \"accessKeyId\": \"%s\"\n}\n", $accessKey),
            'src/LoginTest.php'        => sprintf("<?php\n\n\$accessKeyId = '%s';\n", $accessKey),
            'src/config.json'          => sprintf("{\n  \"accessKeyId\": \"%s\"\n}\n", $accessKey),
            'src/latest.php'           => sprintf("<?php\n\n\$accessKeyId = '%s';\n", $accessKey),
        ]);

        return $project;
    }

    /**
     * Writes each file of a temporary project, creating its directories first.
     *
     * @param string                $project - Root used to resolve the supplied project-relative file paths.
     * @param array<string, string> $contentsByPath - File contents keyed by project-relative path.
     *
     * @return void - Asserts successful directory creation and file writes for the supplied paths.
     */
    private function writeProjectFiles(string $project, array $contentsByPath): void
    {

        foreach ($contentsByPath as $relativePath => $contents) {
            $directory = dirname($project . '/' . $relativePath);

            if (!is_dir($directory)) {
                self::assertTrue(mkdir($directory, 0777, true), $directory);
            }

            self::assertNotFalse(file_put_contents($project . '/' . $relativePath, $contents), $relativePath);
        }
    }

    /**
     * Runs analyse over the prepared project in one output format, as a user would from the project root.
     *
     * @param string $project - Project root to scan.
     * @param string $format  - CLI output format: json or text.
     *
     * @return Process - the finished process, whose stdout carries the report.
     */
    private function scan(string $project, string $format): Process
    {
        // Include ordinarily ignored paths so the assertions isolate built-in test-path suppression.
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            '--format',
            $format,
            '--fail-on',
            'none',
            '--no-config',
            '--no-baseline',
            '--no-cache',
            '--include-ignored',
        ], $project);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $process;
    }

    /**
     * Runs the summary command over the prepared project, the other text surface that prints the skip count.
     *
     * @param string $project - Project root to scan.
     *
     * @return Process - the finished process, whose stdout carries the digest.
     */
    private function summary(string $project): Process
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'summary',
            // The summary command has no --no-cache option because it does not use the result cache.
            '--no-config',
            '--include-ignored',
        ], $project);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $process;
    }

    /**
     * Selects suppression audit rows whose source is exactly built-in.
     *
     * @param array<string, mixed> $report - Decoded machine report; missing or null suppressions are treated as empty.
     *
     * @return list<array<array-key, mixed>> - Rows with source built-in, preserving report order; empty when none match.
     */
    private function builtInRows(array $report): array
    {
        $suppressions = $report['suppressions'] ?? [];
        self::assertIsArray($suppressions, 'the report publishes a suppressions array');
        $rows = [];

        // Suppression rows from every source must be arrays, including rows excluded from the result.
        foreach ($suppressions as $auditRow) {
            self::assertIsArray($auditRow, 'each suppression is an audit row');
            $rows[] = $auditRow;
        }

        return array_values(array_filter($rows, static fn(array $auditRow): bool => ($auditRow['source'] ?? null) === 'built-in'));
    }

    /**
     * Lists the rule ids a report published for one file path.
     *
     * @param array<string, mixed> $report   - Decoded machine report.
     * @param string               $filePath - Project-relative path to filter on.
     *
     * @return list<string> - Matching IDs in order, including duplicates; absent/null IDs become empty strings; no matches return [].
     */
    private function rulesFor(array $report, string $filePath): array
    {
        $findings = $report['findings'] ?? [];
        self::assertIsArray($findings);
        $rules = [];

        foreach ($findings as $finding) {
            self::assertIsArray($finding, 'each finding is a row');
            $ruleId = $finding['ruleId'] ?? '';
            self::assertIsString($ruleId, 'each finding names its rule');

            $rules[] = ($finding['file'] ?? null) === $filePath ? $ruleId : null;
        }

        return array_values(array_filter($rules, static fn(?string $rule): bool => $rule !== null));
    }
}
