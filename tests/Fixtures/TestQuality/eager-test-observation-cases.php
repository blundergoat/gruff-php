<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\TestQuality;

use PHPUnit\Framework\TestCase;

final class ProcessOutputQualityTest extends TestCase
{
    public function testCommandOutputIsCheckedAfterSingleRun(): void
    {
        $process = new ProcessFixture();
        $process->setTimeout(120);
        $process->run();
        $expected = file_get_contents(__DIR__ . '/expected-output.txt');

        self::assertIsString($expected);
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('ok', $process->getOutput());
        self::assertStringNotContainsString('error', $process->getOutput());
    }

    public function testSnapshotCleanupIsTeardown(): void
    {
        $snapshot = new SnapshotFixture();
        $root = null;

        try {
            $root = $snapshot->create();

            self::assertIsString($root);
            self::assertStringContainsString('snapshot', $root);
            self::assertNotSame('', $root);
        } finally {
            if ($root !== null) {
                $snapshot->remove($root);
            }
        }
    }
}

final class MultipleBehaviorQualityTest extends TestCase
{
    public function testProcessesOrderAndSendsReceipt(): void
    {
        $service = new OrderServiceFixture();
        $order = $service->processOrder();
        $receipt = $service->sendReceipt();

        self::assertSame('paid', $order->status());
        self::assertSame('sent', $receipt->status());
        self::assertTrue($service->auditTrailWritten());
    }
}
