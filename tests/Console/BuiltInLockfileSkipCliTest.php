<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * The family's built-in lockfile skip, as a gruff-php user meets it.
 *
 * A package-manager lockfile carries thousands of published integrity digests, so the entropy
 * rule's findings there are dropped by file name. FAMILY-CONTRACT.md section 13a lets no surface
 * filter in silence, so every drop is counted and published as a built-in audit row, and every
 * other sensitive-data rule still reads the lockfile.
 */
final class BuiltInLockfileSkipCliTest extends CliTestCase
{
    /** The one rule the built-in lockfile skip covers. */
    private const ENTROPY_RULE = 'sensitive-data.high-entropy-string';

    /** A package-manager lockfile, named in parts so this file holds no literal a guard would match. */
    private const LOCKFILE_NAME = 'package-' . 'lock.json';

    /** The same bytes under a neutral name, which must keep reporting everything. */
    private const TWIN_NAME = 'other.json';

    /**
     * Verify the lockfile loses only its entropy findings while its byte-identical twin keeps them.
     *
     * @return void
     */
    public function testLockfileLosesOnlyItsEntropyFindings(): void
    {
        $project = $this->createLockfileProject();

        try {
            $report = $this->decodeJsonOutput($this->scan($project, 'json'));
            $twinRules = $this->rulesFor($report, 'web/' . self::TWIN_NAME);
            $lockfileRules = $this->rulesFor($report, 'web/' . self::LOCKFILE_NAME);

            self::assertContains(self::ENTROPY_RULE, $twinRules, 'the twin must still report the entropy rule, or this test proves nothing');
            self::assertNotContains(self::ENTROPY_RULE, $lockfileRules, 'the lockfile still reports the entropy rule');
            self::assertContains('sensitive-data.aws-access-key', $lockfileRules, 'a credential in a lockfile must still report');
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify the skip publishes one built-in audit row naming the lockfile and its count.
     *
     * @return void
     */
    public function testTheSkipPublishesABuiltInAuditRow(): void
    {
        $project = $this->createLockfileProject();

        try {
            $builtIn = $this->builtInRows($this->decodeJsonOutput($this->scan($project, 'json')));

            self::assertCount(1, $builtIn);
            self::assertSame(self::ENTROPY_RULE, $builtIn[0]['rule'] ?? null);
            self::assertSame(['web/' . self::LOCKFILE_NAME], $builtIn[0]['paths'] ?? null);
            self::assertGreaterThanOrEqual(1, $builtIn[0]['suppressed'] ?? 0);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify both text surfaces that apply the skip publish its count, in the family wording.
     *
     * @return void
     */
    public function testTheSkipIsCountedOnAnalyseAndSummaryText(): void
    {
        $project = $this->createLockfileProject();
        $expected = sprintf('builtInLockfile[web/%s] %s: ', self::LOCKFILE_NAME, self::ENTROPY_RULE);

        try {
            self::assertStringContainsString($expected, $this->scan($project, 'text')->getOutput());
            self::assertStringContainsString($expected, $this->summary($project)->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Writes one lockfile and its byte-identical twin, so a path-based mechanism is visible.
     *
     * @return string - Project root holding the two files.
     */
    private function createLockfileProject(): string
    {
        // Outside the repository, so an interrupted run cannot leave a credential-shaped value in the working tree.
        $project = sys_get_temp_dir() . '/gruff-lockfile-skip-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($project . '/web', 0777, true));
        // Both values are assembled here, so no whole secret-shaped literal is stored in this file.
        $digest = 'q7ZxM2kPv9LtB4nR' . 'w8HsD3jFy6GcT5mV' . 'a1UeN0bK';
        $accessKey = 'AKIA' . '2222333344445555';
        $body = sprintf("{\n  \"resolvedDigest\": \"%s\",\n  \"accessKeyId\": \"%s\"\n}\n", $digest, $accessKey);
        self::assertNotFalse(file_put_contents($project . '/web/' . self::LOCKFILE_NAME, $body));
        self::assertNotFalse(file_put_contents($project . '/web/' . self::TWIN_NAME, $body));

        return $project;
    }

    /**
     * Runs `analyse` over the prepared project in one format.
     *
     * @param string $project - Project root to scan.
     * @param string $format  - Output format requested from the CLI.
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
     * Runs `summary` over the prepared project, the other surface that applies the skip.
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
     * @return list<array<array-key, mixed>> - one row per lockfile the skip removed findings from.
     */
    private function builtInRows(array $report): array
    {
        $suppressions = $report['suppressions'] ?? [];
        self::assertIsArray($suppressions, 'the report publishes a suppressions array');
        $rows = [];

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
     * @return list<string> - one rule id per finding reported for that file.
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
