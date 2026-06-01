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
     * @return string - PHP source for the clean Example class used as the "before" side of a branch-review diff
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
     * @return string - PHP source for the Example class with an added newRisk() method, landing in changed-only diff scope
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
     * @return string - PHP source for the Example class carrying oldRisk(), present only on the base side so it reads as a removed finding
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
     * @return string - PHP source for the NewRisk class whose unserialize() of caller input trips a real security rule
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
     * @return string - PHP source for the BookingGatewayInterface with a single implementor, the base side of the single-implementor scenario
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
     * @return string - PHP source for the same BookingGatewayInterface with a trivial edit, pulling it into changed-only scope without altering its
     *                contract
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
     * @return string - PHP source for the unchanged BookingOtpGateway, the lone implementor that keeps the interface paired one-to-one
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

    /**
     * Return composer metadata for project-wide dead-code review fixtures.
     *
     * @return string - composer JSON declaring App\ as project-owned PSR-4 source
     */
    public static function projectDeadCodeComposerSource(): string
    {
        // Project-wide dead-code rules derive ownership from composer PSR-4 prefixes.
        return <<<'JSON'
{"autoload":{"psr-4":{"App\\":"src/"}}}
JSON;
    }

    /**
     * Return a project-owned class declaration used by an unchanged context file.
     *
     * @return string - PHP source for a referenced internal class
     */
    public static function referencedInternalClassSource(): string
    {
        // Declaration side for the changed-only full-context project-dead-code review scenario.
        return <<<'PHP'
<?php

namespace App;

final class UsedOnlyFromContext
{
}
PHP;
    }

    /**
     * Return the changed declaration source for a referenced internal class.
     *
     * @return string - PHP source with a trivial edit that keeps the class referenced by an unchanged file
     */
    public static function changedReferencedInternalClassSource(): string
    {
        // Trivial branch edit pulls the declaration into changed-only scope without making it dead.
        return <<<'PHP'
<?php

namespace App;

// Branch edit keeps this declaration in changed-only scope.
final class UsedOnlyFromContext
{
}
PHP;
    }

    /**
     * Return an unchanged reference to the project-owned class fixture.
     *
     * @return string - PHP source that references UsedOnlyFromContext from an unchanged file
     */
    public static function internalClassReferenceSource(): string
    {
        // Unchanged context that must still count as a reference during changed-only review.
        return <<<'PHP'
<?php

namespace App;

final class ContextCaller
{
    public function make(): UsedOnlyFromContext
    {
        return new UsedOnlyFromContext();
    }
}
PHP;
    }

    /**
     * Return an added dead internal class fixture.
     *
     * @return string - PHP source for a new project-owned class with no references
     */
    public static function addedDeadInternalClassSource(): string
    {
        // A new project-owned class with no supported references should appear after changed-only filtering.
        return <<<'PHP'
<?php

namespace App;

final class AddedDeadInternal
{
}
PHP;
    }
}
