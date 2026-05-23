<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\GlobalStateMutation;

use PHPUnit\Framework\TestCase;

// Positive: writes to superglobals and global functions without any cleanup.
final class GlobalStateMutationLeakyTest extends TestCase
{
    public function testWritesSuperglobal(): void
    {
        $_GET['user'] = 'leaked';

        self::assertSame('leaked', $_GET['user']);
    }

    public function testCallsPutenv(): void
    {
        putenv('APP_ENV=test');

        self::assertSame('test', getenv('APP_ENV'));
    }

    public function testCallsIniSet(): void
    {
        ini_set('memory_limit', '512M');

        self::assertSame('512M', ini_get('memory_limit'));
    }
}

// Negative: same writes, but the class declares a tearDown to reset state.
final class GlobalStateMutationCleanedUpTest extends TestCase
{
    private mixed $previousEnv;
    private mixed $previousLimit;

    public function testWritesSuperglobalCleanedUp(): void
    {
        $_GET['user'] = 'leaked';

        self::assertSame('leaked', $_GET['user']);
    }

    public function testCallsPutenvCleanedUp(): void
    {
        $this->previousEnv = getenv('APP_ENV');

        putenv('APP_ENV=test');

        self::assertSame('test', getenv('APP_ENV'));
    }

    protected function tearDown(): void
    {
        unset($_GET['user']);
        if (is_string($this->previousEnv)) {
            putenv('APP_ENV=' . $this->previousEnv);
        }
    }
}

// Edge: reading from a superglobal is not a write and must not flag.
final class GlobalStateMutationReadOnlyTest extends TestCase
{
    public function testReadsSuperglobalWithoutWriting(): void
    {
        $value = $_SERVER['HTTP_HOST'] ?? 'localhost';

        self::assertNotSame('', $value);
    }
}

abstract class GlobalStateMutationBaseCleanupTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['GLOBAL_STATE_MUTATION']);
    }
}

// Edge: cleanup inherited from a same-file parent class satisfies the rule.
final class GlobalStateMutationInheritedCleanupTest extends GlobalStateMutationBaseCleanupTest
{
    public function testWritesWithInheritedCleanup(): void
    {
        $_SERVER['GLOBAL_STATE_MUTATION'] = 'changed';

        self::assertSame('changed', $_SERVER['GLOBAL_STATE_MUTATION']);
    }
}
