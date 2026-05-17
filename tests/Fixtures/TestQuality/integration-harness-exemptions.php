<?php

declare(strict_types=1);

namespace Fixtures\TestQuality;

use PHPUnit\Framework\TestCase;

final class IntegrationHarnessExemptionsTest extends TestCase
{
    public function testMysteryGuestReportsOnlyHiddenFixture(): void
    {
        $ownedPath = '/tmp/gruff-owned.json';
        file_put_contents($ownedPath, '{}');
        $ownedPayload = file_get_contents($ownedPath);

        $outputPath = '/tmp/gruff-output.json';
        (new OutputWriter())->write($outputPath);
        $outputPayload = file_get_contents($outputPath);

        $root = '/tmp/gruff-root';
        (new OutputWriter())->writeRoot($root);
        $rootPayload = file_get_contents($root . '/result.json');

        $hiddenPayload = file_get_contents('/tmp/hidden-fixture.json');

        self::assertSame('{}', $ownedPayload);
        self::assertSame('{}', $outputPayload);
        self::assertSame('{}', $rootPayload);
        self::assertSame('{}', $hiddenPayload);
    }

    public function testCommandTesterExecuteIsIntegrationHarness(): void
    {
        $tester = new CommandTester();
        $input = ['command' => 'analyse'];
        $environment = ['APP_ENV' => 'test'];
        $workingDirectory = '/tmp/project';
        $description = 'runs command';
        $expectedStatus = 0;
        $actualStatus = 0;

        $tester->execute($input);

        self::assertSame($expectedStatus, $actualStatus);
        self::assertSame('test', $environment['APP_ENV']);
        self::assertSame('/tmp/project', $workingDirectory);
        self::assertSame('runs command', $description);
    }

    public function testProcessRunIsIntegrationHarness(): void
    {
        $process = new Process();
        $command = ['php', 'bin/gruff-php'];
        $timeout = 30;
        $workingDirectory = '/tmp/project';
        $description = 'runs process';
        $expectedStatus = 0;
        $actualStatus = 0;

        $process->run();

        self::assertSame($expectedStatus, $actualStatus);
        self::assertSame(['php', 'bin/gruff-php'], $command);
        self::assertSame(30, $timeout);
        self::assertSame('/tmp/project', $workingDirectory);
        self::assertSame('runs process', $description);
    }

    public function testApplicationTesterRunIsIntegrationHarness(): void
    {
        $input = ['command' => 'dashboard'];
        $host = '127.0.0.1';
        $port = 18080;
        $project = '/tmp/project';
        $expectedStatus = 0;
        $actualStatus = 0;

        (new ApplicationTester())->run();

        self::assertSame($expectedStatus, $actualStatus);
        self::assertSame(['command' => 'dashboard'], $input);
        self::assertSame('127.0.0.1', $host);
        self::assertSame(18080, $port);
        self::assertSame('/tmp/project', $project);
    }

    public function testDomainRunStillLooksLongerThanSut(): void
    {
        $service = new DomainService();
        $first = 'alpha';
        $second = 'beta';
        $third = 'gamma';
        $fourth = 'delta';
        $fifth = 'epsilon';
        $sixth = 'zeta';
        $seventh = 'eta';
        $eighth = 'theta';

        $service->run();

        self::assertSame('alpha', $first);
        self::assertSame('beta', $second);
        self::assertSame('gamma', $third);
        self::assertSame('delta', $fourth);
        self::assertSame('epsilon', $fifth);
        self::assertSame('zeta', $sixth);
        self::assertSame('eta', $seventh);
        self::assertSame('theta', $eighth);
    }
}

final class OutputWriter
{
    public function write(string $path): void
    {
    }

    public function writeRoot(string $root): void
    {
    }
}

final class CommandTester
{
    public function execute(array $input): void
    {
    }
}

final class Process
{
    public function run(): void
    {
    }
}

final class ApplicationTester
{
    public function run(): void
    {
    }
}

final class DomainService
{
    public function run(): void
    {
    }
}
