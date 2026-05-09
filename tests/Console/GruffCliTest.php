<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GruffCliTest extends TestCase
{
    public function testVersionCommandRunsThroughBinary(): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../../bin/gruff', '--version']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff', $process->getOutput());
        self::assertStringContainsString('0.1.0-dev', $process->getOutput());
    }

    public function testAnalyseCommandRunsAsNoOp(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff 0.1.0-dev', $process->getOutput());
        self::assertStringContainsString('Discovered 2 PHP file(s).', $process->getOutput());
        self::assertStringContainsString('OK tests/Fixtures/M02/mixed/alpha.php', $process->getOutput());
        self::assertStringContainsString('OK tests/Fixtures/M02/mixed/nested/beta.php', $process->getOutput());
        self::assertStringNotContainsString('ignored.php', $process->getOutput());
    }

    public function testAnalyseCommandReportsSyntaxErrorsWithoutAborting(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed/alpha.php',
            'tests/Fixtures/M02/syntax-error/broken.php',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('OK tests/Fixtures/M02/mixed/alpha.php', $process->getOutput());
        self::assertStringContainsString('PARSE-ERROR tests/Fixtures/M02/syntax-error/broken.php:', $process->getOutput());
        self::assertStringContainsString('Completed with 1 parse error(s) and 0 missing path(s).', $process->getOutput());
    }
}
