<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Engine\Analysis\InlineSuppressionFilter;
use Symfony\Component\Process\Process;

/**
 * Covers FAMILY-CONTRACT.md section 13c as a user meets it: another tool's suppression comment, and one exec call
 * reported under two rules.
 *
 * - A phpcs or psalm comment naming a check that makes the finding's claim suppresses it where it was written.
 * - An unrelated sniff, a type-checker comment and an uncommented sibling line keep reporting.
 * - An `exec` whose command is built at run time reports once, as security.process-command-construction.
 * - Each removal is counted in an `inline` or `duplicate` audit row, the text line and a suppressed SARIF result.
 */
final class InlineSuppressionCliTest extends CliTestCase
{
    /**
     * Source whose line numbers the assertions below name; each line exercises one comment form or control.
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
        }

        PHP;

    /**
     * Verifies which security findings survive: only the lines no comment covers, and `exec` once.
     *
     * @return void
     */
    public function testCommentsSuppressOnlyTheChecksTheyNameAndAnExecCallReportsOnce(): void
    {
        $project = $this->writeProject();

        try {
            $report   = $this->decodeJsonOutput($this->scan($project, 'json'));
            $security = [];
            // Each surviving security finding is listed as `line rule`, so one assertion shows the whole outcome.
            foreach ($this->listAt($report, 'findings') as $finding) {
                $finding = $this->decodedJsonObject($finding);
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
     * Verifies the text line counts every removal and SARIF keeps each as a suppressed result with its reason.
     *
     * @return void
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
            // Each suppressed result carries one inSource suppression naming the audit reason.
            foreach ($this->listAt($sarifRun, 'results') as $result) {
                $suppressions = $this->decodedJsonObject($result)['suppressions'] ?? null;
                $suppression  = is_array($suppressions) ? ($suppressions[0] ?? null) : null;
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
     * Runs `analyse` over the project in one output format, as a user would from the project root.
     *
     * @param string $project - Root of the fixture project.
     * @param string $format  - Output format to request.
     *
     * @return Process - The finished process, whose output the caller reads.
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
     * Lists the report's section 13c audit rows as `index|source|rule|suppressed|reason`.
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
            if (in_array($row['source'] ?? null, ['inline', 'duplicate'], true)) {
                $fields = [$row['index'] ?? null, $row['source'], $row['rule'] ?? null, $row['suppressed'] ?? null, $row['reason'] ?? null];
                $rows[] = implode('|', array_map(static fn (mixed $field): string => is_scalar($field) ? (string)$field : '', $fields));
            }
        }

        return $rows;
    }
}
