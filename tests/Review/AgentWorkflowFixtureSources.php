<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

/**
 * Provides PHP source strings for branch-review CLI fixtures.
 */
final class AgentWorkflowFixtureSources
{
    /**
     * Return source code for the base review fixture.
     *
     * @return string
     */
    public static function baseExampleSource(): string
    {
        return <<<'PHP'
<?php

/**
 * Covers Example behavior.
 */
final class Example
{
    public function calculate(string $left, string $right): string
    {
        return $left . $right;
    }
}
PHP;
    }

    /**
     * Return source code for the changed review fixture.
     *
     * @return string
     */
    public static function changedExampleSource(): string
    {
        return <<<'PHP'
<?php



/**
 * Covers Example behavior.
 */
final class Example
{
    public function calculate(string $left, string $right): string
    {
        return $left . $right;
    }

    public function newRisk(string $left, string $right): string
    {
        return $left . $right;
    }
}
PHP;
    }

    /**
     * Return source code for the removed-base review fixture.
     *
     * @return string
     */
    public static function removedBaseExampleSource(): string
    {
        return <<<'PHP'
<?php

/**
 * Covers Example behavior.
 */
final class Example
{
    public function calculate(string $left, string $right): string
    {
        return $left . $right;
    }

    public function oldRisk(string $left, string $right): string
    {
        return $left . $right;
    }
}
PHP;
    }

    /**
     * Return source code for an added risky review fixture.
     *
     * @return string
     */
    public static function addedRiskSource(): string
    {
        return <<<'PHP'
<?php

/**
 * Covers NewRisk behavior.
 */
final class NewRisk
{
    public function decode(string $payload): mixed
    {
        return unserialize($payload);
    }
}
PHP;
    }

    /**
     * Return source code for a project-rule interface review fixture.
     *
     * @return string
     */
    public static function bookingGatewayInterfaceSource(): string
    {
        return <<<'PHP'
<?php

namespace App\Contracts;

interface BookingGatewayInterface
{
    public function issueOtp(string $phoneNumber): string;
}
PHP;
    }

    /**
     * Return changed source code for a project-rule interface review fixture.
     *
     * @return string
     */
    public static function changedBookingGatewayInterfaceSource(): string
    {
        return <<<'PHP'
<?php

namespace App\Contracts;

// Branch edit keeps this interface in changed-only scope.
interface BookingGatewayInterface
{
    public function issueOtp(string $phoneNumber): string;
}
PHP;
    }

    /**
     * Return source code for the unchanged implementor side of a project-rule review fixture.
     *
     * @return string
     */
    public static function bookingOtpGatewaySource(): string
    {
        return <<<'PHP'
<?php

namespace App\Infrastructure;

use App\Contracts\BookingGatewayInterface;

final class BookingOtpGateway implements BookingGatewayInterface
{
    public function issueOtp(string $phoneNumber): string
    {
        return $phoneNumber;
    }
}
PHP;
    }
}
