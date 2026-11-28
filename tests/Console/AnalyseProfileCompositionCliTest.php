<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * Covers acceptance and rejection of profile and rule-filter combinations.
 * The security-profile rejection case checks the usage-error explanation.
 * Accepted cases are checked for exit 0 with --fail-on none.
 */
final class AnalyseProfileCompositionCliTest extends CliTestCase
{
    /**
     * Checks that the security profile rejects an included documentation rule.
     *
     * @return void - Asserts exit 2 and the usage-error explanation for the incompatible rule.
     */
    public function testSecurityProfileRejectsNonSecurityIncludeRule(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--profile',
                                   'security',
                                   '--include-rule',
                                   'docs.missing-phpdoc',
                                   '--no-config',
                                   '--no-baseline',
                                   '--fail-on',
                                   'none',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[USAGE-ERROR]', $process->getOutput());
        self::assertStringContainsString('selects a documentation rule', $process->getOutput());
        self::assertStringContainsString('only security and sensitive-data rules', $process->getOutput());
    }

    /**
     * Supplies accepted profile and rule-filter combinations for the CLI test.
     *
     * @return array<string, array{list<string>}> - Argument lists for the acceptance test, keyed by case label.
     */
    public static function coherentCompositionCases(): array
    {
        return [
            'security profile with security include'  => [['--profile', 'security', '--include-rule', 'security.dangerous-function-call']],
            'security profile with exclude narrowing' => [['--profile', 'security', '--exclude-rule', 'security.header-injection']],
            'default profile with docs include'       => [['--include-rule', 'docs.missing-phpdoc']],
        ];
    }

    /**
     * Verify allowed profile and rule-filter compositions keep running.
     *
     * @param list<string> $compositionArguments - Profile and rule-filter arguments under test.
     *
     * @return void - Asserts exit 0 for each supplied profile and rule-filter combination.
     */
    #[DataProvider('coherentCompositionCases')]
    public function testCoherentProfileCompositionsAreAccepted(array $compositionArguments): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   ...$compositionArguments,
                                   '--no-config',
                                   '--no-baseline',
                                   '--fail-on',
                                   'none',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
