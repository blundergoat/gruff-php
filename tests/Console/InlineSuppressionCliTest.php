<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Engine\Analysis\InlineSuppressionFilter;
use Symfony\Component\Process\Process;

/**
 * Covers FAMILY-CONTRACT.md section 13c using phpcs and psalm comments plus an exec call reported by two rules.
 *
 * Checks ordered security line/rule pairs and inline/duplicate audit rows against exact expected lists.
 * Text assertions cover two audit rows; SARIF assertions check the collected suppression kinds and reasons.
 */
final class InlineSuppressionCliTest extends CliTestCase
{
    /**
     * Fixture for matching and unrelated suppression comments, unsuppressed calls and duplicate findings.
     */
    private const SOURCE = <<<'PHP'
        <?php

        function load(\wpdb $wpdb, string $id, string $path, string $dir, string $host, string $cmd): void
        {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id is validated upstream
            $wpdb->query("SELECT * FROM users WHERE id = $id");
            $wpdb->query("SELECT * FROM posts WHERE id = $id");
            $wpdb->query("SELECT * FROM terms WHERE id = $id"); // phpcs:ignore WordPress.Security.EscapeOutput
            // @phpstan-ignore-next-line
            $wpdb->query("SELECT * FROM links WHERE id = $id");
            $contents = @file_get_contents($path); // phpcs:ignore
            /** @psalm-suppress ForbiddenCode */
            shell_exec('ls ' . $dir);
            exec('ping -c 1 ' . $host);
            passthru($cmd);
            $wpdb->query("SELECT * FROM secrets WHERE id = $id"); // phpcs:ignore Generic
            $wpdb->query("SELECT * FROM options WHERE id = $id"); // phpcs:ignore WordPress
            echo $contents;
            echo @strlen('phpcs:ignore WordPress.PHP.NoSilencedErrors');
        }

        PHP;

    /**
     * Checks suppression matching and the duplicate exec report using the fixture's expected security line/rule pairs.
     *
     * @return void - Asserts ordered security line/rule pairs and inline/duplicate audit rows.
     */
    public function testCommentsSuppressOnlyTheChecksTheyNameAndAnExecCallReportsOnce(): void
    {
        $project = $this->writeProject();

        try {
            $report   = $this->decodeJsonOutput($this->scan($project, 'json'));
            $security = [];
            // Keep the expected rows limited to the security checks this suppression workflow exercises.
            foreach ($this->listAt($report, 'findings') as $finding) {
                $finding = $this->decodedJsonObject($finding);
                // Ignore findings from other pillars so the assertions track the rules covered by the fixture directives.
                if (is_string($finding['ruleId'] ?? null) && str_starts_with($finding['ruleId'], 'security.')) {
                    $security[] = sprintf('%d %s', is_int($finding['line'] ?? null) ? $finding['line'] : 0, $finding['ruleId']);
                }
            }

            self::assertSame([
                '7 security.sql-concatenation',
                '8 security.sql-concatenation',
                '10 security.sql-concatenation',
                '14 security.process-command-construction',
                '15 security.dangerous-function-call',
                '16 security.sql-concatenation',
                '19 security.error-suppression',
            ], $security);
            self::assertSame([
                '0|inline|security.dangerous-function-call|1|' . InlineSuppressionFilter::INLINE_REASON,
                '1|inline|security.error-suppression|1|' . InlineSuppressionFilter::INLINE_REASON,
                '2|inline|security.process-command-construction|1|' . InlineSuppressionFilter::INLINE_REASON,
                '3|inline|security.sql-concatenation|2|' . InlineSuppressionFilter::INLINE_REASON,
                '4|duplicate|security.dangerous-function-call|1|' . InlineSuppressionFilter::DUPLICATE_REASON,
            ], $this->section13cRows($report));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Checks two text audit rows and the reasons and kinds collected from SARIF results that contain a suppression.
     *
     * @return void - Asserts selected text counts and the ordered SARIF suppression reasons.
     */
    public function testTextAndSarifKeepEveryRemovalVisible(): void
    {
        $project = $this->writeProject();

        try {
            $text = $this->scan($project, 'text')->getOutput();
            self::assertStringContainsString('inline[src/Db.php] security.sql-concatenation: 2 (' . InlineSuppressionFilter::INLINE_REASON . ')', $text);
            self::assertStringContainsString('duplicate[src/Db.php] security.dangerous-function-call: 1 (' . InlineSuppressionFilter::DUPLICATE_REASON . ')', $text);

            $sarif         = $this->decodeJsonOutput($this->scan($project, 'sarif'));
            $sarifRun      = $this->decodedJsonObject($this->listAt($sarif, 'runs')[0] ?? null);
            $justification = [];
            // For results with a suppression, the first entry must use inSource and name the audit reason.
            foreach ($this->listAt($sarifRun, 'results') as $result) {
                $suppressions = $this->decodedJsonObject($result)['suppressions'] ?? null;
                $suppression  = is_array($suppressions) ? ($suppressions[0] ?? null) : null;
                // Inspect only suppressed SARIF results, where the caller needs the reason a finding was hidden.
                if (is_array($suppression)) {
                    self::assertSame('inSource', $suppression['kind'] ?? null);
                    $justification[] = $suppression['justification'] ?? null;
                }
            }

            self::assertSame(
                [...array_fill(0, 5, InlineSuppressionFilter::INLINE_REASON), InlineSuppressionFilter::DUPLICATE_REASON],
                $justification,
            );
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Checks that a selected hunk publishes only the suppression inside its lines.
     *
     * @return void - Asserts one SARIF result with the rule hidden on the selected line.
     */
    public function testSarifSuppressionsRespectChangedRanges(): void
    {
        $project = $this->writeProject();

        try {
            $report   = $this->decodeJsonOutput($this->scan($project, 'sarif', ['--changed-ranges=11-11', '--changed-scope=hunk', 'src/Db.php']));
            $sarifRun = $this->decodedJsonObject($this->listAt($report, 'runs')[0] ?? null);
            $results  = $this->listAt($sarifRun, 'results');
            self::assertCount(1, $results);
            $result = $this->decodedJsonObject($results[0]);
            self::assertSame('security.error-suppression', $result['ruleId'] ?? null);
            self::assertCount(1, $this->listAt($result, 'suppressions'));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Checks that `--diff-base --changed-only --format sarif` excludes suppressed findings from unchanged files.
     *
     * @return void - Asserts zero SARIF results when the only suppressed findings live in an unchanged file.
     */
    public function testSarifSuppressionsRespectChangedOnlyBranchReview(): void
    {
        $project = $this->writeProject();

        try {
            file_put_contents($project . '/src/Clean.php', "<?php\n\ndeclare(strict_types=1);\n\n/** Clean helper. */\nfinal readonly class Clean\n{\n}\n");

            foreach ([['init', '-q'], ['add', 'src/Db.php', 'src/Clean.php'], ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-qm', 'base']] as $gitArgs) {
                $git = new Process(array_merge(['git'], $gitArgs), $project);
                $git->run();
                self::assertSame(0, $git->getExitCode(), $git->getErrorOutput());
            }

            // Modify only Clean.php so Db.php (with its inline suppressions) is unchanged against HEAD.
            file_put_contents($project . '/src/Clean.php', "<?php\n\ndeclare(strict_types=1);\n\n/** Clean helper updated. */\nfinal readonly class Clean\n{\n}\n");

            $report   = $this->decodeJsonOutput($this->scan($project, 'sarif', ['src', '--diff-base', 'HEAD', '--changed-only']));
            $sarifRun = $this->decodedJsonObject($this->listAt($report, 'runs')[0] ?? null);
            self::assertSame([], $this->listAt($sarifRun, 'results'));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Checks the PHP hotspot schema and its suppression audit.
     *
     * @return void - Confirms the hotspot audit matches the analysis audit.
     */
    public function testHotspotPublishesItsSchemaAndSuppressionAudit(): void
    {
        $project = $this->writeProject();

        try {
            $hotspot  = $this->decodeJsonOutput($this->scan($project, 'hotspot'));
            $analysis = $this->decodeJsonOutput($this->scan($project, 'json'));

            self::assertSame('gruff-php.hotspot.v1', $hotspot['schemaVersion'] ?? null);
            self::assertNotEmpty($this->listAt($hotspot, 'hotspots'));
            self::assertSame($this->listAt($analysis, 'suppressions'), $this->listAt($hotspot, 'suppressions'));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Writes the fixture project into a fresh temp directory.
     *
     * @return string - Root of the project; the caller removes it with removeDir().
     */
    private function writeProject(): string
    {
        $project = $this->tempDir();
        self::assertTrue(mkdir($project . '/src'));
        self::assertNotFalse(file_put_contents($project . '/src/Db.php', self::SOURCE));

        return $project;
    }

    /**
     * Runs the analyse command over the project in one output format, as a user would from the project root.
     *
     * @param string       $project - Root of the fixture project.
     * @param string       $format  - Output format to request.
     * @param list<string> $options - Additional scope arguments.
     *
     * @return Process - The finished process, whose output the caller reads.
     */
    private function scan(string $project, string $format, array $options = []): Process
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
            '--config',
            self::PROJECT_ROOT . '/tests/Fixtures/Config/enable-default-off-rules.yaml',
            '--no-baseline',
            '--no-cache',
            '--include-ignored',
            ...$options,
        ], $project);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $process;
    }

    /**
     * Reads one JSON array member of a decoded object.
     *
     * @param array<string, mixed> $payload - Decoded JSON object.
     * @param string               $key     - Member holding a JSON array.
     *
     * @return list<mixed> - The array's items in order.
     */
    private function listAt(array $payload, string $key): array
    {
        $items = $payload[$key] ?? null;
        self::assertIsArray($items, $key . ' is a JSON array');

        return array_values($items);
    }

    /**
     * Lists section 13c audit rows as pipe-separated index, source, rule, suppressed-count and reason fields.
     *
     * @param array<string, mixed> $report - Decoded JSON report.
     *
     * @return list<string> - One entry per inline or duplicate row, in report order.
     */
    private function section13cRows(array $report): array
    {
        $rows = [];
        // Only the two section 13c sources are compared; built-in and configured rows belong to section 13a.
        foreach ($this->listAt($report, 'suppressions') as $row) {
            $row = $this->decodedJsonObject($row);
            // Compare the inline and duplicate rows without relying on configured sensitive-exclusion entries.
            if (in_array($row['source'] ?? null, ['inline', 'duplicate'], true)) {
                $fields = [$row['index'] ?? null, $row['source'], $row['rule'] ?? null, $row['suppressed'] ?? null, $row['reason'] ?? null];
                $rows[] = implode('|', array_map(static fn (mixed $field): string => is_scalar($field) ? (string)$field : '', $fields));
            }
        }

        return $rows;
    }
}
