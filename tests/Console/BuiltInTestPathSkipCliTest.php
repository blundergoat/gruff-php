<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Covers the built-in test-path skip as a user meets it when scanning a project with sample keys under `tests/` or `examples/`.
 *
 * - Sensitive-data findings in test, fixture and example files are dropped, while production files keep reporting.
 * - Every drop is counted in a `source: "built-in"` audit row, because FAMILY-CONTRACT.md section 13a lets no surface filter in silence.
 */
final class BuiltInTestPathSkipCliTest extends CliTestCase
{
    /**
     * Verifies a key in test, fixture and example files is skipped and counted per file and rule.
     * Production files still report, including `src/latest.php`, whose name merely contains `test`.
     *
     * @return void
     */
    public function testTheTestPathClassSkipsSensitiveFindingsAndCountsThem(): void
    {
        $project = $this->createTestPathProject();

        try {
            $report   = $this->decodeJsonOutput($this->scan($project, 'json'));
            // Only the two production files may still report the key.
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
     * Verifies both text surfaces print the skip's count, so a terminal reader sees what the built-in class hid.
     * Example: `analyse` on a project with a sample key in `examples/demo.json` prints a `builtInTestPath[examples/demo.json]` line.
     *
     * @return void
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
     * @return string - root of the new project, outside the repository so an interrupted run leaves no key in the working tree
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
     * @param string                $project - Root of the temporary project; created when absent.
     * @param array<string, string> $contentsByPath - File contents keyed by project-relative path.
     *
     * @return void
     */
    private function writeProjectFiles(string $project, array $contentsByPath): void
    {
        // A nested path such as `Tests/Fixtures/keys.json` needs its directories before the file can be written.
        foreach ($contentsByPath as $relativePath => $contents) {
            $directory = dirname($project . '/' . $relativePath);
            // Each directory is created once, whatever order the files arrive in.
            if (!is_dir($directory)) {
                self::assertTrue(mkdir($directory, 0777, true), $directory);
            }

            self::assertNotFalse(file_put_contents($project . '/' . $relativePath, $contents), $relativePath);
        }
    }

    /**
     * Runs `analyse` over the prepared project in one output format, as a user would from the project root.
     *
     * @param string $project - Project root to scan.
     * @param string $format  - Output format requested from the CLI, `json` or `text`.
     *
     * @return Process - the finished process, whose stdout carries the report.
     */
    private function scan(string $project, string $format): Process
    {
        // Temp roots sit under an ignored directory, so ask for them explicitly.
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
     * Runs `summary` over the prepared project, the other text surface that prints the skip's count.
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
            // `summary` has no --no-cache: it never reads or writes the result cache.
            '--no-config',
            '--include-ignored',
        ], $project);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $process;
    }

    /**
     * Lists the audit rows the built-in skip produced, which are the ones naming a source.
     *
     * @param array<string, mixed> $report - Decoded machine report.
     *
     * @return list<array<array-key, mixed>> - one row per file and rule the skip hid; empty when no test file had a sensitive finding
     */
    private function builtInRows(array $report): array
    {
        $suppressions = $report['suppressions'] ?? [];
        self::assertIsArray($suppressions, 'the report publishes a suppressions array');
        $rows = [];

        // A row that is not an object means the report shape broke, so the test fails here rather than on a confusing diff.
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
     * @return list<string> - one rule id per finding reported for that file; empty when the file reported nothing
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
            // Findings in other files become null here and are dropped below, so only the asked-for file's rules remain.
            $rules[] = ($finding['file'] ?? null) === $filePath ? $ruleId : null;
        }

        return array_values(array_filter($rules, static fn(?string $rule): bool => $rule !== null));
    }
}
