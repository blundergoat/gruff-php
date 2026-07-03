<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * Covers profile/include-rule composition: coherent combinations run, incoherent ones fail fast.
 */
final class AnalyseProfileCompositionCliTest extends CliTestCase
{
    /**
     * Verify the security profile rejects include rules its composite score never counts.
     *
     * @return void
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
                                   'docs.missing-public-phpdoc',
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
     * Provide profile/include compositions whose findings and score stay coherent.
     *
     * @return array<string, array{list<string>}> - rows of extra analyse arguments that must run cleanly
     */
    public static function coherentCompositionCases(): array
    {
        return [
            'security profile with security include'  => [['--profile', 'security', '--include-rule', 'security.dangerous-function-call']],
            'security profile with exclude narrowing' => [['--profile', 'security', '--exclude-rule', 'security.weak-crypto']],
            'default profile with docs include'       => [['--include-rule', 'docs.missing-public-phpdoc']],
        ];
    }

    /**
     * Verify allowed profile/include compositions keep running.
     *
     * @param list<string> $compositionArguments - Profile and rule-filter arguments under test.
     *
     * @return void
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
