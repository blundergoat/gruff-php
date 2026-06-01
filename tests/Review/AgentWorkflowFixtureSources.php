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
        // Clean single-method class: the "before" side a branch-review diff compares against.
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
        // Adds newRisk() on top of the base so the diff carries a method that lands in changed-only scope.
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
        // Carries oldRisk() present only on the base side, so comparison must report it as a removed finding.
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
        // unserialize() of caller input trips a real security rule, so the added method yields a genuine finding.
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
        // Interface with exactly one implementor: the base side of the single-implementor project-rule scenario.
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
        // Same interface with a trivial edit, pulling it into changed-only scope without altering its contract.
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
        // Unchanged implementor that keeps the interface at one implementor, so the rule sees a complete pair.
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
