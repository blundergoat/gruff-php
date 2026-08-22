<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * Covers what a configured `sensitiveExclusions:` entry does to a real `analyse` run.
 *
 * Every case compares a no-config baseline scan of the same synthetic corpus against the same tree
 * under the case configuration: the findings the configuration removed must be exactly those whose
 * rule and path match a declared entry, so an entry can never quietly take a sibling with it. The
 * suite also pins the audit row a reviewer reads and the suppression total the terminal report
 * prints, because a suppressed finding must be accounted for rather than merely absent.
 */
final class SensitiveExclusionsCliTest extends CliTestCase
{
    /** Synthetic corpus fixtures copied into every test project, keyed by their project-relative path. */
    private const CORPUS_FILES = ['AwsSample.php', 'AwsSibling.php', 'JwtSample.php', 'Clean.php'];

    /** Rule id the corpus trips twice, in two different files. */
    private const AWS_RULE = 'sensitive-data.aws-access-key';

    /** Second sensitive-data rule the corpus trips, in its own file. */
    private const JWT_RULE = 'sensitive-data.jwt-token';

    /**
     * Verifies a rule plus a path suppresses every occurrence of that rule in that file, reports its
     * count, and removes nothing else - the same rule in another file and another rule in the same
     * scan both keep reporting.
     *
     * @return void
     * @throws JsonException
     */
    public function testExactRuleAndPathSuppressesOnlyItsOwnScope(): void
    {
        $entries = [['rule' => self::AWS_RULE, 'path' => 'corpus/AwsSample.php', 'reason' => 'Synthetic AWS key in the redaction corpus.']];
        $project = $this->createCorpusProject($entries);

        try {
            $baseline   = $this->analyse($this->createCorpusProject([]));
            $configured = $this->analyse($project);

            self::assertSame([[self::AWS_RULE, 'corpus/AwsSample.php']], $this->removedFindings($baseline, $configured));
            self::assertGreaterThanOrEqual(1, $this->suppressedCount($configured, 0));
            self::assertContains([self::AWS_RULE, 'corpus/AwsSibling.php'], $this->findingScopes($configured));
            self::assertContains([self::JWT_RULE, 'corpus/JwtSample.php'], $this->findingScopes($configured));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verifies two entries with different scopes stay independent, each reporting its own count and
     * removing only its own findings.
     *
     * @return void
     * @throws JsonException
     */
    public function testTwoDistinctEntriesReportIndependentCounts(): void
    {
        $project = $this->createCorpusProject([
            ['rule' => self::AWS_RULE, 'path' => 'corpus/AwsSample.php', 'reason' => 'Synthetic AWS key in the redaction corpus.'],
            ['rule' => self::JWT_RULE, 'path' => 'corpus/JwtSample.php', 'reason' => 'Synthetic JWT in the redaction corpus.'],
        ]);

        try {
            $baseline   = $this->analyse($this->createCorpusProject([]));
            $configured = $this->analyse($project);

            self::assertSame(
                [[self::AWS_RULE, 'corpus/AwsSample.php'], [self::JWT_RULE, 'corpus/JwtSample.php']],
                $this->removedFindings($baseline, $configured),
            );
            self::assertGreaterThanOrEqual(1, $this->suppressedCount($configured, 0));
            self::assertGreaterThanOrEqual(1, $this->suppressedCount($configured, 1));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verifies the corpus's second copy of one rule is reachable on its own, so excluding one file
     * demonstrably leaves the other reporting rather than merely appearing to.
     *
     * @return void
     * @throws JsonException
     */
    public function testExcludingTheSiblingFileLeavesTheOriginalReporting(): void
    {
        $project = $this->createCorpusProject([
            ['rule' => self::AWS_RULE, 'path' => 'corpus/AwsSibling.php', 'reason' => 'Only the sibling fixture is accepted.'],
        ]);

        try {
            $baseline   = $this->analyse($this->createCorpusProject([]));
            $configured = $this->analyse($project);

            self::assertSame([[self::AWS_RULE, 'corpus/AwsSibling.php']], $this->removedFindings($baseline, $configured));
            self::assertContains([self::AWS_RULE, 'corpus/AwsSample.php'], $this->findingScopes($configured));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verifies an entry whose scope matches nothing reports zero and removes nothing, so fixing the
     * underlying problem - or narrowing to a symbol this pillar never stamps - never breaks a build.
     *
     * @param array<string, string> $exclusionEntry - Entry whose declared scope matches no finding in the corpus.
     *
     * @return void
     * @throws JsonException
     */
    #[DataProvider('scopeMatchingNothingProvider')]
    public function testScopeMatchingNothingReportsZeroWithoutFailing(array $exclusionEntry): void
    {
        $project = $this->createCorpusProject([$exclusionEntry]);

        try {
            $baseline   = $this->analyse($this->createCorpusProject([]));
            $configured = $this->analyse($project);

            self::assertSame([], $this->removedFindings($baseline, $configured));
            self::assertSame(0, $this->suppressedCount($configured, 0));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Provides the two ways an accepted entry legitimately matches nothing: a file with no finding,
     * and a symbol on a pillar whose findings carry none.
     *
     * @return array<string, array{array<string, string>}> - the entry each row configures.
     */
    public static function scopeMatchingNothingProvider(): array
    {
        return [
            'file carrying no finding' => [[
                'rule'   => self::AWS_RULE,
                'path'   => 'corpus/Clean.php',
                'reason' => 'Retained while the fixture is being removed.',
            ]],
            'symbol narrows to nothing' => [[
                'rule'   => self::AWS_RULE,
                'path'   => 'corpus/AwsSample.php',
                'symbol' => 'SyntheticFixtureSymbol',
                'reason' => 'Narrowed to one symbol while the fixture is refactored.',
            ]],
        ];
    }

    /**
     * Verifies the machine report publishes the family audit row for every configured entry, carrying
     * only configured material - never a message excerpt, preview, or matched value.
     *
     * @return void
     * @throws JsonException
     */
    public function testAuditRowCarriesTheFamilyShapeAndNoValueMaterial(): void
    {
        $reason  = 'Synthetic AWS key in the redaction corpus; not a live credential.';
        $project = $this->createCorpusProject([['rule' => self::AWS_RULE, 'path' => 'corpus/AwsSample.php', 'reason' => $reason]]);

        try {
            $process = $this->analyseProcess($project, 'json');
            $report  = $this->decodeJsonOutput($process);

            self::assertSame(
                [[
                    'index'      => 0,
                    'rule'       => self::AWS_RULE,
                    'paths'      => ['corpus/AwsSample.php'],
                    'symbol'     => null,
                    'reason'     => $reason,
                    'suppressed' => 1,
                ]],
                $report['suppressions'],
            );
            self::assertStringNotContainsString('AKIA', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verifies the terminal report states how many findings were suppressed and why, so a reader of
     * the text output is never left with an unexplained absence.
     *
     * @return void
     */
    public function testTextReportNamesTheSuppressionTotalAndItsReason(): void
    {
        $reason  = 'Synthetic AWS key in the redaction corpus; not a live credential.';
        $project = $this->createCorpusProject([['rule' => self::AWS_RULE, 'path' => 'corpus/AwsSample.php', 'reason' => $reason]]);

        try {
            $output = $this->analyseProcess($project, 'text')->getOutput();

            self::assertStringContainsString(
                sprintf('Suppressed findings: 1 via sensitiveExclusions[0] %s: 1 (%s)', self::AWS_RULE, $reason),
                $output,
            );
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verifies the digest applies the same exclusion the fuller report does and accounts for it in
     * the same words on the same surface, so the two commands can no longer disagree about one tree.
     *
     * Both halves matter: a summary that filtered without saying so would hide findings behind a
     * quietly smaller number, and a summary that declined to filter would contradict `analyse` on
     * the very tree the reader just scanned.
     *
     * @return void
     */
    public function testSummaryTextReportsTheSameSuppressionTotalAndFindingCountAsAnalyse(): void
    {
        $reason  = 'Synthetic AWS key in the redaction corpus; not a live credential.';
        $project = $this->createCorpusProject([['rule' => self::AWS_RULE, 'path' => 'corpus/AwsSample.php', 'reason' => $reason]]);

        try {
            $expectedLine = sprintf('Suppressed findings: 1 via sensitiveExclusions[0] %s: 1 (%s)', self::AWS_RULE, $reason);
            $analyse      = $this->analyseProcess($project, 'text')->getOutput();
            $summary      = $this->summaryProcess($project)->getOutput();

            self::assertSame($expectedLine, $this->suppressionLine($analyse));
            self::assertSame($expectedLine, $this->suppressionLine($summary));
            // The digest is only trustworthy if the exclusion moved its totals too, not just its wording.
            self::assertSame($this->findingsLine($analyse), $this->findingsLine($summary));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Runs `summary` over a prepared project as text and asserts the digest actually saw the corpus -
     * without that check, a digest of an empty scan would satisfy every assertion by reporting nothing.
     *
     * @param string $project - Project root to scan.
     *
     * @return Process - the finished process, whose stdout carries the digest.
     */
    private function summaryProcess(string $project): Process
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'summary',
                                   '--format',
                                   'text',
                                   // Temp roots sit under an ignored directory, so ask for them explicitly.
                                   '--include-ignored',
                               ], $project);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('corpus/', $process->getOutput());

        return $process;
    }

    /**
     * Pulls the one suppression audit line out of a text surface, so two surfaces can be compared on
     * the exact string a reader sees rather than on a substring either might merely contain.
     *
     * @param string $output - Rendered text output from `analyse` or `summary`.
     *
     * @return string - the single suppression line the surface printed.
     */
    private function suppressionLine(string $output): string
    {
        return $this->soleLineStartingWith($output, 'Suppressed findings: ');
    }

    /**
     * Pulls the canonical finding-count line out of a text surface, which is where a summary that
     * declined to filter would disagree with the report it claims to digest.
     *
     * @param string $output - Rendered text output from `analyse` or `summary`.
     *
     * @return string - the single canonical `Findings:` line the surface printed.
     */
    private function findingsLine(string $output): string
    {
        return $this->soleLineStartingWith($output, 'Findings: ');
    }

    /**
     * Finds the one output line beginning with a prefix, failing when a surface printed none or
     * several - either would make a comparison between two surfaces meaningless.
     *
     * @param string $output - Rendered text output to search.
     * @param string $prefix - Line prefix identifying the wanted line.
     *
     * @return string - the single matching line, trailing carriage return removed.
     */
    private function soleLineStartingWith(string $output, string $prefix): string
    {
        $matches = [];

        // Scan every rendered line rather than substring-matching, so an unexpected second copy is caught.
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, $prefix)) {
                $matches[] = rtrim($line, "\r");
            }
        }

        self::assertCount(1, $matches, sprintf('Expected exactly one line starting with "%s".', $prefix));

        return $matches[0];
    }

    /**
     * Builds an isolated project holding the synthetic corpus and a config declaring the supplied
     * exclusions, narrowed to the sensitive-data pillar so unrelated rules cannot blur the result.
     *
     * @param list<array<string, string>> $entries - Exclusion entries to declare; an empty list writes no block at all.
     *
     * @return string - absolute path to the project root the caller must remove.
     */
    private function createCorpusProject(array $entries): string
    {
        $project = $this->tempDir();
        self::assertTrue(mkdir($project . '/corpus', 0777, true));

        // Copy the corpus verbatim so every project scans byte-identical input.
        foreach (self::CORPUS_FILES as $fixture) {
            $contents = file_get_contents(self::PROJECT_ROOT . '/tests/Fixtures/SensitiveExclusions/' . $fixture);
            self::assertIsString($contents);
            file_put_contents($project . '/corpus/' . $fixture, $contents);
        }

        file_put_contents($project . '/.gruff-php.yaml', $this->configYaml($entries));

        return $project;
    }

    /**
     * Renders the project config: the required schema version, a sensitive-data-only rule selection,
     * and the exclusion entries under test.
     *
     * @param list<array<string, string>> $entries - Exclusion entries to declare; an empty list writes no block at all.
     *
     * @return string - the YAML config contents to write at the project root.
     */
    private function configYaml(array $entries): string
    {
        $yaml = "schemaVersion: gruff-php.config.v0.1\nselection:\n    pillars:\n        - sensitive-data\n";

        // No entries means the baseline run, which must see no `sensitiveExclusions` block at all.
        if ($entries === []) {
            return $yaml;
        }

        $yaml .= "sensitiveExclusions:\n";

        // Render each entry by hand so the file reads exactly as a user would write it.
        foreach ($entries as $exclusionEntry) {
            $yaml .= sprintf("    - rule: %s\n      path: %s\n", $exclusionEntry['rule'], $exclusionEntry['path']);
            $yaml .= isset($exclusionEntry['symbol']) ? sprintf("      symbol: %s\n", $exclusionEntry['symbol']) : '';
            $yaml .= sprintf("      reason: %s\n", $exclusionEntry['reason']);
        }

        return $yaml;
    }

    /**
     * Runs `analyse` over a prepared project and returns the decoded machine report.
     *
     * @param string $project - Project root to scan; removed by the caller.
     *
     * @return array<string, mixed> - the decoded report for that run.
     * @throws JsonException
     */
    private function analyse(string $project): array
    {
        $report = $this->decodeJsonOutput($this->analyseProcess($project, 'json'));
        $this->removeDir($project);

        return $report;
    }

    /**
     * Runs `analyse` over a prepared project in the requested format and asserts the scan actually
     * saw files - without that check, two runs that each scanned nothing compare equal.
     *
     * @param string $project - Project root to scan.
     * @param string $format - Output format requested from the CLI.
     *
     * @return Process - the finished process, whose stdout carries the report.
     */
    private function analyseProcess(string $project, string $format): Process
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   '--format',
                                   $format,
                                   '--fail-on',
                                   'none',
                                   '--no-baseline',
                                   '--no-cache',
                                   // Temp roots sit under an ignored directory, so ask for them explicitly.
                                   '--include-ignored',
                               ], $project);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('corpus/', $process->getOutput());

        return $process;
    }

    /**
     * Lists the (rule, file) scope of every finding a report published, in report order.
     *
     * @param array<string, mixed> $report - Decoded machine report.
     *
     * @return list<array{string, string}> - one rule-and-file pair per reported finding.
     */
    private function findingScopes(array $report): array
    {
        $findings = $report['findings'];
        self::assertIsArray($findings);

        $scopes = [];

        // Reduce each finding to the pair an exclusion is allowed to match on.
        foreach ($findings as $finding) {
            self::assertIsArray($finding);
            self::assertIsString($finding['ruleId']);
            self::assertIsString($finding['file']);
            $scopes[] = [$finding['ruleId'], $finding['file']];
        }

        return $scopes;
    }

    /**
     * Names the findings the configured run removed relative to the baseline run, which is what a
     * sibling suppression would show up in.
     *
     * @param array<string, mixed> $baseline - Report from the same corpus with no exclusions configured.
     * @param array<string, mixed> $configured - Report from the same corpus under the case configuration.
     *
     * @return list<array{string, string}> - rule-and-file pairs present in the baseline but absent from the configured run.
     */
    private function removedFindings(array $baseline, array $configured): array
    {
        $survivors = $this->findingScopes($configured);
        $removed   = [];

        // Anything the baseline reported and the configured run did not was removed by the configuration.
        foreach ($this->findingScopes($baseline) as $scope) {
            if (!in_array($scope, $survivors, true)) {
                $removed[] = $scope;
            }
        }

        return $removed;
    }

    /**
     * Reads one audit row's suppressed count from the report.
     *
     * @param array<string, mixed> $report - Decoded machine report.
     * @param int                  $index  - Entry index whose count is read.
     *
     * @return int - findings that entry removed on this run.
     */
    private function suppressedCount(array $report, int $index): int
    {
        $suppressions = $report['suppressions'];
        self::assertIsArray($suppressions);
        $auditRow = $suppressions[$index];
        self::assertIsArray($auditRow);
        self::assertSame($index, $auditRow['index']);
        self::assertIsInt($auditRow['suppressed']);

        return $auditRow['suppressed'];
    }
}
