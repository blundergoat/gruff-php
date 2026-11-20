<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers analyse compatibility with unknown and retired rule IDs in configuration.
 * Checks stderr warnings and successful exits for ignored configuration entries.
 * Execution filters use a separate contract: unknown IDs produce usage-error reports.
 */
final class AnalyseCliUnknownRuleIdTest extends CliTestCase
{
    /**
     * Verify analyse warns on stderr and still runs when config names an unknown rule id.
     *
     * @return void - Asserts exit 0, the unknown-rule warning on stderr, and no config-error marker on stdout.
     */
    public function testAnalyseCommandWarnsAndRunsWithUnknownRuleIdConfig(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   '--config',
                                   'tests/Fixtures/Config/unknown-rule.yaml',
                                   '--no-baseline',
                                   '--fail-on',
                                   'none',
                                   'tests/Fixtures/Source/mixed/alpha.php',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString(
            'gruff-php: ignoring unknown rule id "size.nope" in config',
            $process->getErrorOutput(),
        );
        self::assertStringNotContainsString('[CONFIG-ERROR]', $process->getOutput());
    }

    /**
     * Checks compatibility with the four retired project-rule blocks carried by the fixture.
     *
     * @return void - Asserts exit 0, a stderr warning for each listed retired rule, and no config-error marker on stdout.
     */
    public function testAnalyseCommandWarnsAndRunsWithRetiredProjectRuleBlocks(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   '--config',
                                   'tests/Fixtures/Config/retired-project-rules.yaml',
                                   '--no-baseline',
                                   '--fail-on',
                                   'none',
                                   'tests/Fixtures/Source/mixed/alpha.php',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        foreach ([
                     'dead-code.unused-internal-class',
                     'dead-code.unused-internal-constant',
                     'dead-code.unused-internal-function',
                     'design.single-implementor-interface',
                 ] as $retiredRuleId) {
            self::assertStringContainsString(
                sprintf('gruff-php: ignoring unknown rule id "%s" in config', $retiredRuleId),
                $process->getErrorOutput(),
                sprintf('Expected a stderr warning for retired rule id "%s".', $retiredRuleId),
            );
        }

        self::assertStringNotContainsString('[CONFIG-ERROR]', $process->getOutput());
    }

    /**
     * Verify unknown --include-rule values are rejected instead of narrowing the run to zero rules.
     *
     * @return void - Asserts exit 2 and the usage-error message naming the unknown include-rule ID.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
     */
    public function testAnalyseCommandRejectsUnknownIncludeRuleFilter(): void
    {
        $this->assertUnknownExecutionRuleFilter('--include-rule');
    }

    /**
     * Verify unknown --exclude-rule values are rejected instead of pretending to exclude a rule.
     *
     * @return void - Asserts exit 2 and the usage-error message naming the unknown exclude-rule ID.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
     */
    public function testAnalyseCommandRejectsUnknownExcludeRuleFilter(): void
    {
        $this->assertUnknownExecutionRuleFilter('--exclude-rule');
    }

    /**
     * Assert that an unknown execution-level rule filter returns a usage diagnostic.
     *
     * @param string $option - CLI filter option to exercise.
     *
     * @return void - Asserts exit 2 and the first diagnostic's usage-error type and option-specific message.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
     */
    private function assertUnknownExecutionRuleFilter(string $option): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--no-config',
                                   '--no-baseline',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                                   $option,
                                   'docs.missing-public-phpdox',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        $report      = $this->decodeJsonOutput($process);
        $diagnostics = $report['diagnostics'] ?? null;
        self::assertIsArray($diagnostics);
        $firstDiagnostic = $diagnostics[0] ?? null;
        self::assertIsArray($firstDiagnostic);
        self::assertSame('usage-error', $firstDiagnostic['type'] ?? null);
        self::assertSame(sprintf('Unknown rule id "docs.missing-public-phpdox" for %s.', $option), $firstDiagnostic['message'] ?? null);
    }
}
